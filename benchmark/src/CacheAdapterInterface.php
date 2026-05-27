<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

interface CacheAdapterInterface
{
    public function set(string $key, ComplexTestObject $object): void;
    public function setWithTag(string $key, ComplexTestObject $object, string $tag): void;
    public function get(string $key): ?ComplexTestObject;
    public function getTagged(string $tag): array;
    public function delete(string $key): void;
    public function clear(): void;
    public function getName(): string;
    public function getShortName(): string;
    public function supportsTagging(): bool;
}
