<?php

namespace TelegramApiServer\Controllers;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use danog\MadelineProto\API;
use danog\MadelineProto\MTProto;
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
                $args[$param->getName()] = null;
            }
            $name = $method->getName();
            $needRequest = array_key_exists('request', $args);
            $this->methods[mb_strtolower($name)] = function (API $API, ...$params) use ($args, $name, $needRequest) {
                return $this->extension->{$name}($API, ...self::prepareArgs($needRequest, $params, $args));
            };
        }
        $classes = [API::class, \danog\MadelineProto\MTProto::class];
        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $args = [];
                foreach ($method->getParameters() as $param) {
                    $args[$param->getName()] = null;
                }
                $name = $method->getName();
                $needRequest = array_key_exists('request', $args);
                $this->methodsMadeline[mb_strtolower($name)] = function (API|MTProto $API, ...$params) use ($args, $name, $needRequest) {
                    return $API->{$name}(...self::prepareArgs($needRequest, $params, $args));
                };
            }
        }

    }

    private static ?Future $w = null;

    private static function prepareArgs(bool $needRequest, array $params, array $args): array
    {
        if (!$needRequest) {
            unset($params['request']);
        }
        $argsPrepared = array_intersect_key($params, $args);
        if (count($argsPrepared) !== count($params)) {
            $argsPrepared = array_values($params);
        }

        return $argsPrepared;
    }

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
        if ($pathCount === 1 && \array_key_exists(mb_strtolower($api[0]), $this->methods)) {
            $result = $this->methods[mb_strtolower($api[0])]($madelineProto, ...$parameters);
        } else {
            if (mb_strtolower($api[0]) === 'api') {
                $madelineProto = Client::getWrapper($madelineProto)->getAPI();
                \array_shift($api);
                $pathCount = \count($api);
            }
            //Проверяем нет ли в MadilineProto такого метода.
            switch ($pathCount) {
                case 1:
                    $result = $this->methodsMadeline[mb_strtolower($api[0])]($madelineProto, ...$parameters);
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
