<?php

namespace TelegramApiServer\Controllers;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use danog\MadelineProto\API;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use Revolt\EventLoop;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\MadelineProtoExtensions\ApiExtensions;
use UnexpectedValueException;

final class ApiController extends AbstractApiController
{
    private ?string $session = '';
    private readonly ApiExtensions $extension;

    private array $methods = [];
    private array $methodsMadeline = [];
    public function __construct()
    {
        $this->extension = new ApiExtensions;
        foreach ((new ReflectionClass(ApiExtensions::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $args = [];
            foreach ($method->getParameters() as $param) {
                $args[$param->getName()] = true;
            }
            $name = $method->getName();
            $this->methods[$method->getName()] = function (API $API, ...$params) use ($args, $name) {
                return $this->extension->{$name}($API, ...array_intersect_key($params, $args));
            };
        }
        foreach ((new ReflectionClass(API::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $args = [];
            foreach ($method->getParameters() as $param) {
                $args[$param->getName()] = true;
            }
            $name = $method->getName();
            $this->methodsMadeline[$method->getName()] = function (API $API, ...$params) use ($args, $name) {
                return $API->{$name}(...array_intersect_key($params, $args));
            };
        }
    }

    private static ?Future $w = null;
    /**
     * @throws Exception
     */
    protected function callApi(Request $request): mixed
    {
        $path = $request->getAttribute(Router::class);
        $session = $path['session'] ?? null;
        $api = \explode('.', $path['method'] ?? '');

        $madelineProto = Client::getInstance()->getSession($session);
        $tick = Config::getInstance()->get('api.bulk_interval');
        $params = $this->resolveRequest($request);

        if (!$tick) {
            return $this->callApiCommon($madelineProto, $api, $params);
        }

        if (!self::$w) {
            $f = new DeferredFuture();
            self::$w = $f->getFuture();
            EventLoop::delay($tick, static function () use ($f): void {
                $f->complete();
                self::$w = null;
            });
        }
        self::$w->await();

        return $this->callApiCommon($madelineProto, $api, $params);
    }


    private function callApiCommon(API $madelineProto, array $api, array $parameters)
    {
        $pathCount = \count($api);
        if ($pathCount === 1 && \array_key_exists($api[0], $this->methods)) {
            $result = $this->methods[$api[0]]($madelineProto, ...$parameters);
        } else {
            if ($api[0] === 'API') {
                $madelineProto = Client::getWrapper($madelineProto)->getAPI();
                \array_shift($api);
                $pathCount = \count($api);
            }
            //Проверяем нет ли в MadilineProto такого метода.
            switch ($pathCount) {
                case 1:
                    $result = $this->methodsMadeline[$api[0]]($madelineProto, ...$parameters);
                    break;
                case 2:
                    $result = $madelineProto->{$api[0]}->{$api[1]}(...$parameters);
                    break;
                default:
                    throw new UnexpectedValueException('Incorrect method format');
            }
        }

        return $result;
    }

}
