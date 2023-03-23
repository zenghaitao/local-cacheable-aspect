<?php
declare(strict_types=1);
namespace ZenStudio\LocalCacheableAspect\Aspect;

use Hyperf\Cache\AnnotationManager;
use Hyperf\Cache\CacheManager;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Exception\Exception;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hyperf\Utils\ApplicationContext;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Server;
use ZenStudio\LocalCacheableAspect\MemCache;
use Hyperf\Di\Annotation\Aspect;

/**
 * 切入Cacheable类生成本地缓存
 * @Aspect
 */
class LocalCacheableAspect extends AbstractAspect
{
    /**
     * @var CacheManager
     */
    protected CacheManager $manager;

    /**
     * @var AnnotationManager
     */
    protected AnnotationManager $annotationManager;

    /**
     * 切入类
     * @var string[]
     */
    public $classes = [
        'Hyperf\Cache\Aspect\CacheableAspect::process',
    ];

    /**
     * 缓存版本RedisKey
     * @var string
     */
    private string $redis_version;

    /**
     * Cacheable的redis前缀
     * @var string|mixed
     */
    private string $redis_cache_prefix;

    /**
     * 默认存活时间
     * @var int
     */
    private int $default_ttl;

    /**
     * 自动清理过期数据周期
     * @var int
     */
    private int $cycle_time = 0;

    /**
     * 全局锁
     * @var bool
     */
    private bool $global_locked = false;

    /**
     * 行锁
     * @var array
     */
    private array $row_locked = [];

    /**
     * 主进程内存
     * @var MemCache|null
     */
    private ?MemCache $MemCache = null;

    /**
     * 缓存模式 table|mem|master
     * @var string
     */
    private string $cache_mode = 'worker';

    /**
     * 过期时间
     * @var int
     */
    private int $clear_time = 0;

    /**
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * @var RedisProxy
     */
    private RedisProxy $Redis;

    /**
     * @var array
     */
    private array $configs;

    /**
     * 启用开关
     * @var bool
     */
    private bool $enable;

    /**
     * @param CacheManager $manager
     * @param AnnotationManager $annotationManager
     */
    public function __construct(CacheManager $manager, AnnotationManager $annotationManager)
    {
        $this->manager = $manager;
        $this->annotationManager = $annotationManager;
        $this->container = ApplicationContext::getContainer();
        $ConfigInterface = $this->container->get(ConfigInterface::class);

        $this->configs = $ConfigInterface->get('local_cache', []);
        $pool = (string)$this->configs['redis.pool'] ?? 'default';
        $this->Redis = $this->container->get(RedisFactory::class)->get($pool);

        $this->enable = (bool)$this->configs['enable'];
        $this->redis_cache_prefix = (string)$ConfigInterface->get('cache.default')['prefix'];
        $this->redis_version = (string)$this->configs['redis.version_key'];
        $this->cycle_time = (int)$this->configs['cycle_time'];
        $this->default_ttl = (int)$this->configs['default_ttl'];

        if($this->cycle_time){
            $this->clear_time = time() + $this->cycle_time;
        }

        try {
            $Server = $this->container->get(Server::class);
            if (!empty($Server->MasterCache) && $Server->MasterCache instanceof MemCache) {
                $this->cache_mode = 'master';
                $this->MemCache = $Server->MasterCache;
                if ($this->MemCache->getClearTime()) {
                    $this->clear_time = $this->MemCache->getClearTime();
                } else {
                    $this->MemCache->setClearTime($this->clear_time);
                }
            }
        } catch (\Throwable $ex) {
            $this->cache_mode = 'worker';
        }

        if ($this->cache_mode == 'worker') {
            $this->MemCache = $this->container->get(MemCache::class);
        }
    }

