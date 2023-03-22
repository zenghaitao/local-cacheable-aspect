<?php
declare(strict_types=1);
namespace ZenStudio\LocalCacheableAspect;

class LocalCacheStartCallBack
{
    public function handle()
    {
        MemCache::Create();
    }

}