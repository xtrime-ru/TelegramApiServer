<?php


namespace TelegramApiServer;


use danog\MadelineProto\TL\Conversion\BotAPI;
use function Amp\call;
use \danog\MadelineProto;

class ClientCustomMethods
{
    use BotAPI;

    private MadelineProto\Api $madelineProto;

    public function __construct(MadelineProto\Api $madelineProto)
    {
        $this->madelineProto = $madelineProto;
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
     * @return \Amp\Promise
     */
    public function getHistory(array $data): \Amp\Promise
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
                'hash' => 0,
            ],
            $data
        );

        return $this->madelineProto->messages->getHistory($data);
    }

    /**
     * @param array $data
     *
     * @return \Amp\Promise
     */
    public function getHistoryHtml(array $data): \Amp\Promise
    {
        return call(
            function() use ($data) {
                $response = yield $this->getHistory($data);

                foreach ($response['messages'] as &$message) {
                    $message['message'] = $this->formatMessage($message['message'] ?? null, $message['entities'] ?? []);
                }
                unset($message);

                return $response;
            }
        );
    }

    /**
     * Проверяет есть ли подходящие медиа у сообщения
     *
     * @param array $message
     * @param bool $allowWebPage
     *
     * @return bool
     */
    private static function hasMedia(array $message = [], bool $allowWebPage = true): bool
    {
        $media = $message['media'] ?? [];
        if (empty($media['_'])) {
            return false;
        }
        if ($media['_'] === 'messageMediaWebPage') {
            return $allowWebPage;
        }
        return true;
    }

    public function formatMessage(string $message = null, array $entities = []): ?string
    {
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

        $entities = array_reverse($entities);
        foreach ($entities as $entity) {
            if (isset($html[$entity['_']])) {
                $text = static::mbSubstr($message, $entity['offset'], $entity['length']);

                if (in_array($entity['_'], ['messageEntityTextUrl', 'messageEntityMention', 'messageEntityUrl'])) {
                    $textFormate = sprintf($html[$entity['_']], $entity['url'] ?? $text, $text);
                } else {
                    $textFormate = sprintf($html[$entity['_']], $text);
                }

                $message = static::substringReplace($message, $textFormate, $entity['offset'], $entity['length']);
            }
        }
        $message = nl2br($message);
        return $message;
    }

    private static function substringReplace(string $original, string $replacement, int $position, int $length): string
    {
        $startString = static::mbSubstr($original, 0, $position);
        $endString = static::mbSubstr($original, $position + $length, static::mbStrlen($original));
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
     * @return \Amp\Promise
     */
    public function copyMessages(array $data): \Amp\Promise
    {
        return call(
            function() use ($data) {
                $data = array_merge(
                    [
                        'from_peer' => '',
                        'to_peer' => '',
                        'id' => [],
                    ],
                    $data
                );

                $response = yield $this->madelineProto->channels->getMessages(
                    [
                        'channel' => $data['from_peer'],
                        'id' => $data['id'],
                    ]
                );
                $result = [];
                if (!$response || !is_array($response) || !array_key_exists('messages', $response)) {
                    return $result;
                }

                foreach ($response['messages'] as $message) {
                    usleep(random_int(300, 2000) * 1000);
                    $messageData = [
                        'message' => $message['message'] ?? '',
                        'peer' => $data['to_peer'],
                        'entities' => $message['entities'] ?? [],
                    ];
                    if (static::hasMedia($message, false)) {
                        $messageData['media'] = $message; //MadelineProto сама достанет все media из сообщения.
                        $result[] = yield $this->sendMedia($messageData);
                    } else {
                        $result[] = yield $this->sendMessage($messageData);
                    }
                }

                return $result;
            }
        );
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
     * @return \Amp\Promise
     */
    public function sendMedia(array $data): \Amp\Promise
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

        return $this->madelineProto->messages->sendMedia($data);
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
     * @return \Amp\Promise
     */
    public function sendMessage(array $data): \Amp\Promise
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
     * @return \Amp\Promise
     */
    public function searchGlobal(array $data): \Amp\Promise
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
        return $this->madelineProto->messages->searchGlobal($data);
    }

    /**
     * Загружает медиафайл из указанного сообщения в поток
     *
     * @param array $data
     *
     * @return \Amp\Promise
     */
    public function getMedia(array $data): \Amp\Promise
    {
        return call(
            function() use ($data) {
                $data = array_merge(
                    [
                        'peer' => '',
                        'id' => [0],
                        'message' => [],
                        'size_limit' => 0,
                    ],
                    $data
                );

                $message = $data['message'] ?: (yield $this->getMessages($data))['messages'][0] ?? null;
                if (!$message || $message['_'] === 'messageEmpty') {
                    throw new \UnexpectedValueException('Empty message');
                }

                if (!static::hasMedia($message)) {
                    throw new \UnexpectedValueException('Message has no media');
                }

                $info = yield $this->madelineProto->getDownloadInfo($message);

                if ($data['size_limit'] && $info['size'] > $data['size_limit']) {
                    throw new \OutOfRangeException(
                        "Media exceeds size limit. Size: {$info['size']} bytes; limit: {$data['size_limit']} bytes"
                    );
                }

                $stream = fopen('php://memory', 'rwb');
                yield $this->madelineProto->downloadToStream($info, $stream);
                rewind($stream);

                return [
                    'headers' => [
                        'Content-Length' => $info['size'],
                        'Content-Type' => $info['mime'],
                    ],
                    'stream' => $stream,
                ];
            }
        );
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток
     *
     * @param array $data
     *
     * @return \Amp\Promise
     */
    public function getMediaPreview(array $data): \Amp\Promise
    {
        return call(
            function() use ($data) {
                $data = array_merge(
                    [
                        'peer' => '',
                        'id' => [0],
                        'message' => [],
                    ],
                    $data
                );

                $message = $data['message'] ?: (yield $this->getMessages($data))['messages'][0] ?? null;
                if (!$message || $message['_'] === 'messageEmpty') {
                    throw new \UnexpectedValueException('Empty message');
                }

                if (!static::hasMedia($message)) {
                    throw new \UnexpectedValueException('Message has no media');
                }

                $media = $message['media'][array_key_last($message['media'])];
                switch (true) {
                    case isset($media['sizes']):
                        $thumb = $media['sizes'][array_key_last($media['sizes'])];
                        break;
                    case isset($media['thumb']['size']):
                        $thumb = $media['thumb'];
                        break;
                    case !empty($media['thumbs']):
                        $thumb = $media['thumbs'][array_key_last($media['thumbs'])];
                        break;
                    case isset($media['photo']['sizes']):
                        $thumb = $media['photo']['sizes'][array_key_last($media['photo']['sizes'])];
                        break;
                    default:
                        throw new \UnexpectedValueException('Message has no preview');

                }
                $info = yield $this->madelineProto->getDownloadInfo($thumb);

                //Фикс для LAYER 100+
                //TODO: Удалить, когда снова станет доступна загрузка photoSize
                if (isset($info['thumb_size'])) {
                    $infoFull = yield $this->madelineProto->getDownloadInfo($media);
                    $infoFull['InputFileLocation']['thumb_size'] = $info['thumb_size'];
                    $infoFull['size'] = $info['size'];
                    $infoFull['mime'] = $info['mime'];
                    $info = $infoFull;
                }

                $stream = fopen('php://memory', 'rwb');
                yield $this->madelineProto->downloadToStream($info, $stream);
                rewind($stream);

                return [
                    'headers' => [
                        'Content-Length' => $info['size'],
                        'Content-Type' => $info['mime'],
                    ],
                    'stream' => $stream,
                ];
            }
        );
    }

    /**
     * @param array $data
     *
     * @return \Amp\Promise
     */
    public function getMessages(array $data): \Amp\Promise
    {
        return call(
            function() use ($data) {
                $peerInfo = yield $this->madelineProto->getInfo($data['peer']);
                if ($peerInfo['type'] === 'channel') {
                    $response = yield $this->madelineProto->channels->getMessages(
                        [
                            'channel' => $data['peer'],
                            'id' => (array) $data['id'],
                        ]
                    );
                } else {
                    $response = yield $this->madelineProto->messages->getMessages(['id' => (array) $data['id']]);
                }

                return $response;
            }
        );
    }

}