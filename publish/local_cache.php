<?php
return[
    //功能开关
    'enable' => true,

    //版本号存储Redis Pool
    'redis.pool' => 'default',
    //版本号存储key值
    'redis.version_key' => 'version_map',

    //自动清理时间周期(秒)
    'cycle_time' => 3600,
    //行记录的默认过期时间
    'default_ttl' => 3600,
];