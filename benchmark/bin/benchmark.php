#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Praetorian\CacheBenchmark\Adapters\PraetorianCacheAdapter;
use Praetorian\CacheBenchmark\Adapters\SymfonyCacheIgbinaryAdapter;
use Praetorian\CacheBenchmark\Adapters\SymfonyCacheJsonAdapter;
use Praetorian\CacheBenchmark\BenchmarkRunner;

function showHelp(): void
{
    echo "Cache Benchmark Tool\n\n";
    echo "Usage: php bin/benchmark.php [options]\n\n";
    echo "Options:\n";
    echo "  --adapter=<name>    Run specific adapter (praetorian, symfony-igbinary, symfony-json, all)\n";
    echo "  --items=<count>     Number of items to benchmark (default: 100000)\n";
    echo "  --host=<host>       Redis host (default: localhost)\n";
    echo "  --port=<port>       Redis port (default: 6380)\n";
    echo "  --type=<type>       Benchmark type (basic, tagged, both) (default: basic)\n";
    echo "  --tags=<tags>       Comma-separated tags for tagged benchmark (default: tag1,tag2,tag3,tag4)\n";
    echo "  --help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/benchmark.php --adapter=all --items=50000 --type=basic\n";
    echo "  php bin/benchmark.php --adapter=all --items=100000 --type=tagged\n";
    echo "  php bin/benchmark.php --adapter=praetorian --type=both --items=10000\n";
    echo "  php bin/benchmark.php --adapter=all --type=tagged --tags=category1,category2,category3,category4\n";
}

function parseArguments(): array
{
    $options = getopt('', [
        'adapter::',    // Optional adapter (praetorian, symfony-igbinary, symfony-json, all)
        'items::',      // Optional number of items (default: 1000)
        'host::',       // Optional Redis host (default: localhost)
        'port::',       // Optional Redis port (default: 6381)
        'type::',       // Optional benchmark type (basic, tagged)
        'tags::'        // Optional comma-separated list of tags for tagged benchmarks
    ]);    $args = $_SERVER['argv'] ?? [];

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            $parts = explode('=', $arg, 2);
            $key = substr($parts[0], 2);

            if ($key === 'help') {
                $options['help'] = true;
            } elseif (isset($parts[1])) {
                $value = $parts[1];

                switch ($key) {
                    case 'items':
                    case 'port':
                        $options[$key] = (int)$value;
                        break;
                    default:
                        $options[$key] = $value;
                        break;
                }
            }
        }
    }

    return array_merge([
        'adapter' => 'all',
        'items' => 100000,
        'host' => 'localhost',
        'port' => 6380,
        'type' => 'basic',
        'tags' => 'tag1,tag2,tag3,tag4'
    ], $options);
}

function createAdapter(string $name, string $host, int $port): ?object
{
    switch ($name) {
        case 'praetorian':
            return new PraetorianCacheAdapter($host, $port);
        case 'symfony-igbinary':
            return new SymfonyCacheIgbinaryAdapter($host, $port);
        case 'symfony-json':
            return new SymfonyCacheJsonAdapter($host, $port);
        default:
            return null;
    }
}

function main(): void
{
    $options = parseArguments();

    if (isset($options['help']) && $options['help']) {
        showHelp();
        exit(0);
    }

    echo "=== Cache Benchmark Tool ===\n";
    echo "Items: {$options['items']}\n";
    echo "Redis: {$options['host']}:{$options['port']}\n\n";

    // Test Redis connection
    try {
        $redis = new Redis();
        $connected = $redis->connect($options['host'], $options['port']);
        if (!$connected) {
            throw new Exception("Could not connect to Redis");
        }
        $redis->ping();
        $redis->close();
        echo "✓ Redis connection successful\n\n";
    } catch (Exception $e) {
        echo "✗ Redis connection failed: {$e->getMessage()}\n";
        echo "Please ensure Redis/Valkey is running on {$options['host']}:{$options['port']}\n";
        exit(1);
    }

    $runner = new BenchmarkRunner();
    $tags = explode(',', $options['tags']);

    if ($options['type'] === 'tagged' || $options['type'] === 'both') {
        // Run tagged benchmarks
        if ($options['adapter'] === 'all') {
            $adapters = [
                createAdapter('praetorian', $options['host'], $options['port']),
                createAdapter('symfony-igbinary', $options['host'], $options['port']),
                createAdapter('symfony-json', $options['host'], $options['port'])
            ];

            $adapters = array_filter($adapters); // Remove null values
            $runner->runAllTaggedBenchmarks($adapters, $options['items'], $tags);
        } else {
            $adapter = createAdapter($options['adapter'], $options['host'], $options['port']);

            if ($adapter === null) {
                echo "Error: Unknown adapter '{$options['adapter']}'\n";
                echo "Available adapters: praetorian, symfony-igbinary, symfony-json, all\n";
                exit(1);
            }

            $runner->runTaggedBenchmark($adapter, $options['items'], $tags);
        }
    }

    if ($options['type'] === 'basic' || $options['type'] === 'both') {
        // Run basic benchmarks
        if ($options['adapter'] === 'all') {
            $adapters = [
                createAdapter('praetorian', $options['host'], $options['port']),
                createAdapter('symfony-igbinary', $options['host'], $options['port']),
                createAdapter('symfony-json', $options['host'], $options['port'])
            ];

            $adapters = array_filter($adapters); // Remove null values
            $runner->runAll($adapters, $options['items']);
        } else {
            $adapter = createAdapter($options['adapter'], $options['host'], $options['port']);

            if ($adapter === null) {
                echo "Error: Unknown adapter '{$options['adapter']}'\n";
                echo "Available adapters: praetorian, symfony-igbinary, symfony-json, all\n";
                exit(1);
            }

            $runner->run($adapter, $options['items']);
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
