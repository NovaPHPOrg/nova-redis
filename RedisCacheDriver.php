<?php

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

declare(strict_types=1);

namespace nova\plugin\redis;

use nova\framework\cache\CacheException;
use nova\framework\cache\iCacheDriver;

use function nova\framework\config;

use Redis;

use RedisException;

class RedisCacheDriver implements iCacheDriver
{
    private Redis $redis;
    private string $prefix = "nova:";

    private RedisConfig $config;

    /**
     */
    public function __construct($shared = false)
    {
        $this->redis = new Redis();
        if (!$shared) {
            $this->prefix = md5(ROOT_PATH).":";
        }
        $this->config = new RedisConfig();
    }

    /**
     * @throws CacheException
     */
    private function ensureConnected(): void
    {
        $config = config('redis');
        try {
            // 使用持久连接
            $this->redis->pconnect($this->config->host, $this->config->port, $this->config->timeout);

            if (!empty($this->config->password)) {
                if (!$this->redis->auth($this->config->password)) {
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
    public function set($key, $value, $expire): bool
    {
        $this->ensureConnected();
        $options = $expire > 0 ? ["EX" => $expire] : [];
        $this->redis->set($this->prefix . $key, serialize($value), $options);
        return true;
    }

    /**
     * @throws RedisException|CacheException
     */
    public function delete($key): bool
    {
        $this->ensureConnected();
        $this->redis->del($this->prefix . $key);
        return true;
    }

    /**
     * @throws RedisException|CacheException
     */
    public function deleteKeyStartWith($key): bool
    {
        $this->ensureConnected();
        $this->removeKeyStart($this->prefix . $key);
        return true;
    }

    /**
     * @throws RedisException|CacheException
     */
    public function clear(): bool
    {
        $this->ensureConnected();
        $this->removeKeyStart($this->prefix);
        return true;
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

    public function gc(string $startKey, int $maxCount): bool
    {
        return true;
    }

    public function getAll(string $startKey): array
    {
        $this->ensureConnected();
        
        $pattern = $this->prefix . $startKey . '*';
        $cursor = null;
        $allKeys = [];
        
        // 第一步：收集所有匹配的键
        do {
            $keys = $this->redis->scan($cursor, $pattern);
            if ($keys !== false && !empty($keys)) {
                $allKeys = array_merge($allKeys, $keys);
            }
        } while ($cursor > 0);
        
        if (empty($allKeys)) {
            return [];
        }
        
        // 第二步：批量获取所有值（一次网络往返）
        $values = $this->redis->mget($allKeys);
        $result = [];
        
        foreach ($allKeys as $index => $fullKey) {
            $data = $values[$index];
            if ($data !== false) {
                // 移除前缀，返回逻辑键名（与文件缓存保持一致）
                $logicalKey = substr($fullKey, strlen($this->prefix));
                
                // 安全反序列化
                try {
                    $result[$logicalKey] = unserialize($data);
                } catch (\Throwable $e) {
                    // 损坏的数据直接跳过，不影响其他数据
                    continue;
                }
            }
        }
        
        return $result;
    }
    
}
