<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

use IDCT\Cache\HashRapidCacheClient;
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

    /**
     * Profiles {@see HashRapidCacheClient}'s own operations.
     *
     * The value contract is different from {@see RapidCacheClient}: every
     * stored value must be a non-empty flat array of scalars, so this sweep
     * uses a fixed-shape record payload instead of {@see ComplexTestObject}.
     * Field-level operations (HGET/HSET/HINCRBY) exercise the per-field
     * surface that is unique to the hash client.
     *
     * Categories emitted: "Hash Core", "Hash Fields", "Hash Tagging",
     * "Hash Counters" - distinct from the string-client categories so both
     * sweeps can coexist in a single report.
     *
     * @return list<OperationResult>
     */
    public function runHash(
        HashRapidCacheClient $cache,
        int $itemCount = 100000,
        int $tagCount = 4,
        int $counterCount = 1000,
    ): array {
        $this->logger->info("Starting HashRapidCacheClient feature benchmark");
        $this->logger->info("Items: {$itemCount}, tags: {$tagCount}, counters: {$counterCount}");

        $this->logger->info("Generating flat payloads...");
        $payloads = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $payloads[$i] = [
                'id' => $i,
                'name' => "user_{$i}",
                'email' => "user_{$i}@example.com",
                'score' => $i * 7,
                'created_at' => '2026-01-01T00:00:00Z',
            ];
        }

        $tags = [];
        for ($t = 1; $t <= $tagCount; $t++) {
            $tags[] = "h_tag{$t}";
        }

        $cache->clear();
        $this->warmUpHash($cache);

        $results = [];

        // ---------------------------------------------------------- Hash Core
        $results[] = $this->measure('Hash Core', 'set', $itemCount, function () use ($cache, $payloads, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->set("h_{$i}", $payloads[$i]);
            }
        });

        $results[] = $this->measure('Hash Core', 'get', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->get("h_{$i}");
            }
        });

        $results[] = $this->measure('Hash Core', 'has', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->has("h_{$i}");
            }
        });

        $chunk = 1000;
        $results[] = $this->measure('Hash Core', 'setMultiple', $itemCount, function () use ($cache, $payloads, $itemCount, $chunk) {
            $buffer = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $buffer["hm_{$i}"] = $payloads[$i];
                if (count($buffer) >= $chunk) {
                    $cache->setMultiple($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $cache->setMultiple($buffer);
            }
        }, note: "batches of {$chunk}");

        $results[] = $this->measure('Hash Core', 'getMultiple', $itemCount, function () use ($cache, $itemCount, $chunk) {
            $keys = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $keys[] = "hm_{$i}";
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

        $results[] = $this->measure('Hash Core', 'deleteMultiple', $itemCount, function () use ($cache, $itemCount, $chunk) {
            $keys = [];
            for ($i = 1; $i <= $itemCount; $i++) {
                $keys[] = "hm_{$i}";
                if (count($keys) >= $chunk) {
                    $cache->deleteMultiple($keys);
                    $keys = [];
                }
            }
            if ($keys !== []) {
                $cache->deleteMultiple($keys);
            }
        }, note: "batches of {$chunk}");

        $results[] = $this->measure('Hash Core', 'delete', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->delete("h_{$i}");
            }
        });

        // -------------------------------------------------------- Hash Fields
        // Re-populate the same keys so per-field reads/writes have substrate.
        for ($i = 1; $i <= $itemCount; $i++) {
            $cache->set("h_{$i}", $payloads[$i]);
        }

        $results[] = $this->measure('Hash Fields', 'getField', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->getField("h_{$i}", 'email');
            }
        }, note: 'single HGET per key');

        $results[] = $this->measure('Hash Fields', 'setField', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->setField("h_{$i}", 'last_seen', '2026-05-28');
            }
        }, note: 'single HSET per key');

        $results[] = $this->measure('Hash Fields', 'hasField', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->hasField("h_{$i}", 'email');
            }
        });

        $results[] = $this->measure('Hash Fields', 'getFields', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->getFields("h_{$i}", ['id', 'name', 'email']);
            }
        }, note: 'HMGET of 3 fields per key');

        $results[] = $this->measure('Hash Fields', 'setFields', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->setFields("h_{$i}", ['score' => $i, 'updated_at' => '2026-05-28']);
            }
        }, note: 'HMSET of 2 fields per key');

        $results[] = $this->measure('Hash Fields', 'deleteField', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->deleteField("h_{$i}", 'last_seen');
            }
        });

        $results[] = $this->measure('Hash Fields', 'deleteFields', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->deleteFields("h_{$i}", ['updated_at', 'score']);
            }
        }, note: 'HDEL of 2 fields per key');

        // ------------------------------------------------------- Hash Tagging
        $cache->clear();

        $results[] = $this->measure('Hash Tagging', 'setTagged', $itemCount, function () use ($cache, $payloads, $itemCount, $tags, $tagCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $tag = $tags[($i - 1) % $tagCount];
                $cache->setTagged("ht_{$i}", $payloads[$i], $tag);
            }
        });

        $retrieved = 0;
        $results[] = $this->measure('Hash Tagging', 'getTagged', $itemCount, function () use ($cache, $tags, &$retrieved) {
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

        $results[] = $this->measure('Hash Tagging', 'tag', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->tag("ht_{$i}", 'extra');
            }
        }, note: 'second tag onto existing keys');

        $results[] = $this->measure('Hash Tagging', 'untag', $itemCount, function () use ($cache, $itemCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->untag("ht_{$i}", 'extra');
            }
        });

        $results[] = $this->measure('Hash Tagging', 'getTagCardinality', $tagCount, function () use ($cache, $tags) {
            foreach ($tags as $tag) {
                $cache->getTagCardinality($tag);
            }
        }, note: "{$tagCount} tags");

        $results[] = $this->measure('Hash Tagging', 'clearByTag', $itemCount, function () use ($cache, $tags) {
            foreach ($tags as $tag) {
                $cache->clearByTag($tag);
            }
        }, note: "{$tagCount} groups invalidated");

        // ------------------------------------------------------ Hash Counters
        $cache->clear();
        // HINCRBY needs a parent hash; create one counter key per group so the
        // ops mirror RapidCacheClient::increase/decrease one-shot semantics.
        for ($c = 0; $c < $counterCount; $c++) {
            $cache->set("hctr_{$c}", ['hits' => 0]);
        }

        $results[] = $this->measure('Hash Counters', 'incrementField', $itemCount, function () use ($cache, $itemCount, $counterCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->incrementField('hctr_' . ($i % $counterCount), 'hits', 1);
            }
        }, note: "{$counterCount} distinct counters, HINCRBY 'hits'");

        $results[] = $this->measure('Hash Counters', 'decrementField', $itemCount, function () use ($cache, $itemCount, $counterCount) {
            for ($i = 1; $i <= $itemCount; $i++) {
                $cache->decrementField('hctr_' . ($i % $counterCount), 'hits', 1);
            }
        }, note: "{$counterCount} distinct counters, HINCRBY 'hits' -1");

        $cache->clear();

        $this->logger->info('Hash benchmark complete: ' . count($results) . ' operations measured');

        return $results;
    }

    private function warmUpHash(HashRapidCacheClient $cache): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $cache->set("warmup_{$i}", ['v' => $i]);
            $cache->get("warmup_{$i}");
            $cache->delete("warmup_{$i}");
        }
    }
}
