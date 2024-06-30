<?php declare(strict_types=1);

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use danog\MadelineProto;
use danog\MadelineProto\StrTools;
use http\Exception\InvalidArgumentException;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\Exceptions\NoMediaException;
use function Amp\delay;

final class ApiExtensions
{

    private MadelineProto\Api $madelineProto;
    private Request $request;
    private ?StreamedField $file;

    public function __construct(MadelineProto\Api $madelineProto, Request $request, ?StreamedField $file)
    {
        $this->madelineProto = $madelineProto;
        $this->request = $request;
        $this->file = $file;
    }

    public function getHistoryHtml(array $data): array
    {
        $response = $this->madelineProto->messages->getHistory(...$data);
        if (!empty($response['messages'])) {
            foreach ($response['messages'] as &$message) {
                $message['message'] = $this->formatMessage($message['message'] ?? null, $message['entities'] ?? []);
            }
            unset($message);
        }

        return $response;
    }

    /**
     * Проверяет есть ли подходящие медиа у сообщения.
     *
     *
     */
    private static function hasMedia(array $message = [], bool $allowWebPage = false): bool
    {
        $mediaType = $message['media']['_'] ?? null;
        if ($mediaType === null) {
            return false;
        }
        if (
            $mediaType === 'messageMediaWebPage' &&
            ($allowWebPage === false || empty($message['media']['webpage']['photo']))
        ) {
            return false;
        }

        return true;
    }

    public function formatMessage(?string $message = null, array $entities = []): ?string
    {
        if ($message === null) {
            return null;
        }
        $html = [
            'messageEntityItalic' => '<i>%s</i>',
            'messageEntityBold' => '<strong>%s</strong>',
            'messageEntityCode' => '<code>%s</code>',
            'messageEntityPre' => '<pre>%s</pre>',
            'messageEntityStrike' => '<strike>%s</strike>',
            'messageEntityUnderline' => '<u>%s</u>',
            'messageEntityBlockquote' => '<blockquote>%s</blockquote>',
            'messageEntityTextUrl' => '<a href="%s" target="_blank" rel="nofollow">%s</a>',
            'messageEntityMention' => '<a href="tg://resolve?domain=%s" rel="nofollow">%s</a>',
            'messageEntityUrl' => '<a href="%s" target="_blank" rel="nofollow">%s</a>',
        ];

        foreach ($entities as $key => &$entity) {
            if (isset($html[$entity['_']])) {

                $text = StrTools::mbSubstr($message, $entity['offset'], $entity['length']);

                $template = $html[$entity['_']];
                if (\in_array($entity['_'], ['messageEntityTextUrl', 'messageEntityMention', 'messageEntityUrl'])) {
                    $textFormated = \sprintf($template, \strip_tags($entity['url'] ?? $text), $text);
                } else {
                    $textFormated = \sprintf($template, $text);
                }

                $message = self::substringReplace($message, $textFormated, $entity['offset'], $entity['length']);

                //Увеличим оффсеты всех следующих entity
                foreach ($entities as $nextKey => &$nextEntity) {
                    if ($nextKey <= $key) {
                        continue;
                    }
                    if ($nextEntity['offset'] < ($entity['offset'] + $entity['length'])) {
                        $nextEntity['offset'] += StrTools::mbStrlen(
                            \preg_replace('~(\>).*<\/.*$~', '$1', $textFormated)
                        );
                    } else {
                        $nextEntity['offset'] += StrTools::mbStrlen($textFormated) - StrTools::mbStrlen($text);
                    }
                }
                unset($nextEntity);
            }
        }
        unset($entity);
        $message = \nl2br($message);
        return $message;
    }

    private static function substringReplace(string $original, string $replacement, int $position, int $length): string
    {
        $startString = StrTools::mbSubstr($original, 0, $position);
        $endString = StrTools::mbSubstr($original, $position + $length, StrTools::mbStrlen($original));
        return $startString . $replacement . $endString;
    }

