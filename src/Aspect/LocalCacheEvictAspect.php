<?php
declare(strict_types=1);
namespace ZenStudio\LocalCacheableAspect\Aspect;

use Hyperf\Cache\AnnotationManager;
use Hyperf\Cache\CacheManager;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hyperf\Utils\ApplicationContext;
use PhpCsFixer\ConfigInterface;
use Psr\Container\ContainerInterface;
use Hyperf\Di\Annotation\Aspect;

/**
 * @Aspect
 */
class LocalCacheEvictAspect extends AbstractAspect
{
    /**
     * @var CacheManager
     */
    protected $manager;

    /**
     * @var AnnotationManager
     */
    protected AnnotationManager $annotationManager;

    /**
     * 切入类
     * @var string[]
     */
    public $classes = [
        'Hyperf\Cache\Aspect\CacheEvictAspect::process',
    ];

    /**
     * Cacheable的redis前缀
     * @var string
     */
    private string $redis_cache_prefix;

    /**
     * 缓存版本的Key
     * @var string
     */
    private string $redis_version;

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
     * @param CacheManager $manager
     * @param AnnotationManager $annotationManager
     */
    public function __construct(CacheManager $manager, AnnotationManager $annotationManager)
    {
        $this->manager = $manager;
        $this->annotationManager = $annotationManager;

        $this->container = ApplicationContext::getContainer();
        $ConfigInterface = $this->container->get(\Hyperf\Contract\ConfigInterface::class);

        $this->configs = $ConfigInterface->get('local_cache', []);
        $pool = (string)$this->configs['redis.pool'] ?? 'default';

        $this->redis_cache_prefix = (string)$ConfigInterface->get('cache.default')['prefix'];
        $this->redis_version = (string)$this->configs['redis.version_key'];

        $this->Redis = $this->container->get(RedisFactory::class)->get($pool);
    }

    /**
     * 缓存版本+1
     * @param ProceedingJoinPoint $proceedingJoinPoint
     * @return mixed
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $arguments = $proceedingJoinPoint->arguments['keys'];
        if ($arguments['proceedingJoinPoint'] instanceof ProceedingJoinPoint) {
            $className = $arguments['proceedingJoinPoint']->className;
            $method = $arguments['proceedingJoinPoint']->methodName;
            $arguments = $arguments['proceedingJoinPoint']->arguments['keys'];
            [$key, $ttl, $group, $annotation] = $this->annotationManager->getCacheEvictValue($className, $method, $arguments);
            $key = $this->redis_cache_prefix . $key;
            //版本号+1
            $this->Redis->hIncrBy($this->redis_version, $key, 2);
        }
        return $proceedingJoinPoint->process();
    }

    /**
     * 重置版本缓存数据
     * @return void
     */
    public function reset()
    {
        //重置版本号
        $this->Redis->del($this->redis_version);
    }
}
