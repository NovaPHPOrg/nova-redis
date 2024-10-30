<?php
namespace nova\plugin\redis;

use nova\framework\cache\CacheException;
use nova\framework\cache\iCacheDriver;
use Redis;
use RedisException;
use function nova\framework\config;

class RedisCacheDriver implements iCacheDriver
{
    private Redis $redis;
    private string $prefix = "nova:";

    /**
     */
    public function __construct($shared = false)
    {
        $this->redis = new Redis();
        if (!$shared) {
            $this->prefix = md5(ROOT_PATH).":";
        }
    }

    /**
     * @throws CacheException
     */
    private function ensureConnected(): void
    {
        $config = config('redis');
        try {
            // 使用持久连接
            $this->redis->pconnect($config['host'], $config['port'], $config['timeout'] ?? 0);

            if (!empty($config['password'])) {
                if (!$this->redis->auth($config['password'])) {
                    throw new CacheException("Redis auth failed");
                }
            }
        } catch (RedisException $e) {
            throw new CacheException("Redis connect failed: " . $e->getMessage());
        }
    }

    /**
     * @throws RedisException|CacheException
     */
    public function get($key, $default = null): mixed
    {
        $this->ensureConnected();
        $data = $this->redis->get($this->prefix . $key);
        return $data !== false ? unserialize($data) : $default;
    }

    /**
     * @throws RedisException|CacheException
     */
    public function set($key, $value, $expire): void
    {
        $this->ensureConnected();
        $options = $expire > 0 ? ["EX" => $expire] : [];
        $this->redis->set($this->prefix . $key, serialize($value), $options);
    }

    /**
     * @throws RedisException|CacheException
     */
    public function delete($key): void
    {
        $this->ensureConnected();
        $this->redis->del($this->prefix . $key);
    }

    /**
     * @throws RedisException|CacheException
     */
    public function deleteKeyStartWith($key): void
    {
        $this->ensureConnected();
        $this->removeKeyStart($this->prefix . $key);
    }

    /**
     * @throws RedisException|CacheException
     */
    public function clear(): void
    {
        $this->ensureConnected();
        $this->removeKeyStart($this->prefix);
    }

    /**
     * @throws RedisException|CacheException
     */
    private function removeKeyStart($prefix): void
    {
        $this->ensureConnected();
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $prefix . '*');
            if ($keys !== false) {
                foreach ($keys as $key) {
                    $this->redis->del($key);
                }
            }
        } while ($cursor > 0);
    }

    /**
     * @throws RedisException|CacheException
     */
    public function getTtl($key): int
    {
        $this->ensureConnected();
        return $this->redis->ttl($this->prefix . $key) ?: -1;
    }
}
