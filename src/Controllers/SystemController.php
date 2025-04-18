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
                $args[$param->getName()] = true;
            }
            $name = $method->getName();
            $this->methods[$method->getName()] = function (...$params) use ($args, $name) {
                return $this->extension->{$name}(...array_intersect_key($params, $args));
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
