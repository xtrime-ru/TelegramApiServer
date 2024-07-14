<?php declare(strict_types=1);

namespace TelegramApiServer\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\SocketHttpServer;
use TelegramApiServer\Controllers\ApiController;
use TelegramApiServer\Controllers\EventsController;
use TelegramApiServer\Controllers\LogController;
use TelegramApiServer\Controllers\SystemController;
use TelegramApiServer\Logger;
use TelegramApiServer\MadelineProtoExtensions\ApiExtensions;
use TelegramApiServer\MadelineProtoExtensions\SystemApiExtensions;
use function Amp\Http\Server\Middleware\stackMiddleware;

final class Router
{
    private \Amp\Http\Server\Router $router;
    private SocketHttpServer $server;

    public function __construct(SocketHttpServer $server, ErrorHandler $errorHandler)
    {
        $this->server = $server;
        $this->router = new \Amp\Http\Server\Router(
            httpServer: $server,
            logger: Logger::getInstance(),
            errorHandler: $errorHandler,
        );
        $this->setRoutes();
        $this->setFallback();
    }

    public function getRouter(): \Amp\Http\Server\Router
    {
        return $this->router;
    }

    private function setFallback(): void
    {
        $this->router->setFallback(new ClosureRequestHandler(static function (Request $request) {
            return ErrorResponses::get(HttpStatus::NOT_FOUND, 'Path not found');
        }));
    }

    private function setRoutes(): void
    {
        $middlewares = [
            new AccessLoggerMiddleware(Logger::getInstance()),
            new Authorization()
        ];
        $apiHandler = stackMiddleware(ApiController::getRouterCallback(ApiExtensions::class), ...$middlewares);
        $systemApiHandler = stackMiddleware(SystemController::getRouterCallback(SystemApiExtensions::class), ...$middlewares);
        $eventsHandler = stackMiddleware(EventsController::getRouterCallback($this->server), ...$middlewares);
        $logHandler = stackMiddleware(LogController::getRouterCallback($this->server), ...$middlewares);

        foreach (['GET', 'POST'] as $method) {
            $this->router->addRoute($method, '/api/{method}[/]', $apiHandler);
            $this->router->addRoute($method, '/api/{session:.*?[^/]}/{method}[/]', $apiHandler);

            $this->router->addRoute($method, '/system/{method}[/]', $systemApiHandler);
        }

        $this->router->addRoute('GET', '/events[/]', $eventsHandler);
        $this->router->addRoute('GET', '/events/{session:.*?[^/]}[/]', $eventsHandler);

        $this->router->addRoute('GET', '/log[/]', $logHandler);
        $this->router->addRoute('GET', '/log/{level:.*?[^/]}[/]', $logHandler);
    }

}
