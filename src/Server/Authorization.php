<?php

namespace TelegramApiServer\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramApiServer\Config;

class Authorization implements Middleware
{
    private array $ipWhitelist;
    private int $selfIp;

    public function __construct()
    {
        $this->ipWhitelist = (array)Config::getInstance()->get('api.ip_whitelist', []);
        $this->selfIp = ip2long(getHostByName(php_uname('n')));
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = explode(':', $request->getClient()->getRemoteAddress()->toString())[0];
        if ($this->isIpAllowed($host)) {
            $response = $requestHandler->handleRequest($request);
        } else {
            $response = ErrorResponses::get(HttpStatus::FORBIDDEN, 'Your host is not allowed: ' . $host);
        }

        return $response;
    }

    private function isIpAllowed(string $host): bool
    {
        global $options;
        if ($options['docker']) {
            $isSameNetwork = abs(ip2long($host) - $this->selfIp) < 256;
            if ($isSameNetwork) {
                return true;
            }
        }

        if ($this->ipWhitelist && !in_array($host, $this->ipWhitelist, true)) {
            return false;
        }
        return true;
    }
}