    /**
     * Пересылает сообщения без ссылки на оригинал.
     *
     * @param array $data
     * <pre>
     * [
     *  'from_peer' => '',
     *  'to_peer' => '',
     *  'id' => [], //Id сообщения, или нескольких сообщений
     * ]
     * </pre>
     *
     */
    public function copyMessages(array $data)
    {
        $data = \array_merge(
            [
                'from_peer' => '',
                'to_peer' => '',
                'id' => [],
            ],
            $data
        );

        $response = $this->madelineProto->channels->getMessages(
            [
                'channel' => $data['from_peer'],
                'id' => $data['id'],
            ]
        );
        $result = [];
        if (!$response || !\is_array($response) || !\array_key_exists('messages', $response)) {
            return $result;
        }

        foreach ($response['messages'] as $key => $message) {
            $messageData = [
                'message' => $message['message'] ?? '',
                'peer' => $data['to_peer'],
                'entities' => $message['entities'] ?? [],
            ];
            if (self::hasMedia($message, false)) {
                $messageData['media'] = $message; //MadelineProto сама достанет все media из сообщения.
                $result[] = $this->madelineProto->messages->sendMedia(...$messageData);
            } else {
                $result[] = $this->madelineProto->messages->sendMessage(...$messageData);
            }
            if ($key > 0) {
                delay(\random_int(300, 2000) / 1000);
            }
        }

        return $result;
    }

