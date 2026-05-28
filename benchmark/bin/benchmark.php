#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use IDCT\Cache\HashRapidCacheClient;
use IDCT\Cache\RapidCacheClient;
use IDCT\RapidCacheBenchmark\BenchmarkRunner;
use IDCT\RapidCacheBenchmark\HtmlReportGenerator;
use IDCT\RapidCacheBenchmark\SvgChartGenerator;
use IDCT\RapidCacheBenchmark\SystemInfo;

function showHelp(): void
{
    echo "IDCT Rapid Cache — Feature Benchmark\n\n";
    echo "Profiles the throughput of RapidCache's own operations across BOTH clients:\n";
    echo "  - RapidCacheClient     (Core, Tagging, Counters)\n";
    echo "  - HashRapidCacheClient (Hash Core, Hash Tagging, Hash Counters)\n";
    echo "There is no cross-library comparison.\n\n";
    echo "Usage: php bin/benchmark.php [options]\n\n";
    echo "Options:\n";
    echo "  --items=<count>     Number of items per operation (default: 100000)\n";
    echo "  --host=<host>       Redis host (default: localhost)\n";
    echo "  --port=<port>       Redis port (default: 6381)\n";
    echo "  --tags=<count>      Number of tag groups for tagging ops (default: 4)\n";
    echo "  --counters=<count>  Number of distinct counters for counter ops (default: 1000)\n";
    echo "  --report=<path>     Write an HTML report (table + charts) to the given path\n";
    echo "  --chart=<path>      Write a static SVG chart (embeddable in Markdown) to the given path\n";
    echo "  --help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/benchmark.php --items=100000\n";
    echo "  php bin/benchmark.php --items=10000 --report=report.html --chart=benchmark.svg\n";
}

/**
 * @return array{items: int, host: string, port: int, tags: int, counters: int, report: ?string, chart: ?string, help: bool}
 */
function parseArguments(): array
{
    $defaults = [
        'items' => 100000,
        'host' => 'localhost',
        'port' => 6381,
        'tags' => 4,
        'counters' => 1000,
        'report' => null,
        'chart' => null,
        'help' => false,
    ];

    $options = $defaults;
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];

        if ($key === 'help') {
            $options['help'] = true;
        } elseif (array_key_exists($key, $defaults) && isset($parts[1])) {
            $options[$key] = match ($key) {
                'items', 'port', 'tags', 'counters' => (int) $parts[1],
                default => $parts[1],
            };
        }
    }

    return $options;
}

function main(): void
{
    $options = parseArguments();

    if ($options['help']) {
        showHelp();
        exit(0);
    }

    echo "=== IDCT Rapid Cache — Feature Benchmark ===\n";
    echo "Items: {$options['items']}\n";
    echo "Redis: {$options['host']}:{$options['port']}\n\n";

    try {
        $redis = new Redis();
        if (!$redis->connect($options['host'], $options['port'])) {
            throw new Exception('Could not connect to Redis');
        }
        $redis->ping();
        $redis->close();
        echo "✓ Redis connection successful\n\n";
    } catch (Exception $e) {
        echo "✗ Redis connection failed: {$e->getMessage()}\n";
        echo "Please ensure Redis/Valkey is running on {$options['host']}:{$options['port']}\n";
        exit(1);
    }

    $cache = new RapidCacheClient($options['host'], $options['port'], 'rapid-cache:');
    $hashCache = new HashRapidCacheClient($options['host'], $options['port'], 'rapid-cache:');

    $runner = new BenchmarkRunner();
    $results = $runner->run($cache, $options['items'], $options['tags'], $options['counters']);
    $results = array_merge(
        $results,
        $runner->runHash($hashCache, $options['items'], $options['tags'], $options['counters']),
    );

    if ($options['report'] !== null || $options['chart'] !== null) {
        $context = [
            'items' => $options['items'],
            'host' => $options['host'],
            'port' => $options['port'],
            'system' => SystemInfo::detect(),
            'generatedAt' => new DateTimeImmutable(),
        ];

        if ($options['report'] !== null) {
            (new HtmlReportGenerator())->generate($options['report'], $context, $results);
            echo "\n✓ HTML report written to {$options['report']}\n";
        }

        if ($options['chart'] !== null) {
            (new SvgChartGenerator())->generate($options['chart'], $context, $results);
            echo "\n✓ SVG chart written to {$options['chart']}\n";
        }
    }

    echo "\nBenchmark completed!\n";
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
