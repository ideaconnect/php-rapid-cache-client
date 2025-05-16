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

    /** @var */
    private $redis;

    public function __construct(
        private string $host,
        private ?int $port = self::DEFAULT_REDIS_PORT,
        private ?string $prefix = null
    ) {

    }

    protected function reconnect()
    {
        if (false === $this->getRedis() || null === $this->getRedis() || $this->getRedis()->isConnected() !== true) {
            $redis = new Redis();
            $redis->connect($this->host, $this->port);
            if ($this->prefix) {
                $redis->setOption(Redis::OPT_PREFIX, $this->prefix);
            }
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            $this->redis = $redis;
        }

        return $this;
    }

    protected function getRedis() : ?Redis
    {
        return $this->redis;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagged(string $tag): Generator
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $members = $redis->sMembers($tag);
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

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $this->reconnect();
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
        $this->reconnect();

        if ($skipTagsRemoval !== true) {
            $this->untagKeyFromAllTags($key);
        }

        $redis = $this->getRedis();
        $redis->del($key);

        return $this;
    }

    private function untagKeyFromAllTags(string $key): self
    {
        $this->reconnect();
        $tags = $this->getTagged(self::TAGS_SET_NAME_PREFIX.$key);
        foreach ($tags as $tag) {
            $this->untag($key, $tag);
        }

        return $this;
    }

    public function untag(string $key, string $tag): self
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $type = $redis->type($tag);
        if ($type === Redis::REDIS_ZSET) {
            $redis->zRem($tag, $key);
        } else {
            $redis->sRem($tag, $key);
        }

        $redis->sRem( self::TAGS_SET_NAME_PREFIX.$key, $tag);

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

        $this->reconnect();
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
            if ($score !== null) {
                $redis->zAdd($tag, $score, $key);
            } else {
                $redis->sAdd($tag, $key);
            }

            $redis->sAdd(self::TAGS_SET_NAME_PREFIX.$key, $tag);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): self
    {
        $this->reconnect();
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

        $this->reconnect();
        $redis = $this->getRedis();
        $redis->rPush($queue, $value);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function pop(string $queue, int $range = 1): mixed
    {
        $this->reconnect();
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
        if (null === $this->get($key)) {
            throw new InvalidArgumentException(sprintf('Can\'t tag non-existing key "%s"', $key));
        }

        $this->reconnect();
        $redis = $this->getRedis();

        if ($score !== null) {
            $redis->zAdd($tag, $score, $key);
        } else {
            $redis->sAdd($tag, $key);
        }

        $redis->sAdd(self::TAGS_SET_NAME_PREFIX.$key, $tag);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function clearByTag(string $tag): CacheServiceInterface
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $members = $redis->sMembers($tag);
        foreach ($members as $member) {
            $this->delete($member);
        }

        return $this;
    }

    public function getCardinality(string $set, bool $sortedSet = false): int
    {
        $this->reconnect();
        $redis = $this->getRedis();
        if ($sortedSet) {
            return intval($redis->zCard($set));
        }

        return intval($redis->sCard($set));
    }

    public function getQueue(string $queue): array
    {
        $this->reconnect();
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
        $this->reconnect();
        $redis = $this->getRedis();
        return intval($redis->lLen($queue));
    }

    public function getSorted(string $set, int $count, int $offset = 0, bool $reversed = false): Generator
    {

        $command = $reversed ? 'ZREVRANGE' : 'ZRANGE';
        $this->reconnect();
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
        $this->reconnect();
        $redis = $this->getRedis();
        $redis->incrBy($key, $value);

        return $this;
    }

    public function decrease(string $key, int $value): self
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $redis->decrBy($key, $value);

        return $this;
    }

    public function addToSet(string $key, mixed $value): self
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $redis->sAdd($key, $value);
        return $this;
    }

    public function removeFromSet(string $key, mixed $value): self
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $redis->sRem($key, $value);
        return $this;
    }

    public function getSet(string $key): ?array
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $members = $redis->sMembers($key);
        if ($members === false) {
            return null;
        }

        return $members;
    }

    public function createSet(string $key, array $values): self
    {
        $this->reconnect();
        $redis = $this->getRedis();
        $redis->del($key); // delete existing set if any
        $redis->sAddArray($key, $values);
        return $this;
    }
}