    /**
     * Загружает медиафайл из указанного сообщения в поток.
     *
     *
     */
    public function getMedia(array $data): Response
    {
        $data = \array_merge(
            [
                'peer' => '',
                'id' => [0],
                'message' => [],
            ],
            $data
        );

        $message = $data['message'] ?: ($this->getMessages($data))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        if ($message['media']['_'] !== 'messageMediaWebPage') {
            $info = $this->madelineProto->getDownloadInfo($message);
        } else {
            $webpage = $message['media']['webpage'];
            if (!empty($webpage['embed_url'])) {
                return new Response(302, ['Location' => $webpage['embed_url']]);
            } elseif (!empty($webpage['document'])) {
                $info = $this->madelineProto->getDownloadInfo($webpage['document']);
            } elseif (!empty($webpage['photo'])) {
                $info = $this->madelineProto->getDownloadInfo($webpage['photo']);
            } else {
                return $this->getMediaPreview($data);
            }
        }

        return $this->downloadToResponse($info);
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток.
     *
     */
    public function getMediaPreview(array $data): Response
    {
        $data = \array_merge(
            [
                'peer' => '',
                'id' => [0],
                'message' => [],
            ],
            $data
        );

        $message = $data['message'] ?: ($this->getMessages($data))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        $media = $message['media'][\array_key_last($message['media'])];
        $thumb = null;
        switch (true) {
            case isset($media['sizes']):
                foreach ($media['sizes'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            case isset($media['thumb']['size']):
                $thumb = $media['thumb'];
                break;
            case !empty($media['thumbs']):
                foreach ($media['thumbs'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            case isset($media['photo']['sizes']):
                foreach ($media['photo']['sizes'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            default:
                throw new NoMediaException('Message has no preview');

        }
        if (null === $thumb) {
            throw new NoMediaException('Empty preview');
        }
        $info = $this->madelineProto->getDownloadInfo($thumb);

        if ($media['_'] === 'webPage') {
            $media = $media['photo'];
        }

        //Фикс для LAYER 100+
        //TODO: Удалить, когда снова станет доступна загрузка photoSize
        if (isset($info['thumb_size'])) {
            $infoFull = $this->madelineProto->getDownloadInfo($media);
            $infoFull['InputFileLocation']['thumb_size'] = $info['thumb_size'];
            $infoFull['size'] = $info['size'];
            $infoFull['mime'] = $info['mime'] ?? 'image/jpeg';
            $infoFull['name'] = 'thumb';
            $infoFull['ext'] = '.jpeg';
            $info = $infoFull;
        }

        return $this->downloadToResponse($info);
    }

    public function getMessages(array $data): array
    {
        $peerInfo = $this->madelineProto->getInfo($data['peer']);
        if (\in_array($peerInfo['type'], ['channel', 'supergroup'])) {
            $response = $this->madelineProto->channels->getMessages(
                [
                    'channel' => $data['peer'],
                    'id' => (array) $data['id'],
                ]
            );
        } else {
            $response = $this->madelineProto->messages->getMessages(['id' => (array) $data['id']]);
        }

        return $response;
    }

    /**
     * Download to Amp HTTP response.
     *
     * @param array $info
     *      Any downloadable array: message, media etc...
     *
     */
    public function downloadToResponse(array $info): Response
    {
        return $this->madelineProto->downloadToResponse($info, $this->request);
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToBrowser(array $info): Response
    {
        return $this->downloadToResponse($info);
    }

    /**
     * Upload file from POST request.
     * Response can be passed to 'media' field in messages.sendMedia.
     *
     * @throws NoMediaException
     */
    public function uploadMediaForm(): array
    {
        if (empty($this->file)) {
            throw new NoMediaException('File not found');
        }
        $inputFile = $this->madelineProto->uploadFromStream(
            $this->file,
            0,
            $this->file->getMimeType(),
            $this->file->getFilename()
        );
        $inputFile['id'] = \unpack('P', $inputFile['id'])['1'];
        return [
            'media' => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $inputFile,
                'attributes' => [
                    ['_' => 'documentAttributeFilename', 'file_name' => $this->file->getFilename()]
                ]
            ]
        ];
    }

    public function setEventHandler(): void
    {
        Client::getWrapper($this->madelineProto)->getAPI()->setEventHandler(EventHandler::class);
        Client::getWrapper($this->madelineProto)->serialize();
    }

    public function serialize(): void
    {
        Client::getWrapper($this->madelineProto)->serialize();
    }

    public function getUpdates(array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = match($key) {
                'offset', 'limit' => (int) $value,
                'timeout' => (float) $value,
                default => throw new \InvalidArgumentException("Unknown parameter: {$key}"),
            };
        }

        return $this->madelineProto->getUpdates($params);
    }

    public function setNoop(): void
    {
        $this->madelineProto->setNoop();
        Client::getWrapper($this->madelineProto)->serialize();
    }

    public function setWebhook(string $url): void
    {
        $this->madelineProto->setWebhook($url);
        Client::getWrapper($this->madelineProto)->serialize();
    }

    public function unsubscribeFromUpdates(?string $channel = null): array
    {
        $inputChannelId = null;
        if ($channel) {
            $id = (string) $this->madelineProto->getId($channel);

            $inputChannelId = (int) \str_replace(['-100', '-'], '', $id);
            if (!$inputChannelId) {
                throw new InvalidArgumentException('Invalid id');
            }
        }
        $counter = 0;
        foreach (Client::getWrapper($this->madelineProto)->getAPI()->feeders as $channelId => $_) {
            if ($channelId === 0) {
                continue;
            }
            if ($inputChannelId && $inputChannelId !== $channelId) {
                continue;
            }
            Client::getWrapper($this->madelineProto)->getAPI()->feeders[$channelId]->stop();
            Client::getWrapper($this->madelineProto)->getAPI()->updaters[$channelId]->stop();
            unset(
                Client::getWrapper($this->madelineProto)->getAPI()->feeders[$channelId],
                Client::getWrapper($this->madelineProto)->getAPI()->updaters[$channelId]
            );
            Client::getWrapper($this->madelineProto)->getAPI()->getChannelStates()->remove($channelId);
            $counter++;
        }

        return [
            'disabled_update_loops' => $counter,
            'current_update_loops' => \count(Client::getWrapper($this->madelineProto)->getAPI()->feeders),
        ];
    }

}
