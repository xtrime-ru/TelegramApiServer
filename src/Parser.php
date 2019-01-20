<?php

namespace TelegramSwooleClient;

class Parser
{
    public $client;
    private $settings = [
        'peer' => '',
        'limit' => 10,
        'max_id' => 0,
    ];
    private $RPS = 3;
    private $requestTimestampMs = 0;
    private $maxPeers = 50;

    /**
     * Parser constructor.
     * @param $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function validator($data): array
    {
        if (!$data) {
            throw new \Exception('No channels');
        }
        if (count($data) > $this->maxPeers) {
            throw new \Exception('Too many channels');
        }

        if (isset($data['peer'])) {
            $data = [$data];
        }

        foreach ($data as $key=>$val) {
            if (empty($val['peer'])) {
                throw new \Exception('Wrong data format');
            } else {
                if (preg_match('/bot$/i', $val['peer'])){
                    throw new \Exception('Bots not allowed');
                }
                if (preg_match('/[^\w\-#@]/', $val['peer'])){
                    throw new \Exception('Incorrect peer name');
                }
                if (preg_match('/[A-Z]/', $val['peer'])) {
                    throw new \Exception('Uppercase not supported');
                }
            }
        }

        return $data;
    }

    /**
     * @param $settings
     * @return Parser
     */
    public function setSettings($settings): self
    {
        $this->settings = array_merge($this->settings, $settings);
        return $this;
    }

    /**
     * Получает посты из указанных каналов
     *
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getHistory(array $data=[]):array
    {
        $data = $this->validator($data);

        $result = [];
        foreach ($data as $val)
        {
            $this->rateLimiter();
            $this->setSettings($val);
            $posts = $this->client->getHistory($this->settings);
            $posts = $this->privacyKeeper($posts);
            $result[$val['peer']] = $posts;
        }

        return $result;
    }

    /**
     * Ограничивает количество запросов, что бы избежать ошибки FLOOD_WAIT
     */
    private function rateLimiter(): void
    {
        $time = round(microtime(true)*1000, 0);
        if (!$this->requestTimestampMs) {
            $this->requestTimestampMs = $time;
        } else {
            $interval = $time - $this->requestTimestampMs;
            $targetInterval = 1000/$this->RPS;

            if ($interval < $targetInterval) {
                usleep(
                    mt_rand(
                        round($targetInterval/1.3),
                        round($targetInterval*1.3)
                    )
                );
            }
        }
    }

    /**
     * Фильтрует из результатов не каналы.
     * @param $posts
     * @return array
     */
    private function privacyKeeper($posts):array
    {
        if (!isset($posts['_']) || $posts['_'] != 'messages.channelMessages') {
            $posts = ['error'=>'This is not channel'];
        }

        return $posts;
    }
}