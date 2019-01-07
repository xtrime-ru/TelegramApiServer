<?php

namespace TelegramSwooleClient;

use \danog\MadelineProto;

class Client {

    public $MadelineProto;

    /**
     * Client constructor.
     */
    public function __construct()
    {
        $sessionFile = __DIR__ . '/../session.madeline';

        $config = Config::getInstance()->getConfig('telegram');

        if (empty($config['connection_settings']['all']['proxy_extra']['address'])) {
            unset($config['connection_settings']);
        }

        //При каждой инициализации настройки обновляются из массива $config
        echo 'Starting telegram client ...' . PHP_EOL;
        $this->MadelineProto = new MadelineProto\API($sessionFile, $config);
        $this->MadelineProto->start();
        echo 'Client started' . PHP_EOL;

    }

    /**
     * Получает данные о канале/пользователе по логину
     *
     * @param array|string|id $id
     * Например логин в формате '@xtrime'
     * @return array
     */
    public function getInfo($id): array
    {
        return $this->MadelineProto->get_info($id);
    }


    /**
     * Получает последние сообщения из указанных каналов
     *
     * @param array $data
     * <pre>
     * [
     *     'peer'          => '',
     *     'offset_id'     => 0,
     *     'offset_date'   => 0,
     *     'add_offset'    => 0,
     *     'limit'         => 0,
     *     'max_id'        => 0,
     *     'min_id'        => 0,
     *     'hash'          => 0
     * ]
     * </pre>
     * @return array
     */
    public function getHistory($data): array
    {
        $data = array_merge([
            'peer'          => '',
            'offset_id'     => 0,
            'offset_date'   => 0,
            'add_offset'    => 0,
            'limit'         => 0,
            'max_id'        => 0,
            'min_id'        => 0,
            'hash'          => 0,
        ], $data);

        return $this->MadelineProto->messages->getHistory($data);
    }

    /**
     * Пересылает сообщения
     *
     * @param string $fromPeer
     * @param string $toPeer
     * @param array|int $messageId
     * Id сообщения, или нескольких сообщений
     */
    public function forwardMessages($fromPeer, $toPeer, $messageId): void
    {
        $this->MadelineProto->messages->forwardMessages([
            'from_peer' => $fromPeer,
            'to_peer'   => $toPeer,
            'id'        => (array) $messageId,
        ]);
    }

    /**
     * @param string $q
     * @param array $channels
     * @param int $limit
     * @param int $timestampMin
     * @return array
     */
    public function search($q = '', array $channels = [], $limit = 10, $timestampMin = 0): array
    {
        $search = $this->MadelineProto->messages->searchGlobal([
            'q'             => $q,
            'offset_id'     => 0,
            'offset_date'   => 0,
            'limit'         => 100,
        ]);
        $foundChannels = [];
        $messages = [];
        $tmp = [];

        // Оставляем только нужные каналы и индексируем их по id
        foreach ($search['chats'] AS $chat) {
            if (isset($chat['username'])) {
                $tmp[$chat['username']] = $chat['id'];
            }
        }

        $search['chats'] = $tmp;
        unset($tmp);
        if ($channels){
            foreach ($channels as $channel) {
                $channel = str_replace('@','',$channel);
                if ($channelId = $search['chats'][$channel] ?? 0) {
                    $foundChannels[$channelId] = $channel;
                }
            }
        }else{
            $foundChannels = array_flip($search['chats']);
        }
        unset($search['chats']);
        //

        foreach ($search['messages'] as $message) {
            if (!$channelId = $message['to_id']['channel_id'] ?? 0) {
                continue;
            }
            if (!$channelName = $foundChannels[$channelId] ?? '') {
                continue;
            }
            if (empty($message['message'])) {
                continue;
            }
            if ($timestampMin && $message['date'] < $timestampMin) {
                continue;
            }

            $messages[crc32($message['message'])] = [
                'id'        => $message['id'],
                'text'      => $message['message'],
                'channel'   => $channelName,
                'date'      => $message['date'],
            ];
        }

        usort($messages, function($a, $b) {
            return $b['date'] <=> $a['date'];
        });

        $messages = array_slice($messages, 0, $limit);

        return $messages;
    }

}
