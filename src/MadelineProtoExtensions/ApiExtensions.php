<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use AssertionError;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\FileCallbackInterface;
use danog\MadelineProto\StrTools;
use InvalidArgumentException;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\Exceptions\NoMediaException;
use function Amp\delay;

final class ApiExtensions
{
    public function getHistoryHtml(API $madelineProto, array|int|string|null $peer = null, int|null $offset_id = 0, int|null $offset_date = 0, int|null $add_offset = 0, int|null $limit = 0, int|null $max_id = 0, int|null $min_id = 0, array $hash = [], ?int $floodWaitLimit = null, ?string $queueId = null): array
    {
        $response = $madelineProto->messages->getHistory(
            peer: $peer,
            offset_id: $offset_id,
            offset_date: $offset_date, add_offset: $add_offset,
            limit: $limit,
            max_id: $max_id,
            min_id: $min_id,
            hash: $hash,
            floodWaitLimit: $floodWaitLimit,
            queueId: $queueId,
        );
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
     */
    public function copyMessages(API $madelineProto, string|int $from_peer, string|int $to_peer, array|int $id)
    {
        $response = $madelineProto->channels->getMessages(
            channel: $from_peer,
            id: (array)$id,
        );
        $result = [];
        if (!$response || !\is_array($response) || !\array_key_exists('messages', $response)) {
            return $result;
        }

        foreach ($response['messages'] as $key => $message) {
            $messageData = [
                'message' => $message['message'] ?? '',
                'peer' => $to_peer,
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
     */
    public function getMedia(API $madelineProto, Request $request, string|int $peer = '', array|int $id = 0, array $message = []): Response
    {
        $message = $message ?: ($this->getMessages($madelineProto, $peer, (array)$id))['messages'][0] ?? null;
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
                return $this->getMediaPreview($madelineProto, $request, $peer, $id, $message);
            }
        }

        return $madelineProto->downloadToResponse(messageMedia: $info, request: $request);
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток.
     *
     */
    public function getMediaPreview(API $madelineProto, Request $request, string|int $peer = '', array|int $id = 0, array $message = []): Response
    {
        $message = $message ?: ($this->getMessages($madelineProto, $peer, (array)$id))['messages'][0] ?? null;
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

        return $madelineProto->downloadToResponse(messageMedia: $info, request: $request);
    }

    public function getMessages(API $madelineProto, string|int $peer, array|int $id): array
    {
        $peerInfo = $madelineProto->getInfo($peer);
        if (\in_array($peerInfo['type'], ['channel', 'supergroup'])) {
            $response = $madelineProto->channels->getMessages(
                channel:$peer,
                id: $id,
            );
        } else {
            $response = $madelineProto->messages->getMessages(id: $id);
        }

        return $response;
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToResponse(API $madelineProto, FileCallbackInterface|Message|array|string $messageMedia, Request $request, ?callable $cb = null, ?int $size = null, ?string $mime = null, ?string $name = null): Response
    {
        return $madelineProto->downloadToResponse(
            messageMedia: $messageMedia,
            request: $request,
            cb: $cb,
            size: $size,
            mime: $mime,
            name: $name,
        );
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToBrowser(API $madelineProto, FileCallbackInterface|Message|array|string $messageMedia, Request $request, ?callable $cb = null, ?int $size = null, ?string $mime = null, ?string $name = null): Response
    {
        return $madelineProto->downloadToResponse(
            messageMedia: $messageMedia,
            request: $request,
            cb: $cb,
            size: $size,
            mime: $mime,
            name: $name,
        );
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
            stream: $file,
            size: 0,
            mime: $mimeType,
            fileName:  $fileName ?? '',
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