    /**
     * @param ProceedingJoinPoint $proceedingJoinPoint
     * @return mixed
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        //未打开或全局锁开启时,从数据源返回数据
        if (!$this->enable || $this->global_locked) {
            return $proceedingJoinPoint->process();
        }

        //本地缓存进入清空周期
        if ($this->clear_time && time() > $this->clear_time) {
            $this->clearLocalCache();
        }

        $arguments = $proceedingJoinPoint->arguments['keys'];
        if ($arguments['proceedingJoinPoint'] instanceof ProceedingJoinPoint) {
            $className = $arguments['proceedingJoinPoint']->className;
            $method = $arguments['proceedingJoinPoint']->methodName;
            $arguments = $arguments['proceedingJoinPoint']->arguments['keys'];
            [$key, $ttl, $group, $annotation] = $this->annotationManager->getCacheableValue($className, $method, $arguments);
            $key = $this->redis_cache_prefix . $key;
            //自动重建缓存
            return $this->cacheable($key, $group, $proceedingJoinPoint);
        }

        return $proceedingJoinPoint->process();
    }

    /**
     * @param string $key
     * @param string $group
     * @param ProceedingJoinPoint $proceedingJoinPoint
     * @return mixed
     */
    private function cacheable(string $key, string $group, ProceedingJoinPoint $proceedingJoinPoint)
    {
        $new_version = $this->hasFlush($key);
        if (!$this->hasCache($key) || $new_version) {
            //获取源数据的ttl
            $ttl = (int)$this->container->get(RedisFactory::class)->get($group)->ttl($key);
            if ($ttl <= 0) {
                //默认缓存3600秒
                $ttl = $this->default_ttl;
            }
            $ttl = time() + $ttl;

            $data = $proceedingJoinPoint->process();

            if (!empty($this->MemCache)) {
                //MemCache重建本地缓存
                if (empty($this->row_locked[$key])) {
                    $this->row_locked[$key] = true;
                    $this->MemCache->setData($key, $ttl, $new_version, $data);
                    unset($this->row_locked[$key]);
                }
            }

            //来自父类的返回数据
            return $data;

        } else {
            //来自本地的返回数据
            $data = $this->getCache($key);
            if ($data === false) {
                return $proceedingJoinPoint->process();
            }
            return $data;
        }
    }

    /**
     * @param string $key
     * @return int
     */
    private function hasFlush(string $key): int
    {
        $version = $this->getVersion($key);

        if (time() > $this->getLocalTTL($key)) {
            //需要重建 返回最新版本号
            return $version;
        }

        if (!empty($this->MemCache)) {
            $local_version = $this->MemCache->getVersion($key);
        } else {
            $local_version = 0;
        }

        if ($local_version != $version) {
            //需要重建 返回最新版本号
            return $version;
        }
        return 0;
    }

    /**
     * 获取过期时间
     * @param string $key
     * @return int
     */
    private function getLocalTTL(string $key): int
    {
        if (!empty($this->MemCache)) {
            return $this->MemCache->getTTL($key);
        } else {
            return 0;
        }
    }

    /**
     * 获取版本号
     * @param string $key
     * @return int
     */
    private function getVersion(string $key): int
    {
        $version = (int)$this->Redis->hGet($this->redis_version, $key);
        if (!$version) {
            return $this->Redis->hIncrBy($this->redis_version, $key, 1);
        }
        return $version;
    }

    /**
     * @param string $key
     * @return bool
     */
    private function hasCache(string $key): bool
    {
        if (!empty($this->MemCache)) {
            return $this->MemCache->Exist($key);
        } else {
            return false;
        }
    }

    /**
     * @param string $key
     * @return bool|mixed|null
     */
    private function getCache(string $key)
    {
        if (!empty($this->MemCache)) {
            return $this->MemCache->getData($key);
        } else {
            return false;
        }
    }

    /**
     * @return void
     */
    private function clearLocalCache(){
        if (!$this->global_locked) {
            //开启写入锁
            $this->global_locked = true;

            //更新为最后时间
            $this->clear_time = time() + $this->cycle_time;

            if (!empty($this->MemCache)) {
                if ($this->MemCache->getClearTime() <= time()) {
                    $this->MemCache->Clear();
                    $this->MemCache->setClearTime($this->clear_time);
                }
            }

            //取消全局锁
            $this->global_locked = false;
        }
    }
}
