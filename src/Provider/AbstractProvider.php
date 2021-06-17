<?php

declare(strict_types=1);

namespace Yansongda\Pay\Provider;

use Psr\Http\Client\ClientInterface;
use Throwable;
use Yansongda\Pay\Contract\HttpClientInterface;
use Yansongda\Pay\Contract\PluginInterface;
use Yansongda\Pay\Contract\ProviderInterface;
use Yansongda\Pay\Contract\ShortcutInterface;
use Yansongda\Pay\Event;
use Yansongda\Pay\Exception\InvalidConfigException;
use Yansongda\Pay\Exception\InvalidParamsException;
use Yansongda\Pay\Exception\InvalidResponseException;
use Yansongda\Pay\Logger;
use Yansongda\Pay\Pay;
use Yansongda\Pay\Rocket;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Pipeline;

abstract class AbstractProvider implements ProviderInterface
{
    /**
     * @throws \Yansongda\Pay\Exception\ContainerDependencyException
     * @throws \Yansongda\Pay\Exception\ContainerException
     * @throws \Yansongda\Pay\Exception\InvalidParamsException
     * @throws \Yansongda\Pay\Exception\ServiceNotFoundException
     *
     * @return \Yansongda\Supports\Collection|\Psr\Http\Message\ResponseInterface
     */
    public function call(string $plugin, array $params)
    {
        if (!class_exists($plugin) || !in_array(ShortcutInterface::class, class_implements($plugin))) {
            throw new InvalidParamsException(InvalidParamsException::SHORTCUT_NOT_FOUND, "[$plugin] is not incompatible");
        }

        Event::dispatch(new Event\MethodCalled($plugin, $params, null));

        /* @var ShortcutInterface $money */
        $money = Pay::get($plugin);

        return $this->pay(
            $this->mergeCommonPlugins($money->getPlugins(...$params)),
            ...$params
        );
    }

    /**
     * @throws \Yansongda\Pay\Exception\ContainerDependencyException
     * @throws \Yansongda\Pay\Exception\ContainerException
     * @throws \Yansongda\Pay\Exception\InvalidParamsException
     * @throws \Yansongda\Pay\Exception\ServiceNotFoundException
     *
     * @return \Yansongda\Supports\Collection|\Psr\Http\Message\ResponseInterface
     */
    public function pay(array $plugins, array $params)
    {
        Logger::info('[AbstractProvider] 即将进行支付操作', func_get_args());

        Event::dispatch(new Event\PayStarted($plugins, $params, null));

        $this->verifyPlugin($plugins);

        /* @var Pipeline $pipeline */
        $pipeline = Pay::make(Pipeline::class);

        /* @var Rocket $rocket */
        $rocket = $pipeline
            ->send((new Rocket())->setParams($params)->setPayload(new Collection()))
            ->through($plugins)
            ->via('assembly')
            ->then(function ($rocket) {
                return $this->ignite($rocket);
            });

        Event::dispatch(new Event\PayFinish($rocket));

        return $rocket->getDestination();
    }

    /**
     * @throws \Yansongda\Pay\Exception\ContainerDependencyException
     * @throws \Yansongda\Pay\Exception\ContainerException
     * @throws \Yansongda\Pay\Exception\ServiceNotFoundException
     * @throws \Yansongda\Pay\Exception\InvalidResponseException
     * @throws \Yansongda\Pay\Exception\InvalidConfigException
     */
    public function ignite(Rocket $rocket): Rocket
    {
        if (!should_do_http_request($rocket)) {
            return $rocket;
        }

        /* @var HttpClientInterface $http */
        $http = Pay::get(HttpClientInterface::class);

        if (!($http instanceof ClientInterface)) {
            throw new InvalidConfigException(InvalidConfigException::HTTP_CLIENT_CONFIG_ERROR);
        }

        Logger::info('[AbstractProvider] 准备请求支付服务商 API', $rocket->toArray());

        Event::dispatch(new Event\ApiRequesting($rocket));

        try {
            $response = $http->sendRequest($rocket->getRadar());

            $rocket->setDestination($response);
        } catch (Throwable $e) {
            Logger::error('[AbstractProvider] 请求支付服务商 API 出错', ['message' => $e->getMessage(), 'rocket' => $rocket->toArray(), 'trace' => $e->getTrace()]);

            throw new InvalidResponseException(InvalidResponseException::REQUEST_RESPONSE_ERROR, $e->getMessage());
        }

        Logger::info('[AbstractProvider] 请求支付服务商 API 成功', ['response' => $response, 'rocket' => $rocket->toArray()]);

        Event::dispatch(new Event\ApiRequested($rocket));

        return $rocket;
    }

    abstract public function mergeCommonPlugins(array $plugins): array;

    /**
     * @throws \Yansongda\Pay\Exception\InvalidParamsException
     */
    protected function verifyPlugin(array $plugins): void
    {
        foreach ($plugins as $plugin) {
            if (is_callable($plugin)) {
                continue;
            }

            if ((is_object($plugin) ||
                    (is_string($plugin) && class_exists($plugin))) &&
                in_array(PluginInterface::class, class_implements($plugin))) {
                continue;
            }

            throw new InvalidParamsException(InvalidParamsException::PLUGIN_ERROR, "[$plugin] is not incompatible");
        }
    }
}
