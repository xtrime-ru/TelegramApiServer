<?php declare(strict_types=1);

namespace TelegramApiServer\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\LogLevel;

final class AccessLoggerMiddleware implements Middleware
{
    public function __construct(
        private readonly PsrLogger $logger,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {

        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $protocolVersion = $request->getProtocolVersion();
        $remote = Server::getClientIp($request);

        $context = [
            'method' => $method,
            'uri' => $uri,
            'client' => $remote,
        ];

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                \sprintf(
                    'Client exception for "%s %s" HTTP/%s %s',
                    $method,
                    $uri,
                    $protocolVersion,
                    $remote
                ),
                $context
            );

            throw $exception;
        }

        $status = $response->getStatus();
        $reason = $response->getReason();

        $context = [
            'request' => $context,
            'response' => [
                'status' => $status,
                'reason' => $reason,
            ],
        ];

        $level = $status < 400 ? LogLevel::DEBUG : LogLevel::INFO;

        $this->logger->log(
            $level,
            \sprintf(
                '"%s %s" %d "%s" HTTP/%s %s',
                $method,
                $uri,
                $status,
                $reason,
                $protocolVersion,
                $remote
            ),
            $context
        );

        return $response;
    }
}
