<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use TelegramApiServer\Client;
use TelegramApiServer\MadelineProtoExtensions\SystemApiExtensions;

final class SystemController extends AbstractApiController
{
    private readonly SystemApiExtensions $extension;
    private array $methods = [];
    public function __construct()
    {
        $this->extension = new SystemApiExtensions(Client::getInstance());
        foreach ((new ReflectionClass(SystemApiExtensions::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $args = [];
            foreach ($method->getParameters() as $param) {
                $args[$param->getName()] = null;
            }
            $name = $method->getName();
            $needRequest = array_key_exists('request', $args);
            $this->methods[$method->getName()] = function (...$params) use ($args, $name, $needRequest) {
                if (!$needRequest) {
                    unset($params['request']);
                }
                $argsPrepared = array_intersect_key($params, $args) ?: array_values($params);
                return $this->extension->{$name}(...$argsPrepared);
            };
        }
    }

    /**
     * @throws Exception
     */
    protected function callApi(Request $request)
    {
        $path = $request->getAttribute(Router::class);
        $api = \explode('.', $path['method'] ?? '');
        return $this->methods[$api[0]](...$this->resolveRequest($request));
    }
}
