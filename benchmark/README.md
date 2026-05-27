# Redis Cache Performance Benchmark

This comprehensive benchmark compares the performance of different Redis cache implementations, with special focus on **tagged cache operations** that demonstrate the superiority of hash-based caching strategies.

## Overview

The benchmark tests three cache implementations:

1. **IDCT Rapid Cache (Hash-based with igbinary)** - Our optimized implementation using Redis hashes for tagged operations
2. **Symfony Cache (RedisTagAware with igbinary)** - Symfony's tag-aware Redis adapter with DefaultMarshaller(true) for igbinary serialization
3. **Symfony Cache (RedisTagAware with JSON)** - Symfony's tag-aware Redis adapter with DefaultMarshaller(false) for JSON serialization

## Benchmark Types

### Basic Benchmarks
Tests standard cache operations (SET/GET) without tagging functionality.

### Tagged Benchmarks ⭐
Tests tagged cache operations where items are stored with tags and can be retrieved by tag. This demonstrates the key advantage of the hash-based approach:

- **Hash-based approach**: Uses `hSet("TAG:$tag", $key, $value)` for direct storage and `hGetAll("TAG:$tag")` for retrieval
- **Standard approach**: Uses sets to track tagged keys, requiring N+1 queries (one to get keys, then N to get values)

## Quick Start

### Run Benchmarks via Make
```bash
# Run quick benchmarks (10,000 items, basic + tagged)
make benchmark-quick

# Run full benchmarks (100,000 items, basic + tagged)
make benchmark

# Run only tagged benchmarks (100,000 items)
make benchmark-tagged

# Run only basic benchmarks (100,000 items)
make benchmark-basic
```

### Manual Execution
```bash
cd benchmark

# Basic benchmark with all adapters
php bin/benchmark.php --adapter=all --items=100000 --type=basic

# Tagged benchmark with all adapters
php bin/benchmark.php --adapter=all --items=100000 --type=tagged

# Both benchmarks with specific adapter
php bin/benchmark.php --adapter=rapid-cache --items=50000 --type=both

# Custom tags for tagged benchmark
php bin/benchmark.php --type=tagged --tags=category1,category2,category3,category4

# Same run, plus a self-contained HTML report (table + bar chart per section)
php bin/benchmark.php --adapter=all --type=both --items=10000 --report=report.html

# Same run, plus a static SVG chart that embeds directly in Markdown/README
php bin/benchmark.php --adapter=all --type=both --items=10000 --chart=benchmark.svg
```

### Available Options
- `--adapter`: rapid-cache, symfony-igbinary, symfony-json, all (default: all)
- `--items`: Number of items to benchmark (default: 100000)
- `--type`: basic, tagged, both (default: basic)
- `--tags`: Comma-separated tags for tagged benchmarks (default: tag1,tag2,tag3,tag4)
- `--host`: Redis host (default: localhost)
- `--port`: Redis port (default: 6380)
- `--report`: Optional path; writes a self-contained HTML report (table + Chart.js bar chart per section) to the given file
- `--chart`: Optional path; writes a static SVG bar chart (no JavaScript — embeds in Markdown/README) to the given file
- `--help`: Show help message

## Performance Results

### 🏆 Tagged Benchmark Results (100,000 items)

| Implementation | SET ops/sec | GET ops/sec | Total ops/sec | Speed Improvement |
|---|---|---|---|---|
| **IDCT Rapid Cache (Hash-based)** | 9,871 | 111,643 | 36,461 | **11.35x faster** |
| Symfony Cache (RedisTagAware + JSON) | 649 | 288,914 | 3,215 | - |
| Symfony Cache (RedisTagAware + igbinary) | 648 | 309,507 | 3,212 | - |

### Key Insights

1. **Tagged SET Performance**: Hash-based approach is **15.2x faster** than Symfony RedisTagAware for tagged SET operations
2. **Tagged GET Performance**: Hash-based approach provides excellent retrieval performance with single queries
3. **Overall Performance**: **11.35x faster** than the slowest implementation for complete tagged operations
4. **Memory Efficiency**: Comparable memory usage across all implementations

## Architecture Comparison

### Hash-based Implementation (IDCT Rapid Cache)
```redis
# Storage: Direct value storage in tag hashes
hSet("TAG:tag1", "key1", "serialized_value1")
hSet("TAG:tag1", "key2", "serialized_value2")

# Retrieval: Single query gets all tagged items
hGetAll("TAG:tag1") → {"key1": "value1", "key2": "value2"}
```

### Set-based Implementation (Symfony RedisTagAware)
```redis
# Storage: Symfony's RedisTagAware with tag metadata tracking
# Uses RedisTagAwareAdapter with DefaultMarshaller
set("cache_key", "serialized_value")
set("tag_metadata", ["key1", "key2"])  # Track tagged keys
# Plus internal Symfony tag structures

# Retrieval: Multiple queries to get tagged items
get("tag_metadata") → ["key1", "key2"]
mget(["key1", "key2"]) → ["value1", "value2"]
```

