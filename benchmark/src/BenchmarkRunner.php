<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class BenchmarkRunner
{
    private Logger $logger;
    private array $results = [];

    public function __construct()
    {
        $this->logger = new Logger('benchmark');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
    }

    public function run(CacheAdapterInterface $adapter, int $itemCount = 100000): BenchmarkResult
    {
        $this->logger->info("Starting benchmark for: {$adapter->getName()}");
        $this->logger->info("Item count: {$itemCount}");

        // Clear cache before starting
        $adapter->clear();

        // Generate test data
        $this->logger->info("Generating test data...");
        $objects = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $objects[$i] = ComplexTestObject::generateRandom($i);
        }

        // Warm up
        $this->logger->info("Warming up...");
        for ($i = 1; $i <= min(100, $itemCount); $i++) {
            $adapter->set("warmup_{$i}", $objects[$i]);
            $adapter->get("warmup_{$i}");
        }
        $adapter->clear();

        // Memory tracking
        $startMemory = memory_get_usage();

        // Benchmark SET operations
        $this->logger->info("Benchmarking SET operations...");
        $setStartTime = microtime(true);

        for ($i = 1; $i <= $itemCount; $i++) {
            $adapter->set("benchmark_{$i}", $objects[$i]);

            if ($i % 10000 === 0) {
                $progress = ($i / $itemCount) * 100;
                $this->logger->info(sprintf("SET Progress: %.1f%% (%d/%d)", $progress, $i, $itemCount));
            }
        }

        $setEndTime = microtime(true);
        $setTime = $setEndTime - $setStartTime;

        // Benchmark GET operations
        $this->logger->info("Benchmarking GET operations...");
        $getStartTime = microtime(true);
        $hitCount = 0;

        for ($i = 1; $i <= $itemCount; $i++) {
            $result = $adapter->get("benchmark_{$i}");
            if ($result !== null) {
                $hitCount++;
            }

            if ($i % 10000 === 0) {
                $progress = ($i / $itemCount) * 100;
                $this->logger->info(sprintf("GET Progress: %.1f%% (%d/%d)", $progress, $i, $itemCount));
            }
        }

        $getEndTime = microtime(true);
        $getTime = $getEndTime - $getStartTime;

        // Memory usage
        $endMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        $totalTime = $setTime + $getTime;

        $result = new BenchmarkResult(
            adapterName: $adapter->getShortName(),
            itemCount: $itemCount,
            setTime: $setTime,
            getTime: $getTime,
            totalTime: $totalTime,
            memoryUsage: $endMemory - $startMemory,
            peakMemoryUsage: $peakMemory,
            details: [
                'hit_count' => $hitCount,
                'hit_rate' => ($hitCount / $itemCount) * 100,
                'avg_set_time_ms' => ($setTime / $itemCount) * 1000,
                'avg_get_time_ms' => ($getTime / $itemCount) * 1000,
            ]
        );

        $this->results[] = $result;
        $this->logResults($result);

        // Cleanup
        $adapter->clear();

        return $result;
    }

    public function runAll(array $adapters, int $itemCount = 100000): array
    {
        $results = [];

        foreach ($adapters as $adapter) {
            $results[] = $this->run($adapter, $itemCount);

            // Give some time between benchmarks
            sleep(2);
        }

        $this->generateSummaryReport($results);

        return $results;
    }

    private function logResults(BenchmarkResult $result): void
    {
        $data = $result->toArray();

        $this->logger->info("=== BENCHMARK RESULTS ===");
        $this->logger->info("Adapter: {$data['adapter']}");
        $this->logger->info("Items: {$data['items']}");
        $this->logger->info("SET Time: {$data['set_time']}s ({$data['set_throughput']} ops/sec)");
        $this->logger->info("GET Time: {$data['get_time']}s ({$data['get_throughput']} ops/sec)");
        $this->logger->info("Total Time: {$data['total_time']}s ({$data['total_throughput']} ops/sec)");
        $this->logger->info("Memory Usage: {$data['memory_usage_mb']} MB");
        $this->logger->info("Peak Memory: {$data['peak_memory_mb']} MB");
        $this->logger->info("Hit Rate: {$data['details']['hit_rate']}%");
        $this->logger->info("Avg SET Time: " . round($data['details']['avg_set_time_ms'], 4) . "ms");
        $this->logger->info("Avg GET Time: " . round($data['details']['avg_get_time_ms'], 4) . "ms");
        $this->logger->info("========================");
    }

    private function generateSummaryReport(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $this->logger->info("\n=== BENCHMARK SUMMARY ===");

        // Sort by total throughput (descending)
        usort($results, fn(BenchmarkResult $a, BenchmarkResult $b) =>
            $b->getTotalThroughput() <=> $a->getTotalThroughput());

        $this->logger->info(sprintf(
            "%-40s | %-12s | %-12s | %-12s | %-10s",
            "Adapter", "SET ops/sec", "GET ops/sec", "Total ops/sec", "Memory MB"
        ));
        $this->logger->info(str_repeat("-", 100));

        foreach ($results as $result) {
            $data = $result->toArray();
            $this->logger->info(sprintf(
                "%-40s | %-12s | %-12s | %-12s | %-10s",
                $data['adapter'],
                number_format($data['set_throughput'], 0),
                number_format($data['get_throughput'], 0),
                number_format($data['total_throughput'], 0),
                $data['memory_usage_mb']
            ));
        }

        // Performance comparison
        if (count($results) > 1) {
            $baseline = $results[count($results) - 1]; // Slowest
            $fastest = $results[0]; // Fastest

            $speedup = $fastest->getTotalThroughput() / $baseline->getTotalThroughput();

            $this->logger->info("\n=== PERFORMANCE COMPARISON ===");
            $this->logger->info("Fastest: {$fastest->adapterName}");
            $this->logger->info("Slowest: {$baseline->adapterName}");
            $this->logger->info(sprintf("Speed improvement: %.2fx faster", $speedup));
        }

        $this->logger->info("=========================");
    }

    public function runTaggedBenchmark(CacheAdapterInterface $adapter, int $itemCount = 100000, array $tags = ['tag1', 'tag2', 'tag3', 'tag4']): TaggedBenchmarkResult
    {
        $this->logger->info("Starting TAGGED benchmark for: {$adapter->getName()}");
        $this->logger->info("Item count: {$itemCount}");
        $this->logger->info("Tags: " . implode(', ', $tags));

        if (!$adapter->supportsTagging()) {
            throw new \Exception("Adapter {$adapter->getName()} does not support tagging");
        }

        // Clear cache before starting
        $adapter->clear();

        // Generate test data
        $this->logger->info("Generating test data...");
        $objects = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $objects[$i] = ComplexTestObject::generateRandom($i);
        }

        // Warm up
        $this->logger->info("Warming up...");
        for ($i = 1; $i <= min(100, $itemCount); $i++) {
            $tag = $tags[($i - 1) % count($tags)];
            $adapter->setWithTag("warmup_{$i}", $objects[$i], $tag);
        }
        $adapter->clear();

        // Memory tracking
        $startMemory = memory_get_usage();

        // Benchmark TAGGED SET operations
        $this->logger->info("Benchmarking TAGGED SET operations...");
        $setStartTime = microtime(true);

        for ($i = 1; $i <= $itemCount; $i++) {
            $tag = $tags[($i - 1) % count($tags)];
            $adapter->setWithTag("tagged_{$i}", $objects[$i], $tag);

            if ($i % 10000 === 0) {
                $progress = ($i / $itemCount) * 100;
                $this->logger->info(sprintf("TAGGED SET Progress: %.1f%% (%d/%d)", $progress, $i, $itemCount));
            }
        }

        $setEndTime = microtime(true);
        $setTime = $setEndTime - $setStartTime;

        // Benchmark TAGGED GET operations
        $this->logger->info("Benchmarking TAGGED GET operations...");
        $getStartTime = microtime(true);
        $tagResults = [];
        $totalRetrieved = 0;

        foreach ($tags as $tag) {
            $this->logger->info("Retrieving items for tag: {$tag}");
            $tagStartTime = microtime(true);

            $results = $adapter->getTagged($tag);
            $retrievedCount = count($results);
            $totalRetrieved += $retrievedCount;

            $tagEndTime = microtime(true);
            $tagTime = $tagEndTime - $tagStartTime;

            $tagResults[$tag] = [
                'count' => $retrievedCount,
                'time' => $tagTime,
                'throughput' => $retrievedCount > 0 ? $retrievedCount / $tagTime : 0
            ];

            $this->logger->info(sprintf("Tag %s: %d items in %.4fs (%.2f ops/sec)",
                $tag, $retrievedCount, $tagTime, $tagResults[$tag]['throughput']));
        }

        $getEndTime = microtime(true);
        $getTime = $getEndTime - $getStartTime;

        // Memory usage
        $endMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        $totalTime = $setTime + $getTime;
        $expectedItemsPerTag = $itemCount / count($tags);

        $result = new TaggedBenchmarkResult(
            adapterName: $adapter->getShortName(),
            itemCount: $itemCount,
            tagCount: count($tags),
            setTime: $setTime,
            getTime: $getTime,
            totalTime: $totalTime,
            memoryUsage: $endMemory - $startMemory,
            peakMemoryUsage: $peakMemory,
            tagResults: $tagResults,
            details: [
                'total_retrieved' => $totalRetrieved,
                'expected_per_tag' => $expectedItemsPerTag,
                'retrieval_accuracy' => ($totalRetrieved / $itemCount) * 100,
                'avg_set_time_ms' => ($setTime / $itemCount) * 1000,
                'avg_tag_get_time_ms' => ($getTime / count($tags)) * 1000,
            ]
        );

        $this->logTaggedResults($result);

        // Cleanup
        $adapter->clear();

        return $result;
    }

    public function runAllTaggedBenchmarks(array $adapters, int $itemCount = 100000, array $tags = ['tag1', 'tag2', 'tag3', 'tag4']): array
    {
        $results = [];

        foreach ($adapters as $adapter) {
            if ($adapter->supportsTagging()) {
                $results[] = $this->runTaggedBenchmark($adapter, $itemCount, $tags);
                // Give some time between benchmarks
                sleep(2);
            } else {
                $this->logger->warning("Skipping {$adapter->getName()} - does not support tagging");
            }
        }

        $this->generateTaggedSummaryReport($results);

        return $results;
    }

    private function logTaggedResults(TaggedBenchmarkResult $result): void
    {
        $data = $result->toArray();

        $this->logger->info("=== TAGGED BENCHMARK RESULTS ===");
        $this->logger->info("Adapter: {$data['adapter']}");
        $this->logger->info("Items: {$data['items']} (across {$data['tags']} tags)");
        $this->logger->info("TAGGED SET Time: {$data['set_time']}s ({$data['set_throughput']} ops/sec)");
        $this->logger->info("TAGGED GET Time: {$data['get_time']}s ({$data['get_throughput']} ops/sec)");
        $this->logger->info("Total Time: {$data['total_time']}s ({$data['total_throughput']} ops/sec)");
        $this->logger->info("Memory Usage: {$data['memory_usage_mb']} MB");
        $this->logger->info("Peak Memory: {$data['peak_memory_mb']} MB");
        $this->logger->info("Retrieval Accuracy: {$data['details']['retrieval_accuracy']}%");
        $this->logger->info("Avg TAGGED SET Time: " . round($data['details']['avg_set_time_ms'], 4) . "ms");
        $this->logger->info("Avg TAG GET Time: " . round($data['details']['avg_tag_get_time_ms'], 4) . "ms");

        $this->logger->info("--- Tag-specific Results ---");
        foreach ($data['tag_results'] as $tag => $tagData) {
            $this->logger->info(sprintf("  %s: %d items in %.4fs (%.2f ops/sec)",
                $tag, $tagData['count'], $tagData['time'], $tagData['throughput']));
        }

        $this->logger->info("==============================");
    }

    private function generateTaggedSummaryReport(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $this->logger->info("\n=== TAGGED BENCHMARK SUMMARY ===");

        // Sort by total throughput (descending)
        usort($results, fn(TaggedBenchmarkResult $a, TaggedBenchmarkResult $b) =>
            $b->getTotalThroughput() <=> $a->getTotalThroughput());

        $this->logger->info(sprintf(
            "%-40s | %-15s | %-15s | %-15s | %-10s | %-12s",
            "Adapter", "SET ops/sec", "GET ops/sec", "Total ops/sec", "Memory MB", "Accuracy %"
        ));
        $this->logger->info(str_repeat("-", 125));

        foreach ($results as $result) {
            $data = $result->toArray();
            $this->logger->info(sprintf(
                "%-40s | %-15s | %-15s | %-15s | %-10s | %-12s",
                $data['adapter'],
                number_format($data['set_throughput'], 0),
                number_format($data['get_throughput'], 0),
                number_format($data['total_throughput'], 0),
                $data['memory_usage_mb'],
                round($data['details']['retrieval_accuracy'], 1)
            ));
        }

        // Performance comparison
        if (count($results) > 1) {
            $baseline = $results[count($results) - 1]; // Slowest
            $fastest = $results[0]; // Fastest

            $speedup = $fastest->getTotalThroughput() / $baseline->getTotalThroughput();

            $this->logger->info("\n=== TAGGED PERFORMANCE COMPARISON ===");
            $this->logger->info("Fastest: {$fastest->adapterName}");
            $this->logger->info("Slowest: {$baseline->adapterName}");
            $this->logger->info(sprintf("Speed improvement: %.2fx faster", $speedup));

            // Show per-tag performance for fastest
            $fastestData = $fastest->toArray();
            $this->logger->info("\nPer-tag performance (fastest adapter):");
            foreach ($fastestData['tag_results'] as $tag => $tagData) {
                $this->logger->info(sprintf("  %s: %.2f ops/sec", $tag, $tagData['throughput']));
            }
        }

        $this->logger->info("===================================");
    }
}
