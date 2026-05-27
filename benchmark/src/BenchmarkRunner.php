<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

use IDCT\Cache\RapidCacheClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Profiles RapidCache against itself: it measures the throughput of each
 * operation the library offers, grouped into Core, Tagging and Counters.
 *
 * There is no second library to compare against - the numbers describe how many
 * operations of each kind RapidCache sustains per second on the host it runs on.
 */
class BenchmarkRunner
{
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('benchmark');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
    }

    /**
     * Runs the full feature sweep and returns one result per operation.
     *
     * @return list<OperationResult>
     */
    public function run(
        RapidCacheClient $cache,
        int $itemCount = 100000,
        int $tagCount = 4,
        int $counterCount = 1000,
    ): array {
        $this->logger->info("Starting RapidCache feature benchmark");
        $this->logger->info("Items: {$itemCount}, tags: {$tagCount}, counters: {$counterCount}");

        $this->logger->info("Generating payloads...");
        $payloads = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $payloads[$i] = ComplexTestObject::generateRandom($i)->toArray();
        }

        $tags = [];
        for ($t = 1; $t <= $tagCount; $t++) {
            $tags[] = "tag{$t}";
        }

        $cache->clear();
        $this->warmUp($cache);

        $results = [];

        // ---------------------------------------------------------------- Core
        $results[] = $this->measure('Core', 'set', $itemCount, function () use ($cache, $payloads, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->set("k_{$i}", $payloads[$i]);
            }
        });

        $results[] = $this->measure('Core', 'get', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->get("k_{$i}");
            }
        });

        $results[] = $this->measure('Core', 'has', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->has("k_{$i}");
            }
        });

        $chunk = 1000;
        $results[] = $this->measure('Core', 'setMultiple', $itemCount, function () use ($cache, $payloads, $itemCount, $chunk) {
            $buffer = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $buffer["m_{$i}"] = $payloads[$i];
                if (count($buffer) >= $chunk) {
                    $cache->setMultiple($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $cache->setMultiple($buffer);
            }
        }, note: "batches of {$chunk}");

        $results[] = $this->measure('Core', 'getMultiple', $itemCount, function () use ($cache, $itemCount, $chunk) {
            $keys = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $keys[] = "m_{$i}";
                if (count($keys) >= $chunk) {
                    foreach ($cache->getMultiple($keys) as $_) {
                    }
                    $keys = [];
                }
            }
            if ($keys !== []) {
                foreach ($cache->getMultiple($keys) as $_) {
                }
            }
        }, note: "batches of {$chunk}");

        $results[] = $this->measure('Core', 'deleteMultiple', $itemCount, function () use ($cache, $itemCount, $chunk) {
            $keys = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $keys[] = "m_{$i}";
                if (count($keys) >= $chunk) {
                    $cache->deleteMultiple($keys);
                    $keys = [];
                }
            }
            if ($keys !== []) {
                $cache->deleteMultiple($keys);
            }
        }, note: "batches of {$chunk}");

        $results[] = $this->measure('Core', 'delete', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->delete("k_{$i}");
            }
        });

        // ------------------------------------------------------------- Tagging
        $cache->clear();

        $results[] = $this->measure('Tagging', 'setTagged', $itemCount, function () use ($cache, $payloads, $itemCount, $tags, $tagCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $tag = $tags[($i - 1) % $tagCount];
                $cache->setTagged("t_{$i}", $payloads[$i], $tag);
            }
        });

        $retrieved = 0;
        $results[] = $this->measure('Tagging', 'getTagged', $itemCount, function () use ($cache, $tags, &$retrieved) {
            foreach ($tags as $tag) {
                foreach ($cache->getTagged($tag) as $_) {
                    $retrieved++;
                }
            }
        }, noteFn: function () use (&$retrieved, $itemCount) {
            return sprintf(
                '%s of %s retrieved (%.0f%%)',
                number_format($retrieved),
                number_format($itemCount),
                $itemCount > 0 ? $retrieved / $itemCount * 100 : 0,
            );
        });

        $results[] = $this->measure('Tagging', 'tag', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->tag("t_{$i}", 'extra');
            }
        }, note: 'second tag onto existing keys');

        $results[] = $this->measure('Tagging', 'untag', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->untag("t_{$i}", 'extra');
            }
        });

        $results[] = $this->measure('Tagging', 'getTagCardinality', $tagCount, function () use ($cache, $tags) {
            foreach ($tags as $tag) {
                $cache->getTagCardinality($tag);
            }
        }, note: "{$tagCount} tags");

        $results[] = $this->measure('Tagging', 'clearByTag', $itemCount, function () use ($cache, $tags) {
            foreach ($tags as $tag) {
                $cache->clearByTag($tag);
            }
        }, note: "{$tagCount} groups invalidated");

        // ------------------------------------------------------------ Counters
        $cache->clear();

        $results[] = $this->measure('Counters', 'increase', $itemCount, function () use ($cache, $itemCount, $counterCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->increase('ctr_' . ($i % $counterCount), 1);
            }
        }, note: "{$counterCount} distinct counters");

        $results[] = $this->measure('Counters', 'decrease', $itemCount, function () use ($cache, $itemCount, $counterCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->decrease('ctr_' . ($i % $counterCount), 1);
            }
        }, note: "{$counterCount} distinct counters");

        $cache->clear();

        $this->logger->info("Benchmark complete: " . count($results) . " operations measured");

        return $results;
    }

    /**
     * Times one operation batch and logs the result.
     *
     * @param callable():void      $fn      The batch to time.
     * @param string|null          $note    Static annotation for the result.
     * @param (callable():string)|null $noteFn Annotation computed after $fn runs
     *                                          (e.g. a retrieved-row count).
     */
    private function measure(
        string $category,
        string $operation,
        int $operations,
        callable $fn,
        ?string $note = null,
        ?callable $noteFn = null,
    ): OperationResult {
        $start = microtime(true);
        $fn();
        $seconds = microtime(true) - $start;

        if ($noteFn !== null) {
            $note = $noteFn();
        }

        $result = new OperationResult($category, $operation, $operations, $seconds, $note);

        $this->logger->info(sprintf(
            '%-10s %-18s %12s ops/sec  (%s ops in %.4fs)%s',
            $category,
            $operation,
            number_format($result->opsPerSec()),
            number_format($operations),
            $seconds,
            $note !== null ? "  [{$note}]" : '',
        ));

        return $result;
    }

    private function warmUp(RapidCacheClient $cache): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $cache->set("warmup_{$i}", $i);
            $cache->get("warmup_{$i}");
            $cache->delete("warmup_{$i}");
        }
    }
}
