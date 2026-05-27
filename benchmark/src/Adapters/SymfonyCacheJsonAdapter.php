<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark\Adapters;

use IDCT\RapidCacheBenchmark\CacheAdapterInterface;
use IDCT\RapidCacheBenchmark\ComplexTestObject;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

class SymfonyCacheJsonAdapter implements CacheAdapterInterface
{
    private RedisTagAwareAdapter $cache;

    public function __construct(string $host = 'localhost', int $port = 6381)
    {
        $redis = new \Redis();
        $redis->connect($host, $port);
        $redis->setOption(\Redis::OPT_PREFIX, 'symfony_json:');

        // Use DefaultMarshaller with JSON (false parameter, which is default)
        $marshaller = new DefaultMarshaller(false);

        $this->cache = new RedisTagAwareAdapter($redis, '', 0, $marshaller);
    }

    public function set(string $key, ComplexTestObject $object): void
    {
        $item = $this->cache->getItem($key);
        $item->set($object->toArray());
        $this->cache->save($item);
    }

    public function setWithTag(string $key, ComplexTestObject $object, string $tag): void
    {
        $item = $this->cache->getItem($key);
        $item->set($object->toArray());
        $item->tag($tag);
        $this->cache->save($item);

        // Track tagged keys for retrieval (since Symfony doesn't provide getByTag)
        $tagMetadataItem = $this->cache->getItem("_tag_metadata_{$tag}");
        $taggedKeys = $tagMetadataItem->isHit() ? $tagMetadataItem->get() : [];
        $taggedKeys[] = $key;
        $tagMetadataItem->set(array_unique($taggedKeys));
        $tagMetadataItem->tag("_metadata");
        $this->cache->save($tagMetadataItem);
    }

    public function get(string $key): ?ComplexTestObject
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $data = $item->get();
        return ComplexTestObject::fromArray($data);
    }

    public function getTagged(string $tag): array
    {
        $results = [];
        // Use tag metadata to retrieve tagged items
        $tagMetadataItem = $this->cache->getItem("_tag_metadata_{$tag}");
        if ($tagMetadataItem->isHit()) {
            $taggedKeys = $tagMetadataItem->get();
            $items = $this->cache->getItems($taggedKeys);
            foreach ($items as $key => $item) {
                if ($item->isHit()) {
                    $results[$key] = ComplexTestObject::fromArray($item->get());
                }
            }
        }
        return $results;
    }

    public function delete(string $key): void
    {
        $this->cache->deleteItem($key);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function getName(): string
    {
        return 'Symfony Cache (RedisTagAware with JSON)';
    }

    public function getShortName(): string
    {
        return 'Symfony (JSON)';
    }

    public function supportsTagging(): bool
    {
        return true;
    }
}
