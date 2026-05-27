# IDCT Rapid Cache — Feature Benchmark

This benchmark profiles **RapidCache against itself**: it measures the
throughput of the library's own operations, grouped into **Core**, **Tagging**
and **Counters**. There is no cross-library comparison — the numbers describe how
many operations of each kind RapidCache sustains per second on the host it runs
on.

> **Absolute numbers depend entirely on the runner hardware and the network path
> to Redis.** Single-key operations are dominated by the round-trip time to the
> server, so a local socket and a Dockerised Valkey over WSL2 will produce very
> different figures. Read the results as *relative* — bulk/pipelined operations
> vs. single round-trips — not as guaranteed throughput.

## What is measured

| Category | Operations |
|---|---|
| **Core** | `set`, `get`, `has`, `setMultiple`, `getMultiple`, `deleteMultiple`, `delete` |
| **Tagging** | `setTagged`, `getTagged`, `tag`, `untag`, `getTagCardinality`, `clearByTag` |
| **Counters** | `increase`, `decrease` |

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

From one 100,000-item run against a Dockerised Valkey 7.2 over WSL2 (numbers are
illustrative — yours will differ):

```
Category   Operation                ops/sec   notes
--------   ------------------    -----------   -----------------------------
Core       set                         3,840
Core       get                         4,147
Core       has                         4,434
Core       setMultiple               115,700   batches of 1000
Core       getMultiple               337,129   batches of 1000
Core       deleteMultiple              2,228   batches of 1000
Core       delete                      1,504
Tagging    setTagged                   3,448
Tagging    getTagged                 200,475   100,000 of 100,000 retrieved (100%)
Tagging    tag                         2,055   second tag onto existing keys
Tagging    untag                       2,161
Tagging    getTagCardinality           3,353   4 tags
Tagging    clearByTag                160,812   4 groups invalidated
Counters   increase                    4,300   1000 distinct counters
Counters   decrease                    4,284   1000 distinct counters
```

How to read it:

- **Single-key operations** (`set`, `get`, `setTagged`, `increase`, …) each cost
  one network round-trip, so on a latency-bound path they cluster together —
  this is the cost of the round-trip, not of RapidCache's logic.
- **Pipelined / bulk operations** are dramatically faster because they amortise
  round-trips: `setMultiple` and `getMultiple` chunk many keys per call, and
  `getTagged`/`clearByTag` resolve a whole tag with `SMEMBERS` + a single
  `MGET`/batched delete.
- `delete`/`deleteMultiple` are slower than their `set` counterparts because each
  removal also unwinds the key's tag memberships.

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

## Test payload

Values are `ComplexTestObject` instances serialized as arrays — realistic nested
data (id, name, email, `DateTime`, metadata, an `Address`, tags, preferences,
etc.) so serialization cost is representative rather than a trivial scalar.
