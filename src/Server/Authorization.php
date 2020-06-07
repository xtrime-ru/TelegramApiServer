<?php

namespace TelegramApiServer\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Status;
use Amp\Promise;
use TelegramApiServer\Config;
use function Amp\call;

class Authorization implements Middleware
{
    private array $ipWhitelist;

    public function __construct()
    {
        $this->ipWhitelist = (array) Config::getInstance()->get('api.ip_whitelist', []);
        //Add self ip for docker.
        if (\count($this->ipWhitelist) > 0) {
            $this->ipWhitelist[] = getHostByName(php_uname('n'));
        }
    }

    public function handleRequest(Request $request, RequestHandler $next): Promise {
        return call(function () use ($request, $next) {

            $host = $request->getClient()->getRemoteAddress()->getHost();
            if ($this->isIpAllowed($host)) {
                $response = yield $next->handleRequest($request);
            } else {
                $response = ErrorResponses::get(Status::FORBIDDEN, 'Your host is not allowed');
            }

            return $response;
        });
    }

    private function isIpAllowed(string $host): bool
    {
        if ($this->ipWhitelist && !in_array($host, $this->ipWhitelist, true)) {
            return false;
        }
        return true;
    }
}