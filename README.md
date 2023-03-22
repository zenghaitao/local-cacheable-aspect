# component-local-cacheable-aspect

```
composer require zen-studio/local-cacheable-aspect

config/autoload/server.php
'callbacks'中增加事件
Event::ON_BEFORE_START => [ZenStudio\LocalCacheableAspect\Contract\ILocalCacheStartCallBack:class, 'handle']

```