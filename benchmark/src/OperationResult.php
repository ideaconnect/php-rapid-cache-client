<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Throughput of a single RapidCache operation measured over a batch of calls.
 *
 * This benchmark profiles RapidCache against itself (no cross-library
 * comparison), so each result is just "operation X sustained N ops in T
 * seconds".
 */
final class OperationResult
{
    public function __construct(
        public string $category,
        public string $operation,
        public int $operations,
        public float $seconds,
        public ?string $note = null,
    ) {}

    public function opsPerSec(): float
    {
        return $this->seconds > 0 ? $this->operations / $this->seconds : 0.0;
    }

    /** Average wall time per operation, in microseconds. */
    public function avgMicros(): float
    {
        return $this->operations > 0 ? ($this->seconds / $this->operations) * 1_000_000 : 0.0;
    }

    /**
     * @return array{category: string, operation: string, operations: int,
     *     seconds: float, ops_per_sec: float, avg_us: float, note: ?string}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'operation' => $this->operation,
            'operations' => $this->operations,
            'seconds' => round($this->seconds, 4),
            'ops_per_sec' => round($this->opsPerSec(), 2),
            'avg_us' => round($this->avgMicros(), 3),
            'note' => $this->note,
        ];
    }
}
