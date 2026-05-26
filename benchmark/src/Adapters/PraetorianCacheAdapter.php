<?php

declare(strict_types=1);

namespace Praetorian\CacheBenchmark\Adapters;

use Praetorian\CacheBenchmark\CacheAdapterInterface;
use Praetorian\CacheBenchmark\ComplexTestObject;
use IDCT\Cache\RapidCacheClient;

class PraetorianCacheAdapter implements CacheAdapterInterface
{
    private RapidCacheClient $cache;

    public function __construct(string $host = 'localhost', int $port = 6381)
    {
        $this->cache = new RapidCacheClient($host, $port, 'praetorian:');
    }

    public function set(string $key, ComplexTestObject $object): void
    {
        $this->cache->set($key, $object->toArray());
    }

    public function setWithTag(string $key, ComplexTestObject $object, string $tag): void
    {
        $this->cache->set($key, $object->toArray(), $tag);
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
        return 'Praetorian Cache (Hash-based with igbinary)';
    }

    public function supportsTagging(): bool
    {
        return true;
    }
}
