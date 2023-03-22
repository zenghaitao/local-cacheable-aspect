<?php
declare(strict_types=1);
namespace ZenStudio\LocalCacheableAspect\Contract;

interface ILocalCacheStartCallBack
{
    /**
     * @return void
     */
    public function handle():void;
}