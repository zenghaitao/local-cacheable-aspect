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
namespace ZenStudio\LocalCacheableAspect;

use ZenStudio\LocalCacheableAspect\Contract\ILocalCacheStartCallBack;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for local cache aspect.',
                    'source' => __DIR__ . '/../publish/local_cache.php',
                    'destination' => BASE_PATH . '/config/autoload/local_cache.php',
                ],
            ],
            'dependencies' => [
                ILocalCacheStartCallBack::class => LocalCacheStartCallBack::class,
            ],
            'commands' => [
            ],
        ];
    }
}
