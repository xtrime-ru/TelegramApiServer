<?php


namespace TelegramApiServer\MadelineProtoExtensions;


use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use danog\MadelineProto;
use danog\MadelineProto\StrTools;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\Exceptions\MediaTooBig;
use TelegramApiServer\Exceptions\NoMediaException;
use function Amp\delay;

class ApiExtensions
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

    /**
     * Получает последние сообщения из указанных каналов
     *
     * @param array $data
     * <pre>
     * [
     *     'peer' => '',
     *     'offset_id' => 0, // (optional)
     *     'offset_date' => 0, // (optional)
     *     'add_offset' => 0, // (optional)
     *     'limit' => 0, // (optional)
     *     'max_id' => 0, // (optional)
     *     'min_id' => 0, // (optional)
     *     'hash' => 0, // (optional)
     * ]
     * </pre>
     *
     */
    public function getHistory(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => 0,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => [],
            ],
            $data
        );

        return $this->madelineProto->messages->getHistory($data);
    }

    public function getHistoryHtml(array $data): array
    {
        $response = $this->getHistory($data);
        if (!empty($response['messages'])) {
            foreach ($response['messages'] as &$message) {
                $message['message'] = $this->formatMessage($message['message'] ?? null, $message['entities'] ?? []);
            }
            unset($message);
        }

        return $response;
    }

    /**
     * Проверяет есть ли подходящие медиа у сообщения
     *
     * @param array $message
     * @param bool $allowWebPage
     *
     * @return bool
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
                if (in_array($entity['_'], ['messageEntityTextUrl', 'messageEntityMention', 'messageEntityUrl'])) {
                    $textFormated = sprintf($template, strip_tags($entity['url'] ?? $text), $text);
                } else {
                    $textFormated = sprintf($template, $text);
                }

                $message = static::substringReplace($message, $textFormated, $entity['offset'], $entity['length']);

                //Увеличим оффсеты всех следующих entity
                foreach ($entities as $nextKey => &$nextEntity) {
                    if ($nextKey <= $key) {
                        continue;
                    }
                    if ($nextEntity['offset'] < ($entity['offset'] + $entity['length'])) {
                        $nextEntity['offset'] += StrTools::mbStrlen(
                            preg_replace('~(\>).*<\/.*$~', '$1', $textFormated)
                        );
                    } else {
                        $nextEntity['offset'] += StrTools::mbStrlen($textFormated) - StrTools::mbStrlen($text);
                    }
                }
                unset($nextEntity);
            }
        }
        unset($entity);
        $message = nl2br($message);
        return $message;
    }

    private static function substringReplace(string $original, string $replacement, int $position, int $length): string
    {
        $startString = StrTools::mbSubstr($original, 0, $position);
        $endString = StrTools::mbSubstr($original, $position + $length, StrTools::mbStrlen($original));
        return $startString . $replacement . $endString;
    }

    /**
     * Пересылает сообщения без ссылки на оригинал
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
        $data = array_merge(
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
        if (!$response || !is_array($response) || !array_key_exists('messages', $response)) {
            return $result;
        }

        foreach ($response['messages'] as $key => $message) {
            $messageData = [
                'message' => $message['message'] ?? '',
                'peer' => $data['to_peer'],
                'entities' => $message['entities'] ?? [],
            ];
            if (static::hasMedia($message, false)) {
                $messageData['media'] = $message; //MadelineProto сама достанет все media из сообщения.
                $result[] = $this->sendMedia($messageData);
            } else {
                $result[] = $this->sendMessage($messageData);
            }
            if ($key > 0) {
                delay(random_int(300, 2000) / 1000);
            }
        }

        return $result;
    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'peer' => '',
     *  'message' => '',      // Текст сообщения,
     *  'media' => [],      // MessageMedia, Update, Message or InputMedia
     *  'reply_to_msg_id' => 0,       // (optional)
     *  'parse_mode' => 'HTML',  // (optional)
     * ]
     * </pre>
     *
     */
    public function sendMedia(array $data): array
    {
        $data = array_merge(
            [
                'peer' => '',
                'message' => '',
                'media' => [],
                'reply_to_msg_id' => 0,
                'parse_mode' => 'HTML',
            ],
            $data
        );

        if (!empty($this->file)) {
            $data = array_merge(
                $data,
                $this->uploadMediaForm()
            );
        }

        return $this->madelineProto->messages->sendMedia(...$data);
    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'peer' => '',
     *  'message' => '',      // Текст сообщения
     *  'reply_to_msg_id' => 0,       // (optional)
     *  'parse_mode' => 'HTML',  // (optional)
     * ]
     * </pre>
     *
     */
    public function sendMessage(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'message' => '',
                'reply_to_msg_id' => 0,
                'parse_mode' => 'HTML',
            ],
            $data
        );

        return $this->madelineProto->messages->sendMessage($data);
    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'folder_id' => 0, // Id папки (optional)
     *  'q'  => '',  //Поисковый запрос
     *  'offset_rate' => 0,   // (optional)
     *  'offset_peer' => null, // (optional)
     *  'offset_id' => 0,   // (optional)
     *  'limit' => 10,  // (optional)
     * ]
     * </pre>
     *
     */
    public function searchGlobal(array $data): array
    {
        $data = array_merge(
            [

                'q' => '',
                'offset_rate' => 0,
                'offset_id' => 0,
                'limit' => 10,
            ],
            $data
        );
        return $this->madelineProto->messages->searchGlobal(...$data);
    }

    /**
     * Загружает медиафайл из указанного сообщения в поток
     *
     * @param array $data
     *
     */
    public function getMedia(array $data): Response
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
                'message' => [],
                'size_limit' => 0,
            ],
            $data
        );

        $message = $data['message'] ?: ($this->getMessages($data))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!static::hasMedia($message, true)) {
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

        if ($data['size_limit'] && $info['size'] > $data['size_limit']) {
            throw new MediaTooBig(
                "Media exceeds size limit. Size: {$info['size']} bytes; limit: {$data['size_limit']} bytes"
            );
        }

        return $this->downloadToResponse($info);
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток
     *
     */
    public function getMediaPreview(array $data): Response
    {
        $data = array_merge(
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

        if (!static::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        $media = $message['media'][array_key_last($message['media'])];
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

    /**
     * @param array $data
     *
     */
    public function getMessages(array $data): array
    {
        $peerInfo = $this->madelineProto->getInfo($data['peer']);
        if (in_array($peerInfo['type'], ['channel', 'supergroup'])) {
            $response = $this->madelineProto->channels->getMessages(
                [
                    'channel' => $data['peer'],
                    'id' => (array)$data['id'],
                ]
            );
        } else {
            $response = $this->madelineProto->messages->getMessages(['id' => (array)$data['id']]);
        }

        return $response;
    }

    /**
     * Download to Amp HTTP response.
     *
     * @param array $info
     *      Any downloadable array: message, media etc...
     *
     * @return Response
     */
    public function downloadToResponse(array $info): Response
    {
        return $this->madelineProto->downloadToResponse($info, $this->request);
    }

    /**
     * Адаптер для стандартного метода
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
        $inputFile['id'] = unpack('P', $inputFile['id'])['1'];
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
    }

}