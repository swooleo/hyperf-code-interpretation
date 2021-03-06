<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Server;

use Hyperf\Contract\MiddlewareInitializerInterface;
use Hyperf\Framework\Bootstrap;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Framework\Event\BeforeServerStart;
use Hyperf\Server\Exception\RuntimeException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server as SwooleWebSocketServer;

class Server implements ServerInterface
{
    /**
     * @var bool
     */
    protected $enableHttpServer = false;

    /**
     * @var bool
     */
    protected $enableWebsocketServer = false;

    /**
     * @var SwooleServer
     */
    protected $server;

    /**
     * @var array
     */
    protected $onRequestCallbacks = [];

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(ContainerInterface $container, LoggerInterface $logger, EventDispatcherInterface $dispatcher)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->eventDispatcher = $dispatcher;
    }

    public function init(ServerConfig $config): ServerInterface
    {
        $this->initServers($config);

        return $this;
    }

    public function start()
    {
        $this->server->start();
    }

    public function getServer()
    {
        return $this->server;
    }

    /**
     * swoole配置，注册回调事件
     * @param \Hyperf\Server\ServerConfig $config
     */
    protected function initServers(ServerConfig $config)
    {
        $servers = $this->sortServers($config->getServers());
        //如果配置多个server,需要先启动http/ws server，再通过addPort()的方式添加其余的server
        //绑定server的callback函数
        //触发 BeforeMainServerStart BeforeServerStart beforeStart
        foreach ($servers as $server) {
            $name = $server->getName();
            $type = $server->getType();
            $host = $server->getHost();
            $port = $server->getPort();
            $sockType = $server->getSockType();
            $callbacks = $server->getCallbacks();

            if (! $this->server instanceof SwooleServer) {
                $this->server = $this->makeServer($type, $host, $port, $config->getMode(), $sockType);
                $callbacks = array_replace($this->defaultCallbacks(), $config->getCallbacks(), $callbacks);
                $this->registerSwooleEvents($this->server, $callbacks, $name);
                $this->server->set(array_replace($config->getSettings(), $server->getSettings()));
                ServerManager::add($name, [$type, current($this->server->ports)]);

                if (class_exists(BeforeMainServerStart::class)) {
                    //这里会启动amqp,自定义process 大家可以搜索源码根据监听BeforeMainServerStart的事件，
                    ## https://hyperf.wiki/2.0/#/zh-cn/event?id=hyperf-%e7%94%9f%e5%91%bd%e5%91%a8%e6%9c%9f%e4%ba%8b%e4%bb%b6
                    // Trigger BeforeMainServerStart event, this event only trigger once before main server start.
                    $this->eventDispatcher->dispatch(new BeforeMainServerStart($this->server, $config->toArray()));
                }
            } else {
                /** @var bool|\Swoole\Server\Port $slaveServer */
                $slaveServer = $this->server->addlistener($host, $port, $sockType);
                if (! $slaveServer) {
                    throw new \RuntimeException("Failed to listen server port [{$host}:{$port}]");
                }
                $server->getSettings() && $slaveServer->set(array_replace($config->getSettings(), $server->getSettings()));
                $this->registerSwooleEvents($slaveServer, $callbacks, $name);
                ServerManager::add($name, [$type, $slaveServer]);
            }

            // Trigger beforeStart event.
            if (isset($callbacks[SwooleEvent::ON_BEFORE_START])) {
                [$class, $method] = $callbacks[SwooleEvent::ON_BEFORE_START];
                if ($this->container->has($class)) {
                    $this->container->get($class)->{$method}();
                }
            }

            if (class_exists(BeforeServerStart::class)) {
                // Trigger BeforeServerStart event.
                $this->eventDispatcher->dispatch(new BeforeServerStart($name));
            }
        }
    }

    /**
     * @param Port[] $servers
     * @return Port[]
     */
    protected function sortServers(array $servers)
    {
        $sortServers = [];
        foreach ($servers as $server) {
            switch ($server->getType() ?? 0) {
                case ServerInterface::SERVER_HTTP:
                    $this->enableHttpServer = true;
                    if (! $this->enableWebsocketServer) {
                        array_unshift($sortServers, $server);
                    } else {
                        $sortServers[] = $server;
                    }
                    break;
                case ServerInterface::SERVER_WEBSOCKET:
                    $this->enableWebsocketServer = true;
                    array_unshift($sortServers, $server);
                    break;
                default:
                    $sortServers[] = $server;
                    break;
            }
        }

        return $sortServers;
    }

    protected function makeServer(int $type, string $host, int $port, int $mode, int $sockType)
    {
        switch ($type) {
            case ServerInterface::SERVER_HTTP:
                return new SwooleHttpServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_WEBSOCKET:
                return new SwooleWebSocketServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_BASE:
                return new SwooleServer($host, $port, $mode, $sockType);
        }

        throw new RuntimeException('Server type is invalid.');
    }

    /**
     * @param \Swoole\Server\Port|SwooleServer $server
     */
    protected function registerSwooleEvents($server, array $events, string $serverName): void
    {
        foreach ($events as $event => $callback) {
            if (! SwooleEvent::isSwooleEvent($event)) {
                continue;
            }
            if (is_array($callback)) {
                //根据对应的server 注册对应的事件和实例化对应的对象
                // 'callbacks' => [
                //                SwooleEvent::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
                //            ],
               // $className Hyperf\HttpServer\Server
               // $method onRequest
                [$className, $method] = $callback;
                if (array_key_exists($className . $method, $this->onRequestCallbacks)) {
                    $this->logger->warning(sprintf('%s will be replaced by %s. Each server should have its own onRequest callback. Please check your configs.', $this->onRequestCallbacks[$className . $method], $serverName));
                }

                $this->onRequestCallbacks[$className . $method] = $serverName;
                //Hyperf\HttpServer\Server Hyperf\WebSocketServer\Server 在此被实例化.
                $class = $this->container->get($className);
                if (method_exists($class, 'setServerName')) {
                    // Override the server name.
                    $class->setServerName($serverName);
                }
                if ($class instanceof MiddlewareInitializerInterface) {
                    //加载中间件
                    $class->initCoreMiddleware($serverName);
                }
                $callback = [$class, $method];
            }
            //swoole的回调事件
            $server->on($event, $callback);
        }
    }

    protected function defaultCallbacks()
    {
        $hasCallback = class_exists(Bootstrap\StartCallback::class) &&
            class_exists(Bootstrap\ManagerStartCallback::class) &&
            class_exists(Bootstrap\WorkerStartCallback::class);

        if ($hasCallback) {
            return [
                SwooleEvent::ON_START => [Bootstrap\StartCallback::class, 'onStart'],
                SwooleEvent::ON_MANAGER_START => [Bootstrap\ManagerStartCallback::class, 'onManagerStart'],
                SwooleEvent::ON_WORKER_START => [Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
                SwooleEvent::ON_WORKER_STOP => [Bootstrap\WorkerStopCallback::class, 'onWorkerStop'],
            ];
        }

        return [
            SwooleEvent::ON_WORKER_START => function (SwooleServer $server, int $workerId) {
                printf('Worker %d started.' . PHP_EOL, $workerId);
            },
        ];
    }
}
