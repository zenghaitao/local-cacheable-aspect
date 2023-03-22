<?php
declare(strict_types=1);

namespace ZenStudio\LocalCacheableAspect;

use Hyperf\Utils\ApplicationContext;
use Swoole\Server;

class MemCache
{
    //版本数据
    private array $version = [];
    //本地缓存数据
    private array $cache = [];
    //有效期数据
    private array $ttl = [];

    /**
     * @param string $key
     * @param int $ttl
     * @param int $version
     * @param mixed $data
     * @return bool
     */
    public function setData(string $key, int $ttl, int $version, $data): bool
    {
        $this->cache[$key] = $data;
        $this->version[$key] = $version;
        $this->ttl[$key] = $ttl;
        return true;
    }

    /**
     * 获取完整数据
     * @param string $key
     * @return mixed
     */
    public function getData(string $key)
    {
        return $this->cache[$key];
    }

    /**
     * 数据是否存在
     * @param string $key
     * @return bool
     */
    public function Exist(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * 获取本地数据版本
     * @param string $key
     * @return int
     */
    public function getVersion(string $key): int
    {
        return (int)$this->version[$key];
    }

    /**
     * 获取有效期
     * @param string $key
     * @return int
     */
    public function getTTL(string $key): int
    {
        return (int)$this->ttl[$key];
    }

    /**
     * 清空Table数据
     * @return void
     */
    public function Clear()
    {
        $time = time();
        foreach ($this->ttl as $key => $ttl) {
            if ($ttl <= $time) {
                //清理过期的缓存数据
                unset($this->cache[$key]);
                unset($this->ttl[$key]);
                unset($this->version[$key]);
            }
        }
    }

    /**
     * 设置自动清理时间
     * @param int $time
     * @return void
     */
    public function setClearTime(int $time)
    {
        $this->setData('MemCache.ClearTime', 0, 0, $time);
    }

    /**
     * 获取自动清理时间
     * @return int
     */
    public function getClearTime(): int
    {
        return (int)$this->getData('MemCache.ClearTime');
    }

    /**
     * 创建ServerCache
     * @return void
     */
    public static function Create()
    {
        $Cache = new static();
        $Server = ApplicationContext::getContainer()->get(Server::class);
        if (empty($Server->MasterCache)) {
            $Server->MasterCache = $Cache;
        }
    }
}