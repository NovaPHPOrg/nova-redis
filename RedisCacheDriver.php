<?php

namespace nova\plugin\redis;

use nova\framework\cache\CacheException;
use nova\framework\cache\iCacheDriver;
use Redis;
use RedisException;

class RedisCacheDriver implements iCacheDriver
{
    private Redis $redis;

    private string $prefix = "nova_cache_";

    /**
     * @throws CacheException
     */
    public function __construct($shared = false)
    {
        //连接redis
        $this->redis = new Redis();
        // 读取redis账号密码
        $config = $GLOBALS['__nova_app_config__']['redis'];
        // 连接redis

        try {
            $this->redis->connect($config['host'], $config['port']);
            if (!empty($config['password'])) {
                if (!$this->redis->auth($config['password'])){
                    throw new CacheException("Redis auth failed");
                }
            }
        } catch (RedisException $e) {
            throw new CacheException("Redis connect failed");
        }



        if (!$shared){
            $this->prefix = md5(ROOT_PATH);
        }
    }

    /**
     * @throws RedisException
     */
    public function get($key, $default = null): mixed
    {
        $data =  $this->redis->get($this->prefix . $key);
        if (!$data)return $default;
        return unserialize($data);
    }

    /**
     * @throws RedisException
     */
    public function set($key, $value, $expire): void
    {
        $options = null;
        if ($expire > 0){
            $options = [
                "EXAT" => time() + $expire
            ];
        }

        $this->redis->set($this->prefix . $key, serialize($value), $options);
    }

    /**
     * @throws RedisException
     */
    public function delete($key): void
    {
       $this->redis->del($this->prefix . $key);
    }

    /**
     * @throws RedisException
     */
    public function deleteKeyStartWith($key): void
    {
        $this->removeKeyStart($this->prefix . $key);
    }

    /**
     * @throws RedisException
     */
    public function clear(): void
    {
        $this->removeKeyStart($this->prefix);
    }

    /**
     * @throws RedisException
     */
    private function removeKeyStart($prefix): void
    {
        $keys = $this->redis->keys($prefix . '*');

        if (!empty($keys)) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        }
    }
}