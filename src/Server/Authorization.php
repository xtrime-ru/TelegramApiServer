<?php declare(strict_types=1);

namespace TelegramApiServer\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramApiServer\Config;

final class Authorization implements Middleware
{
    private array $ipWhitelist;
    private int $selfIp;
    /**
     * @var array<string,string>
     */
    private array $passwords;

    public function __construct()
    {
        $this->selfIp = \ip2long(\getHostByName(\php_uname('n')));
        $this->ipWhitelist = (array) Config::getInstance()->get('api.ip_whitelist', []);
        $this->passwords = Config::getInstance()->get('api.passwords', []);
        if (!$this->ipWhitelist && !$this->passwords) {
            error('API is unprotected! Please specify IP_WHITELIST or PASSWORD in .env.docker');
        }
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = Server::getClientIp($request);

        if ($this->isLocal($host)) {
            return $requestHandler->handleRequest($request);
        }

        if ($this->passwords) {
            $header = (string) $request->getHeader('Authorization');
            if ($header) {
                \sscanf($header, "Basic %s", $encodedPassword);
                [$username, $password] = \explode(':', \base64_decode($encodedPassword), 2);
                if (\array_key_exists($username, $this->passwords) && $this->passwords[$username] === $password) {
                    return $requestHandler->handleRequest($request);
                }
            }

            return ErrorResponses::get(HttpStatus::UNAUTHORIZED, 'Username or password is incorrect');
        }

        if ($this->isIpAllowed($host)) {
            return $requestHandler->handleRequest($request);
        }

        return ErrorResponses::get(HttpStatus::UNAUTHORIZED, 'Your host is not allowed: ' . $host);
    }

    private function isIpAllowed(string $host): bool
    {

        if ($this->ipWhitelist && !\in_array($host, $this->ipWhitelist, true)) {
            return false;
        }
        return true;
    }

    private function isLocal(string $host): bool
    {
        if ($host === '127.0.0.1' || $host === 'localhost') {
            return true;
        }

        global $options;
        if ($options['docker']) {
            $isSameNetwork = \abs(\ip2long($host) - $this->selfIp) < 256;
            if ($isSameNetwork) {
                return true;
            }
        }
        return false;
    }
}
