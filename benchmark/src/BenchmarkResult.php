<?php

declare(strict_types=1);

namespace Praetorian\CacheBenchmark;

class BenchmarkResult
{
    public function __construct(
        public string $adapterName,
        public int $itemCount,
        public float $setTime,
        public float $getTime,
        public float $totalTime,
        public int $memoryUsage,
        public int $peakMemoryUsage,
        public array $details = []
    ) {}

    public function getSetThroughput(): float
    {
        return $this->itemCount / $this->setTime;
    }

    public function getGetThroughput(): float
    {
        return $this->itemCount / $this->getTime;
    }

    public function getTotalThroughput(): float
    {
        return ($this->itemCount * 2) / $this->totalTime; // set + get operations
    }

    public function toArray(): array
    {
        return [
            'adapter' => $this->adapterName,
            'items' => $this->itemCount,
            'set_time' => round($this->setTime, 4),
            'get_time' => round($this->getTime, 4),
            'total_time' => round($this->totalTime, 4),
            'set_throughput' => round($this->getSetThroughput(), 2),
            'get_throughput' => round($this->getGetThroughput(), 2),
            'total_throughput' => round($this->getTotalThroughput(), 2),
            'memory_usage_mb' => round($this->memoryUsage / 1024 / 1024, 2),
            'peak_memory_mb' => round($this->peakMemoryUsage / 1024 / 1024, 2),
            'details' => $this->details
        ];
    }
}
