<?php

declare(strict_types=1);

namespace Praetorian\CacheService;

use Generator;
use InvalidArgumentException;
use Redis;
use function sprintf;

class RedisCacheService implements CacheServiceInterface
{
    const DEFAULT_REDIS_PORT = 6139;
    const MIN_TTL = 1;
    const MAX_TTL = 30 * 24 * 3600;

    private const TAGS_SET_NAME_PREFIX = 'TAGS:';

    /** @var Redis|null */
    private $redis;

    public function __construct(
        private string $host,
        private ?int $port = self::DEFAULT_REDIS_PORT,
        private ?string $prefix = null
    ) {

    }

    protected function reconnect()
    {
        $redis = new Redis();
        $redis->connect($this->host, $this->port);
        if ($this->prefix) {
            $redis->setOption(Redis::OPT_PREFIX, $this->prefix);
        }
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        $this->redis = $redis;

        return $this;
    }

    protected function getRedis() : Redis
    {
        if ($this->redis === null || !$this->redis->isConnected()) {
            $this->reconnect();
        }
        return $this->redis;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagged(string $tag): Generator
    {
        $redis = $this->getRedis();
        $tagHash = 'TAG:' . $tag;
        $members = $redis->hGetAll($tagHash);

        if (empty($members)) {
            yield from [];
            return;
        }

        $anyResults = false;

        foreach ($members as $member => $cachedValue) {
            // Check if the original key still exists (handles TTL expiration)
            if ($redis->exists($member)) {
                $anyResults = true;
                yield $member => $cachedValue;
            } else {
                // Remove expired key from tag hash
                $redis->hDel($tagHash, $member);
            }
        }

        if (!$anyResults) {
            yield from [];
            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $redis = $this->getRedis();
        $value = $redis->get($key);

        if (!$value) {
            return null;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key, bool $skipTagsRemoval = false): self
    {
        $redis = $this->getRedis();

        if ($skipTagsRemoval !== true) {
            $this->removeKeyFromTagHashes($key);
        }

        $redis->del($key);

        return $this;
    }

    private function removeKeyFromTagHashes(string $key): self
    {
        $redis = $this->getRedis();
        $reverseLookupKey = self::TAGS_SET_NAME_PREFIX . $key;
        $tags = $redis->sMembers($reverseLookupKey);

        foreach ($tags as $tag) {
            $tagHash = 'TAG:' . $tag;
            $redis->hDel($tagHash, $key);
        }

        // Clean up the reverse lookup set
        $redis->del($reverseLookupKey);

        return $this;
    }

    public function untag(string $key, string $tag): self
    {
        $redis = $this->getRedis();
        $tagHash = 'TAG:' . $tag;

        // Remove from tag hash
        $redis->hDel($tagHash, $key);

        // Remove from reverse lookup set
        $redis->sRem(self::TAGS_SET_NAME_PREFIX . $key, $tag);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?string $tag = null, ?int $ttl = null, ?int $score = null): self
    {
        if (null === $value) {
            throw new InvalidArgumentException('Can\'t set null item');
        }

        $redis = $this->getRedis();

        if (null !== $ttl) {
            if ($ttl < static::MIN_TTL || $ttl > static::MAX_TTL) {
                throw new InvalidArgumentException(
                    sprintf(
                        'TTL must be a value between (including) %d and %d. Provided: %d.',
                        static::MIN_TTL,
                        static::MAX_TTL,
                        $ttl
                    )
                );
            }

            $redis->setex($key, $ttl, $value);
        } else {
            $redis->set($key, $value);
        }

        if ($tag) {
            $tagHash = 'TAG:' . $tag;

            // Store the value in the tag hash for quick retrieval
            $redis->hSet($tagHash, $key, $value);

            // Keep reverse lookup for cleanup purposes
            $redis->sAdd(self::TAGS_SET_NAME_PREFIX.$key, $tag);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): self
    {
        $redis = $this->getRedis();
        $redis->flushAll();

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function enqueue(string $queue, mixed $value): self
    {
        if (null === $value) {
            throw new InvalidArgumentException('Can\'t enqueue null item');
        }

        $redis = $this->getRedis();
        $redis->rPush($queue, $value);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function pop(string $queue, int $range = 1): mixed
    {
        $redis = $this->getRedis();
        if ($range !== 1) {
            return $redis->lRange($queue, 0, $range);
        }

        $item = $redis->lPop($queue);
        return $item === false ? null : $item;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function tag(string $key, string $tag, ?int $score = null): self
    {
        $value = $this->get($key);
        if (null === $value) {
            throw new InvalidArgumentException(sprintf('Can\'t tag non-existing key "%s"', $key));
        }

        $redis = $this->getRedis();
        $tagHash = 'TAG:' . $tag;

        // Store the value in the tag hash
        $redis->hSet($tagHash, $key, $value);

        // Keep reverse lookup for cleanup purposes
        $redis->sAdd(self::TAGS_SET_NAME_PREFIX.$key, $tag);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function clearByTag(string $tag): CacheServiceInterface
    {
        $redis = $this->getRedis();
        $tagHash = 'TAG:' . $tag;
        $members = $redis->hKeys($tagHash);

        foreach ($members as $member) {
            $this->delete($member);
        }

        // Clean up the tag hash
        $redis->del($tagHash);

        return $this;
    }

    public function getCardinality(string $set, bool $sortedSet = false): int
    {
        $redis = $this->getRedis();

        // For tagged sets (prefixed with TAG:), use the hash length
        if (str_starts_with($set, 'TAG:')) {
            return intval($redis->hLen($set));
        }

        // For other sets, use the original logic
        if ($sortedSet) {
            return intval($redis->zCard($set));
        }

        return intval($redis->sCard($set));
    }

    public function getQueue(string $queue): array
    {
        $redis = $this->getRedis();

        $collected = [];
        $len = $this->getQueueLength($queue);
        for ($i = 0; $i < $len; $i++) {
            $item = $redis->rPopLPush($queue, $queue);
            $collected[] = $item;
        }

        return $collected;
    }

    public function getQueueLength(string $queue): int
    {
        $redis = $this->getRedis();
        return intval($redis->lLen($queue));
    }

    public function getSorted(string $set, int $count, int $offset = 0, bool $reversed = false): Generator
    {

        $command = $reversed ? 'ZREVRANGE' : 'ZRANGE';
        $redis = $this->getRedis();

        if ($reversed) {
            $members = $redis->zRevRange($set, $offset, $offset + $command);
        } else {
            $members = $redis->zRange($set, $offset, $offset + $command);
        }

        if (empty($members)) {
            yield from [];

            return;
        }

        $anyResults = false;

        foreach ($members as $member) {
            $memberValue = $this->get($member);
            if ($memberValue) {
                $anyResults = true;
                yield $member => $memberValue;
            } else {
                $this->delete($member); // fix for expired (TTL) elements which are still in the
            }
        }

        if (!$anyResults) {
            yield from [];

            return;
        }
    }

    public function increase(string $key, int $value): self
    {
        $redis = $this->getRedis();
        $redis->incrBy($key, $value);

        return $this;
    }

    public function decrease(string $key, int $value): self
    {
        $redis = $this->getRedis();
        $redis->decrBy($key, $value);

        return $this;
    }

    public function addToSet(string $key, mixed $value): self
    {
        $redis = $this->getRedis();
        $redis->sAdd($key, $value);
        return $this;
    }

    public function removeFromSet(string $key, mixed $value): self
    {
        $redis = $this->getRedis();
        $redis->sRem($key, $value);
        return $this;
    }

    public function getSet(string $key): ?array
    {
        $redis = $this->getRedis();
        $members = $redis->sMembers($key);
        if ($members === false) {
            return null;
        }

        return $members;
    }

    public function createSet(string $key, array $values): self
    {
        $redis = $this->getRedis();
        $redis->del($key); // delete existing set if any
        $redis->sAddArray($key, $values);
        return $this;
    }
}