## Project Structure

```
benchmark/
├── README.md                 # This documentation
├── composer.json            # Dependencies
├── bin/
│   └── benchmark.php        # Main benchmark CLI script
├── src/
│   ├── BenchmarkRunner.php         # Core benchmark logic
│   ├── BenchmarkResult.php         # Basic benchmark results
│   ├── TaggedBenchmarkResult.php   # Tagged benchmark results
│   ├── HtmlReportGenerator.php     # Renders the --report HTML output
│   ├── SvgChartGenerator.php       # Renders the --chart static SVG output
│   ├── CacheAdapterInterface.php   # Common interface
│   └── Adapters/
│       ├── RapidCacheAdapter.php           # Hash-based implementation
│       ├── SymfonyCacheIgbinaryAdapter.php # Symfony with igbinary
│       └── SymfonyCacheJsonAdapter.php     # Symfony with JSON
└── vendor/                  # Composer dependencies
```

## Prerequisites

- Docker and Docker Compose
- PHP 8.1+ with Redis and igbinary extensions
- Composer for dependency management

## Installation

```bash
cd benchmark
composer install
```

Ensure Redis/Valkey is running on the specified host and port (default: localhost:6380).

## Benchmark Metrics

### Performance Metrics
- **SET Throughput**: Operations per second for write operations
- **GET Throughput**: Operations per second for read operations
- **Total Throughput**: Combined operations per second
- **Average Latency**: Per-operation time in milliseconds

### Resource Metrics
- **Memory Usage**: Memory consumed during benchmark
- **Peak Memory**: Maximum memory usage
- **Retrieval Accuracy**: Percentage of successful cache retrievals (tagged benchmarks)
- **Hit Rate**: Percentage of successful cache retrievals (basic benchmarks)

### Tagged Benchmark Specific
- **Tag-specific Results**: Per-tag retrieval performance
- **Tagged SET Time**: Time to store items with tags
- **Tagged GET Time**: Time to retrieve all items by tags

## Test Object Structure

The benchmark uses `ComplexTestObject` with realistic nested data:

```php
ComplexTestObject {
    id: int
    name: string
    email: string
    createdAt: DateTime
    metadata: array [
        source: string
        version: string
        platform: string
        session_data: array [...]
    ]
    address: Address { street, city, state, zipCode, country, coordinates }
    tags: array
    description: string|null
    isActive: bool
    score: float
    preferences: array [...]
}
```

## Troubleshooting

### Redis Connection Issues
```bash
# Test connection to Valkey
make test-connection

# Check if Valkey is running
docker compose ps

# View logs
docker compose logs valkey
```

### Memory Issues
For large benchmarks (>100k items), ensure sufficient system memory:
```bash
# Run smaller benchmark
php bin/benchmark.php --adapter=all --items=50000 --type=tagged
```

### Performance Optimization
- Ensure no other Redis instances are running on the same port (6380)
- Close unnecessary applications to free system resources
- Use SSD storage for better I/O performance

## Sample Output

### Tagged Benchmark Results
```
=== TAGGED BENCHMARK SUMMARY ===
Adapter                                  | SET ops/sec     | GET ops/sec     | Total ops/sec   | Memory MB  | Accuracy %
-----------------------------------------------------------------------------------------------------------------------------
IDCT Rapid Cache (Hash-based with igbinary) | 9,871           | 111,643         | 36,461          | 86.52      | 100
Symfony Cache (RedisTagAware with JSON)  | 649             | 288,914         | 3,215           | 89.99      | 100
Symfony Cache (RedisTagAware with igbinary) | 648             | 309,507         | 3,212           | 88.44      | 100

=== TAGGED PERFORMANCE COMPARISON ===
Fastest: IDCT Rapid Cache (Hash-based with igbinary)
Slowest: Symfony Cache (RedisTagAware with igbinary)
Speed improvement: 11.35x faster

Per-tag performance (fastest adapter):
  tag1: 28075.13 ops/sec
  tag2: 27777.72 ops/sec
  tag3: 28635.48 ops/sec
  tag4: 27207.18 ops/sec
```

### Basic Benchmark Results
```
=== BENCHMARK SUMMARY ===
Adapter                                  | SET ops/sec  | GET ops/sec  | Total ops/sec | Memory MB
----------------------------------------------------------------------------------------------------
IDCT Rapid Cache (Hash-based with igbinary) | 26,112       | 30,119       | 27,973       | 0.54
Symfony Cache (RedisTagAware with JSON)  | 12,371       | 25,015       | 16,555       | 0.05
Symfony Cache (RedisTagAware with igbinary) | 12,270       | 23,641       | 16,156       | 0.05

=== PERFORMANCE COMPARISON ===
Fastest: IDCT Rapid Cache (Hash-based with igbinary)
Slowest: Symfony Cache (RedisTagAware with igbinary)
Speed improvement: 1.73x faster
```
