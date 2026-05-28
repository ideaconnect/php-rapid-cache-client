# IDCT Rapid Cache — Feature Benchmark

This benchmark profiles **RapidCache against itself**: it measures the
throughput of the library's own operations for **both clients** —
`RapidCacheClient` (string-serialised values) and `HashRapidCacheClient`
(native Redis HASH storage with field-level access). There is no
cross-library comparison — the numbers describe how many operations of each
kind each client sustains per second on the host it runs on.

> **Absolute numbers depend entirely on the runner hardware and the network path
> to Redis.** Single-key operations are dominated by the round-trip time to the
> server, so a local socket and a Dockerised Valkey over WSL2 will produce very
> different figures. Read the results as *relative* — bulk/pipelined operations
> vs. single round-trips — not as guaranteed throughput.

## What is measured

`RapidCacheClient` (igbinary-serialised PHP values as Redis STRINGs):

| Category | Operations |
|---|---|
| **Core** | `set`, `get`, `has`, `setMultiple`, `getMultiple`, `deleteMultiple`, `delete` |
| **Tagging** | `setTagged`, `getTagged`, `tag`, `untag`, `getTagCardinality`, `clearByTag` |
| **Counters** | `increase`, `decrease` |

`HashRapidCacheClient` (flat associative array of scalars as a Redis HASH):

| Category | Operations |
|---|---|
| **Hash Core** | `set`, `get`, `has`, `setMultiple`, `getMultiple`, `deleteMultiple`, `delete` |
| **Hash Fields** | `getField`, `setField`, `hasField`, `getFields`, `setFields`, `deleteField`, `deleteFields` |
| **Hash Tagging** | `setTagged`, `getTagged`, `tag`, `untag`, `getTagCardinality`, `clearByTag` |
| **Hash Counters** | `incrementField`, `decrementField` |

Each operation runs over the same item count (default 100,000). Multi-key
operations (`setMultiple`/`getMultiple`/`deleteMultiple`) are issued in batches of
1,000. `getTagged` also reports how many items it retrieved, as a correctness
check (it should be 100%).

## Quick start

```bash
cd benchmark
composer install

# Full run (100k items per operation) against a local Redis/Valkey
make benchmark

# Quick run (10k items) that also writes report.html + benchmark.svg
make benchmark-quick
```

`make benchmark` / `make benchmark-quick` start a disposable Valkey container
(see [`docker-compose.yml`](docker-compose.yml), port 6381) and tear it down
afterwards. To run against your own server, override the host/port:

```bash
make benchmark REDIS_HOST=cache.local REDIS_PORT=6379
```

### Manual execution

```bash
php bin/benchmark.php --items=100000
php bin/benchmark.php --items=10000 --report=report.html --chart=benchmark.svg
```

### Options

- `--items=<count>` — items per operation (default: `100000`)
- `--host=<host>` — Redis host (default: `localhost`)
- `--port=<port>` — Redis port (default: `6381`)
- `--tags=<count>` — number of tag groups for tagging ops (default: `4`)
- `--counters=<count>` — number of distinct counters for counter ops (default: `1000`)
- `--report=<path>` — write a self-contained HTML report (table + Chart.js bars)
- `--chart=<path>` — write a static SVG chart (no JavaScript — embeds in Markdown)
- `--help` — show help

## Sample output

From one 2,000-item smoke run against a Dockerised Valkey 7.2 over WSL2
(numbers are illustrative — yours will differ; a full 100k-item run sustains
similar shapes at higher absolute throughput on the bulk paths):

```
Category       Operation                ops/sec   notes
------------   ------------------    -----------   -----------------------------
Core           set                         3,757
Core           get                         4,155
Core           has                         4,298
Core           setMultiple               131,605   batches of 1000
Core           getMultiple               268,711   batches of 1000
Core           deleteMultiple              2,186   batches of 1000
Core           delete                      1,450
Tagging        setTagged                   3,479
Tagging        getTagged                 265,328   2,000 of 2,000 retrieved (100%)
Tagging        clearByTag                173,792   4 groups invalidated
Counters       increase                    3,982   1000 distinct counters
Counters       decrease                    3,994   1000 distinct counters
Hash Core      set                         3,639
Hash Core      get                         4,028
Hash Core      setMultiple               193,782   batches of 1000
Hash Core      getMultiple               411,005   batches of 1000
Hash Fields    getField                    4,110   single HGET per key
Hash Fields    setField                    3,915   single HSET per key
Hash Fields    getFields                   4,029   HMGET of 3 fields per key
Hash Fields    setFields                   3,827   HMSET of 2 fields per key
Hash Tagging   setTagged                   3,493
Hash Tagging   getTagged                 276,432   2,000 of 2,000 retrieved (100%)
Hash Tagging   clearByTag                175,608   4 groups invalidated
Hash Counters  incrementField              3,928   1000 distinct counters
Hash Counters  decrementField              4,007   1000 distinct counters
```

How to read it:

- **Single-key operations** (`set`, `get`, `setTagged`, `increase`,
  `getField`, …) each cost one network round-trip, so on a latency-bound path
  they cluster together — this is the cost of the round-trip, not of
  RapidCache's logic.
- **Pipelined / bulk operations** are dramatically faster because they amortise
  round-trips: `setMultiple` and `getMultiple` chunk many keys per call, and
  `getTagged`/`clearByTag` resolve a whole tag with `SMEMBERS` + a single
  `MGET`/batched delete.
- `delete`/`deleteMultiple` are slower than their `set` counterparts because each
  removal also unwinds the key's tag memberships.
- The hash client's **field-level operations** cost the same single
  round-trip as a whole-record `get`/`set`, so they cluster with the other
  single-key calls — the win is bandwidth and contention on big records, not
  raw ops/sec.

## Project structure

```
benchmark/
├── README.md                 # This documentation
├── composer.json             # Dependencies (RapidCache + monolog)
├── docker-compose.yml        # Disposable Valkey for `make benchmark`
├── Makefile                  # benchmark / benchmark-quick targets
├── bin/
│   └── benchmark.php         # CLI entry point
└── src/
    ├── BenchmarkRunner.php       # Runs the per-operation sweep
    ├── OperationResult.php       # One operation's throughput
    ├── HtmlReportGenerator.php   # Renders the --report HTML output
    ├── SvgChartGenerator.php     # Renders the --chart static SVG output
    ├── ComplexTestObject.php     # Realistic nested payload
    └── Address.php               # Nested value object used by the payload
```

## Prerequisites

- Docker + Docker Compose (for the bundled Valkey), or a reachable Redis/Valkey
- PHP 8.5+ with the `redis` and `igbinary` extensions
- Composer

## Test payloads

- `RapidCacheClient` sweep: values are `ComplexTestObject` instances
  serialized as arrays — realistic nested data (id, name, email, `DateTime`,
  metadata, an `Address`, tags, preferences, etc.) so serialization cost is
  representative rather than a trivial scalar.
- `HashRapidCacheClient` sweep: values are flat associative arrays of
  scalars (id, name, email, score, created_at). The hash client rejects
  objects/nested arrays at `set()`, so the payload shape is deliberately
  different.

The two sweeps share the same Valkey instance and the same `rapid-cache:`
key prefix; the hash client uses a separate tag-index namespace
(`H_TAG:` / `H_TAGS:`) so the two tag spaces never collide.
