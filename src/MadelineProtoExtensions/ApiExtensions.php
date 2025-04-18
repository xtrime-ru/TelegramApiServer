<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Response;
use AssertionError;
use danog\MadelineProto\API;
use danog\MadelineProto\StrTools;
use InvalidArgumentException;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\Exceptions\NoMediaException;
use function Amp\delay;

final class ApiExtensions
{
    public function getHistoryHtml(API $madelineProto, ...$params): array
    {
        $response = $madelineProto->messages->getHistory(...$params);
        if (!empty($response['messages'])) {
            foreach ($response['messages'] as &$message) {
                $message['message'] = StrTools::entitiesToHtml(
                    $message['message'] ?? '',
                    $message['entities'] ?? [],
                    true
                );
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
    public function copyMessages(API $madelineProto, ...$data)
    {
        $data = \array_merge(
            [
                'from_peer' => '',
                'to_peer' => '',
                'id' => [],
            ],
            $data
        );

        $response = $madelineProto->channels->getMessages(
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
                $result[] = $madelineProto->messages->sendMedia(...$messageData);
            } else {
                $result[] = $madelineProto->messages->sendMessage(...$messageData);
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
    public function getMedia(API $madelineProto, ...$data): Response
    {
        $data = \array_merge(
            [
                'peer' => '',
                'id' => [0],
                'message' => [],
            ],
            $data
        );

        $message = $data['message'] ?: ($this->getMessages($madelineProto, ...$data))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        if ($message['media']['_'] !== 'messageMediaWebPage') {
            $info = $madelineProto->getDownloadInfo($message);
        } else {
            $webpage = $message['media']['webpage'];
            if (!empty($webpage['embed_url'])) {
                return new Response(302, ['Location' => $webpage['embed_url']]);
            } elseif (!empty($webpage['document'])) {
                $info = $madelineProto->getDownloadInfo($webpage['document']);
            } elseif (!empty($webpage['photo'])) {
                $info = $madelineProto->getDownloadInfo($webpage['photo']);
            } else {
                return $this->getMediaPreview($madelineProto, ...$data);
            }
        }

        return $madelineProto->downloadToResponse(...$info);
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток.
     *
     */
    public function getMediaPreview(API $madelineProto, ...$data): Response
    {
        $data = \array_merge(
            [
                'peer' => '',
                'id' => [0],
                'message' => [],
            ],
            $data
        );

        $message = $data['message'] ?: ($this->getMessages($madelineProto, ...$data))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        $media = match ($message['media']['_']) {
            'messageMediaPhoto' => $message['media']['photo'],
            'messageMediaDocument' => $message['media']['document'],
            'messageMediaWebPage' => $message['media']['webpage'],
        };

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
        $info = $madelineProto->getDownloadInfo($thumb);

        if ($media['_'] === 'webPage') {
            $media = $media['photo'];
        }

        //Фикс для LAYER 100+
        //TODO: Удалить, когда снова станет доступна загрузка photoSize
        if (isset($info['thumb_size'])) {
            $infoFull = $madelineProto->getDownloadInfo($media);
            $infoFull['InputFileLocation']['thumb_size'] = $info['thumb_size'];
            $infoFull['size'] = $info['size'];
            $infoFull['mime'] = $info['mime'] ?? 'image/jpeg';
            $infoFull['name'] = 'thumb';
            $infoFull['ext'] = '.jpeg';
            $info = $infoFull;
        }

        return $madelineProto->downloadToResponse(...$info);
    }

    public function getMessages(API $madelineProto, ...$data): array
    {
        $peerInfo = $madelineProto->getInfo($data['peer']);
        if (\in_array($peerInfo['type'], ['channel', 'supergroup'])) {
            $response = $madelineProto->channels->getMessages(
                [
                    'channel' => $data['peer'],
                    'id' => (array)$data['id'],
                ]
            );
        } else {
            $response = $madelineProto->messages->getMessages(['id' => (array)$data['id']]);
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
    public function downloadToResponse(API $madelineProto, ...$info): Response
    {
        return $madelineProto->downloadToResponse(...$info);
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToBrowser(API $madelineProto, ...$info): Response
    {
        return $madelineProto->downloadToResponse(...$info);
    }

    /**
     * Upload file from POST request.
     * Response can be passed to 'media' field in messages.sendMedia.
     *
     * @throws NoMediaException
     * @deprecated use SendDocument
     */
    public function uploadMediaForm(API $madelineProto, ReadableStream $file, string $mimeType, ?string $fileName): array
    {
        if ($fileName === null) {
            throw new AssertionError("No file name was provided!");
        }
        $inputFile = $madelineProto->uploadFromStream(
            $file,
            0,
            $mimeType,
            $fileName ?? ''
        );
        $inputFile['id'] = \unpack('P', $inputFile['id'])['1'];
        return [
            'media' => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $inputFile,
                'attributes' => [
                    ['_' => 'documentAttributeFilename', 'file_name' => $fileName]
                ]
            ]
        ];
    }

    public function setEventHandler(API $madelineProto): void
    {
        Client::getWrapper($madelineProto)->getAPI()->setEventHandler(EventHandler::class);
        Client::getWrapper($madelineProto)->serialize();
    }

    public function serialize(API $madelineProto): void
    {
        Client::getWrapper($madelineProto)->serialize();
    }

    public function getUpdates(API $madelineProto, array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = match ($key) {
                'offset', 'limit' => (int)$value,
                'timeout' => (float)$value,
                default => throw new InvalidArgumentException("Unknown parameter: {$key}"),
            };
        }

        return $madelineProto->getUpdates($params);
    }

    public function unsubscribeFromUpdates(API $madelineProto, ?string $channel = null): array
    {
        $inputChannelId = null;
        if ($channel) {
            $id = (string)$madelineProto->getId($channel);

            $inputChannelId = (int)\str_replace(['-100', '-'], '', $id);
            if (!$inputChannelId) {
                throw new InvalidArgumentException('Invalid id');
            }
        }
        $counter = 0;
        foreach (Client::getWrapper($madelineProto)->getAPI()->feeders as $channelId => $_) {
            if ($channelId === 0) {
                continue;
            }
            if ($inputChannelId && $inputChannelId !== $channelId) {
                continue;
            }
            Client::getWrapper($madelineProto)->getAPI()->feeders[$channelId]->stop();
            Client::getWrapper($madelineProto)->getAPI()->updaters[$channelId]->stop();
            unset(
                Client::getWrapper($madelineProto)->getAPI()->feeders[$channelId],
                Client::getWrapper($madelineProto)->getAPI()->updaters[$channelId]
            );
            Client::getWrapper($madelineProto)->getAPI()->getChannelStates()->remove($channelId);
            $counter++;
        }

        return [
            'disabled_update_loops' => $counter,
            'current_update_loops' => \count(Client::getWrapper($madelineProto)->getAPI()->feeders),
        ];
    }
}
