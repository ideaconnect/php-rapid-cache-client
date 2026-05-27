<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark\Adapters;

use IDCT\RapidCacheBenchmark\CacheAdapterInterface;
use IDCT\RapidCacheBenchmark\ComplexTestObject;
use IDCT\Cache\RapidCacheClient;

class RapidCacheAdapter implements CacheAdapterInterface
{
    private RapidCacheClient $cache;

    public function __construct(string $host = 'localhost', int $port = 6381)
    {
        $this->cache = new RapidCacheClient($host, $port, 'rapid-cache:');
    }

    public function set(string $key, ComplexTestObject $object): void
    {
        $this->cache->set($key, $object->toArray());
    }

    public function setWithTag(string $key, ComplexTestObject $object, string $tag): void
    {
        $this->cache->setTagged($key, $object->toArray(), $tag);
    }

    public function get(string $key): ?ComplexTestObject
    {
        $data = $this->cache->get($key);
        if ($data === null) {
            return null;
        }

        return ComplexTestObject::fromArray($data);
    }

    public function getTagged(string $tag): array
    {
        $results = [];
        foreach ($this->cache->getTagged($tag) as $key => $data) {
            $results[$key] = ComplexTestObject::fromArray($data);
        }
        return $results;
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function getName(): string
    {
        return 'IDCT Rapid Cache (Hash-based with igbinary)';
    }

    public function getShortName(): string
    {
        return 'Rapid Cache';
    }

    public function supportsTagging(): bool
    {
        return true;
    }
}
