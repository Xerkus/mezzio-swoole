<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-swoole for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Swoole;

use Psr\Container\ContainerInterface;
use Swoole\Http\Server as SwooleHttpServer;

class ServerFactoryFactory
{
    /** @todo Remove these factory constants */

    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 8080;

    public function __invoke(ContainerInterface $container) : ServerFactory
    {
        $config = $container->get('config');
        $swooleConfig = $config['zend-expressive-swoole']['swoole-http-server'] ?? [];

        return new ServerFactory(
            $container->get(SwooleHttpServer::class),
            $swooleConfig['options'] ?? []
        );
    }
}
