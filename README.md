# IDCT Rapid Cache Client

![Tests status](https://github.com/ideaconnect/php-rapid-cache-client/workflows/CI/badge.svg)
[![codecov](https://codecov.io/gh/ideaconnect/php-rapid-cache-client/graph/badge.svg?token=vLwVqbQk5f)](https://codecov.io/gh/ideaconnect/php-rapid-cache-client)
![GitHub tag (latest SemVer)](https://img.shields.io/github/v/tag/ideaconnect/php-rapid-cache-client?label=latest%20version&sort=semver)
![PHP version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

**IDCT Rapid Cache Client** is a high-performance Redis-backed caching library
for PHP. At its core it is a clean [PSR-16 (SimpleCache)](https://www.php-fig.org/psr/psr-16/)
implementation, so it drops straight into any framework or library that speaks
the standard cache contract. On top of that baseline it adds the features real
applications keep reaching for but PSR-16 leaves out: **tag-based grouping and
invalidation, FIFO queues, sets, sorted sets, and atomic counters** - all
exposed through the `CacheServiceInterface` contract.

The package ships **two clients** that share the same PSR-16 + tagging
surface but differ in how values land in Redis:

- **`RapidCacheClient`** — the general-purpose client. Stores any
  serializable PHP value (objects, nested arrays, `DateTime`, …) as a single
  Redis STRING using the compact binary `ext-igbinary` serializer. Adds
  queues, sets, sorted sets, and whole-value counters on top.
- **`HashRapidCacheClient`** — purpose-built for flat associative arrays of
  scalars. Each value is stored as a native Redis HASH so individual fields
  can be read, written, or atomically incremented without round-tripping the
  whole record (`HGET`/`HSET`/`HINCRBY`). No serializer — the wire format is
  plain Redis hash fields.

Speed comes from two deliberate choices: both clients talk to Redis (or any
Redis-compatible server such as [Valkey](https://valkey.io/)) through the
native `ext-redis` C extension, and the string client serializes values with
`ext-igbinary` so arbitrary PHP values round-trip losslessly and cheaply.
Bulk operations are pipelined and chunked, connections are established
lazily and re-established transparently, and every Redis-level error is
translated into a PSR-16 exception so your calling code stays
backend-agnostic.

## Quick example

```php
<?php

use IDCT\Cache\RapidCacheClient;

// host, port, optional key prefix
$cache = new RapidCacheClient('localhost', 6379, 'myapp:');

// Store any serializable value, optionally with a TTL (seconds or DateInterval)
$cache->set('user.123', ['name' => 'John Doe', 'roles' => ['admin']], 3600);

// Read it back (returns the supplied default on a miss)
$user = $cache->get('user.123', $default = null);

// Group entries under a tag, then invalidate the whole group at once
$cache->setTagged('user.123', $user, 'active-users');
$cache->clearByTag('active-users');
```

…and the same surface as a Redis HASH when the value is a flat record and
you want per-field reads / writes / atomic counters:

```php
use IDCT\Cache\HashRapidCacheClient;

$hash = new HashRapidCacheClient('localhost', 6379, 'myapp:');

// Whole hash in one round-trip
$hash->set('user.123', ['name' => 'John Doe', 'visits' => 0, 'plan' => 'pro'], 3600);

// Single-field operations - no need to fetch the whole record
$plan = $hash->getField('user.123', 'plan');                  // HGET
$hash->setField('user.123', 'last_seen', '2026-05-28');       // HSET
$visits = $hash->incrementField('user.123', 'visits', 1);     // HINCRBY - atomic
```

## Features

Shared by both clients:

- **PSR-16 SimpleCache** - drop-in compatible with any PSR-16 consumer
  (implements `Psr\SimpleCache\CacheInterface`).
- **Core cache operations** - `get`, `set`, `delete`, `clear`, `has`, plus the
  multi-key variants `getMultiple`, `setMultiple`, `deleteMultiple`.
- **Flexible TTLs** - `int` seconds or `\DateInterval`; a non-positive TTL
  deletes the entry, per the spec.
- **Tagging system** - associate keys with tags (`setTagged`, `tag`, `untag`)
  and read or invalidate them in bulk (`getTagged`, `clearByTag`,
  `getTagCardinality`). Each client uses its own tag-index namespace so the
  two can coexist on the same Redis instance without colliding.
- **Key namespacing** - an optional prefix isolates your keys on a shared
  Redis instance; `clear()` is prefix-scoped and never touches other apps' data.
- **Resilient connections** - lazy connect, transparent reconnect, optional
  retry-once on transient errors, and PSR-16 exception translation.
- **Tunable performance** - configurable pipeline batch size, persistent
  connections, and finite connect/read timeouts via `RedisConnectionConfig`.

Specific to `RapidCacheClient`:

- **Lossless serialization** - igbinary handles objects, nested arrays,
  `DateTime`, etc., automatically.
- **Queues** - Redis lists used as FIFO queues: `enqueue`, `pop`, `peek`,
  `getQueue`, `getQueueLength`.
- **Sets** - unique collections: `createSet`, `addToSet`, `removeFromSet`,
  `getSet`, `getCardinality`.
- **Sorted sets** - ordered, value-resolving iteration via `getSorted`.
- **Atomic counters** - `increase` / `decrease` backed by Redis `INCRBY`/`DECRBY`.

Specific to `HashRapidCacheClient`:

- **Native Redis HASH storage** - each value is a flat associative array of
  scalars stored field-for-field as a Redis HASH; no serializer, no
  whole-value round-trips for a single-field read.
- **Single- and multi-field operations** - `getField` / `setField` /
  `hasField` / `deleteField` (`HGET`/`HSET`/`HEXISTS`/`HDEL`) and the bulk
  forms `getFields` / `setFields` / `deleteFields` (`HMGET`/`HMSET`/`HDEL`).
- **Atomic field counters** - `incrementField` / `decrementField` backed by
  Redis `HINCRBY` / `HINCRBYFLOAT` operate in place on individual fields.

## Sponsorship ❤️

This project is maintained on the side and looking for sponsors to keep the
modernization moving forward. If your team relies on it, please consider
chipping in - every contribution helps keep this library alive:

[![Sponsor on GitHub](https://img.shields.io/github/sponsors/ideaconnect?style=for-the-badge&logo=githubsponsors&logoColor=white&label=Sponsor&color=ea4aaa)](https://github.com/sponsors/ideaconnect)
[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-FFDD00?style=for-the-badge&logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/idct)

Thank you to everyone who already supports the project! 🙏

## Contents

- [Quick example](#quick-example)
- [Features](#features)
- [Sponsorship ❤️](#sponsorship-️)
- [Requirements](#requirements)
- [Benchmarks](#benchmarks)
- [Installation](#installation)
- [Testing](#testing)
- [Usage](#usage)
  - [Connecting & configuration](#connecting--configuration)
  - [Basic cache operations (PSR-16)](#basic-cache-operations-psr-16)
  - [Bulk operations](#bulk-operations)
  - [Tagging](#tagging)
  - [Queues](#queues)
  - [Sets](#sets)
  - [Sorted sets](#sorted-sets)
  - [Atomic counters](#atomic-counters)
  - [Hash storage (`HashRapidCacheClient`)](#hash-storage-hashrapidcacheclient)
  - [Error handling](#error-handling)
  - [Behavior notes](#behavior-notes)
- [Contributing](#contributing)
- [License](#license)
- [Thank you](#thank-you)

## Requirements

- **PHP 8.2 or higher**
- **`ext-redis`** (phpredis) - built with igbinary support
- **`ext-igbinary`**
- A **Redis** server (6.0+) or any Redis-compatible server such as **Valkey**
  (the test suite runs against Valkey 7.2)

## Benchmarks

The package ships with a self-benchmark that measures the throughput of both
clients' operations against the same Valkey/Redis instance. There is no
cross-library comparison - it answers "how many of each operation does each
client sustain per second on this host". Results are grouped into six
categories:

- `RapidCacheClient` → **Core**, **Tagging**, **Counters**
- `HashRapidCacheClient` → **Hash Core**, **Hash Fields**, **Hash Tagging**,
  **Hash Counters**

The chart is regenerated by CI and published to the `assets` branch:

![Benchmark results](https://raw.githubusercontent.com/ideaconnect/php-rapid-cache-client/assets/benchmark.svg)

Each bar is one operation, in operations per second (higher is better), on a
scale local to its category. The useful signal is the **shape**, not the
absolute height:

- **Single-key operations** (`set`, `get`, `setTagged`, `increase`,
  `getField`, …) each cost one network round-trip, so they cluster together -
  that floor is the round-trip latency to your Redis, not RapidCache's own
  overhead.
- **Pipelined / bulk operations** are far faster because they amortise
  round-trips: `setMultiple` / `getMultiple` chunk many keys per call, and
  `getTagged` / `clearByTag` resolve an entire tag with `SMEMBERS` + a single
  `MGET` or batched delete. This is the throughput path - prefer the multi-key
  and tag-bulk APIs in hot loops.
- The hash client's **field-level operations** (`getField`, `setField`,
  `incrementField`, …) cost the same single round-trip as a whole-value
  `get` / `set` but move only the affected field over the wire - the win is
  bandwidth and contention on big records, not raw ops/sec.

> Absolute numbers depend entirely on the runner hardware and the network path
> to Redis - treat the chart as the relative cost of single round-trips vs.
> pipelined work, not a guaranteed throughput figure.

Reproduce locally from the [`benchmark/`](benchmark/) directory (see its
[README](benchmark/README.md) for all options):

```bash
cd benchmark
composer install
make benchmark-quick   # 10k items, writes report.html + benchmark.svg
```

## Installation

Install the package with Composer:

```bash
composer require idct/php-rapid-cache-client
```

Make sure the required PHP extensions are present:

```bash
# Ubuntu/Debian
sudo apt-get install php-redis php-igbinary

# CentOS/RHEL
sudo yum install php-redis php-igbinary

# Or via PECL (answer "yes" to enable igbinary support when building redis)
pecl install redis igbinary
```

Verify they are loaded:

```bash
php -m | grep -E '(redis|igbinary)'
php --ri redis | grep -i igbinary   # should report igbinary support => enabled
```

## Testing

The library ships with a PHPUnit unit suite (100% line/method coverage) and a
Behat functional suite that runs against a real Valkey container. The Composer
scripts start and stop the container for you via Docker Compose, so you only
need Docker installed - not a local Redis.

```bash
composer install

composer test          # full gate: install + unit + functional
composer test:unit     # PHPUnit unit tests (with coverage)
composer test:bdd      # Behat functional tests
composer test:unit-no-coverage   # faster unit run while iterating
```

Quality tooling:

```bash
composer analyse       # PHPStan (level 8)
composer fix           # php-cs-fixer (PSR-12 + Symfony rules)
composer test:mutation # Infection mutation testing
```

Managing the Valkey container by hand (exposed on host port **6380**):

```bash
composer redis:start   # docker compose up -d valkey
composer redis:stop    # docker compose down
composer test:connection
composer clean         # down -v + docker system prune
```

> Developing the library itself? See [HUMANS.md](HUMANS.md) for a full
> contributor guide and [AGENTS.md](AGENTS.md) for the rules AI agents follow.

## Usage

### Connecting & configuration

The simplest form takes a host, port, and optional key prefix:

```php
use IDCT\Cache\RapidCacheClient;

$cache = new RapidCacheClient('localhost', 6379);

// A prefix is strongly recommended on a shared Redis instance - it namespaces
// every key and scopes clear() so it never deletes other apps' data.
$cache = new RapidCacheClient('localhost', 6379, 'myapp:');
```

For full control (authentication, database selection, timeouts, persistent
connections, retry behavior, pipeline batch size) pass a
`RedisConnectionConfig`. It is an immutable value object whose defaults are
tuned for safe production behavior (a finite 1s connect timeout, non-persistent
connections):

```php
use IDCT\Cache\RapidCacheClient;
use IDCT\Cache\RedisConnectionConfig;

$config = new RedisConnectionConfig(
    host: 'cache.internal',
    port: 6379,
    prefix: 'myapp:',
    password: 's3cr3t',      // null/empty → no AUTH
    database: 1,             // SELECT this DB (0 = none)
    connectTimeout: 1.0,     // seconds; phpredis default of 0 means "wait forever"
    readTimeout: 1.0,        // seconds; only applied when > 0
    persistent: true,        // reuse the connection across requests (pconnect)
    persistentId: 'pool1',   // pool id for persistent connections
    retryOnce: true,         // retry a failed op once on a transient RedisException
    pipelineBatchSize: 1000, // max commands per pipelined/multi-key batch
);

$cache = new RapidCacheClient($config);
```

No socket is opened during construction - the connection is established lazily
on the first cache operation and re-established automatically if it drops.

### Basic cache operations (PSR-16)

```php
// Store a value (returns bool)
$cache->set('user.123', ['name' => 'John Doe', 'email' => 'john@example.com']);

// Retrieve a value ($default is returned only on a true miss)
$user = $cache->get('user.123');
$user = $cache->get('user.123', $defaultValue);

// Store with a TTL - int seconds or a DateInterval
$cache->set('session.abc', $sessionData, 3600);
$cache->set('session.abc', $sessionData, new DateInterval('PT1H'));

// Check existence (a fast probe - not a guarantee against a concurrent expiry)
if ($cache->has('user.123')) {
    // ...
}

// Delete a single key / clear the (prefix-scoped) cache
$cache->delete('user.123');
$cache->clear();
```

> **PSR-16 key rules** - keys must be non-empty strings; the characters
> `{}()/\@:` are reserved and rejected with a
> `Psr\SimpleCache\InvalidArgumentException`. A TTL of `0` (or negative) deletes
> the entry immediately, per the spec.

### Bulk operations

Multi-key operations are pipelined and automatically chunked by the configured
`pipelineBatchSize`, keeping request size bounded on large inputs:

```php
// Store many at once (single MSET, or a pipelined SETEX batch when a TTL is given)
$cache->setMultiple(['k1' => 'v1', 'k2' => 'v2'], 60);

// Read many at once (single MGET); missing keys map to the default
$values = $cache->getMultiple(['k1', 'k2', 'k3'], 'fallback');

// Delete many at once (with tag cleanup per key)
$cache->deleteMultiple(['k1', 'k2']);
```

### Tagging

Tags group related entries so you can read or invalidate them as a unit. Since
PSR-16 `set()` does not take a tag, use `setTagged()` or call `tag()` after a
plain `set()`:

```php
// Store and tag in one atomic call (optionally with a TTL)
$cache->setTagged('user.123', $userData, 'active-users');
$cache->setTagged('user.456', $otherUser, 'active-users', 3600);

// Or set first, tag later (the key must already exist)
$cache->set('post.789', $postData);
$cache->tag('post.789', 'posts');

// Iterate every value currently under a tag (key => value)
foreach ($cache->getTagged('active-users') as $key => $value) {
    echo "$key => " . json_encode($value) . PHP_EOL;
}

// Remove a single tag association (the value itself is kept)
$cache->untag('user.123', 'active-users');

// Count, then bulk-invalidate everything under a tag
$count = $cache->getTagCardinality('active-users');
$cache->clearByTag('active-users');
```

### Queues

Redis lists used as FIFO queues:

```php
// Append items to the tail
$cache->enqueue('email-queue', ['to' => 'user@example.com', 'subject' => 'Welcome']);
$cache->enqueue('email-queue', ['to' => 'admin@example.com', 'subject' => 'New user']);

// Pop items from the head (FIFO). Returns null when empty.
while ($email = $cache->pop('email-queue')) {
    echo 'Sending to: ' . $email['to'] . PHP_EOL;
}

// Pop several at once
$batch = $cache->pop('email-queue', 10);   // array of up to 10 items, or null

// Inspect without removing
$next  = $cache->peek('email-queue');       // head item, or null
$firstFive = $cache->peek('email-queue', 5);

// Length, or the full contents (head-first; O(N))
$length = $cache->getQueueLength('email-queue');
$all    = $cache->getQueue('email-queue');
```

> `enqueue()` rejects `null` values: phpredis cannot tell a stored `null` apart
> from "queue is empty" on pop.

### Sets

Unique, unordered collections:

```php
// Replace the whole set with an exact membership (DEL + SADD)
$cache->createSet('user-roles:123', ['admin', 'editor', 'viewer']);

// Incremental changes
$cache->addToSet('user-roles:123', 'moderator');
$cache->removeFromSet('user-roles:123', 'viewer');

// Read all members (null when the set does not exist)
$roles = $cache->getSet('user-roles:123');

// Member count
$count = $cache->getCardinality('user-roles:123');
```

### Sorted sets

`getSorted()` treats a Redis sorted set as an ordered index of cache keys: it
reads a window of members and resolves each member's cached value, pruning any
member whose underlying key has expired. Use `reversed: true` for
highest-score-first ordering.

```php
// Top 10 of a leaderboard (highest score first), as member => cachedValue
foreach ($cache->getSorted('leaderboard', count: 10, offset: 0, reversed: true) as $member => $value) {
    echo "$member => " . json_encode($value) . PHP_EOL;
}

// Count of a sorted set (pass true so ZCARD is used instead of SCARD)
$players = $cache->getCardinality('leaderboard', sortedSet: true);
```

### Atomic counters

Backed by Redis `INCRBY` / `DECRBY` (the key is auto-created at 0):

```php
$cache->set('page-views', 0);
$cache->increase('page-views', 1);
$cache->decrease('page-views', 1);

$views = $cache->get('page-views');
```

### Hash storage (`HashRapidCacheClient`)

Use `HashRapidCacheClient` when each cached value is a **flat associative
array of scalars** (a record) and you want to read, write, or atomically
modify individual fields without ever round-tripping the whole record. Each
value lives in Redis as a native HASH, indexed by field name — there is no
serializer involved, so wire payloads are plain Redis hash fields.

```php
use IDCT\Cache\HashRapidCacheClient;

$hash = new HashRapidCacheClient('localhost', 6379, 'myapp:');

// PSR-16 surface works as expected; values must be a non-empty flat
// associative array of string/int/float scalars.
$hash->set('user.123', [
    'name'  => 'John Doe',
    'plan'  => 'pro',
    'score' => 1000,
], 3600);

// Whole-record read returns the hash as a flat array
$user = $hash->get('user.123');           // ['name' => 'John Doe', …]
```

Single-field operations don't move the rest of the record across the wire:

```php
$plan = $hash->getField('user.123', 'plan');                 // HGET
$hash->setField('user.123', 'last_seen', '2026-05-28');      // HSET
$hash->deleteField('user.123', 'score');                     // HDEL
if ($hash->hasField('user.123', 'plan')) { … }               // HEXISTS
```

Multi-field reads/writes:

```php
// Read several fields at once
$slice = $hash->getFields('user.123', ['name', 'plan']);     // HMGET
//   ['name' => 'John Doe', 'plan' => 'pro']

// Merge fields into the existing record (does NOT clear unrelated fields)
$hash->setFields('user.123', ['score' => 2000, 'plan' => 'enterprise']);

// Remove several fields at once
$hash->deleteFields('user.123', ['score', 'last_seen']);     // HDEL …
```

Atomic counters operate in place on a single field, so multiple processes
can hit the same counter without a read-modify-write cycle:

```php
$views = $hash->incrementField('page.views', 'count', 1);    // HINCRBY
$saved = $hash->incrementField('account.42', 'balance', 9.99); // HINCRBYFLOAT
$hash->decrementField('inventory.sku-7', 'stock', 1);
```

Tagging mirrors the string client and uses a separate index namespace
(`H_TAG:` / `H_TAGS:`) so both clients can share the same Redis prefix
without colliding:

```php
$hash->setTagged('user.123', $userRecord, 'active-users', 3600);
foreach ($hash->getTagged('active-users') as $key => $record) { … }
$hash->clearByTag('active-users');
```

What you give up vs. `RapidCacheClient`:

- **No type fidelity beyond scalars.** `bool`, `null`, nested arrays, objects,
  resources are rejected at `set()` with
  `Psr\SimpleCache\InvalidArgumentException`. Use `RapidCacheClient` (with
  igbinary) when the cached value isn't a flat record.
- **No per-field TTL.** The whole hash expires as a unit via `PEXPIRE`. Set
  the TTL on `set()`; subsequent `setField()` calls leave the existing TTL
  in place.
- **Queues / sets / sorted sets are not exposed** — those live on
  `RapidCacheClient` and can be used side-by-side on the same connection.

### Error handling

Argument-validation problems are thrown as
`IDCT\Cache\Exception\InvalidArgumentException`, and storage/transport failures
as `IDCT\Cache\Exception\CacheException`. Both implement the matching PSR-16
marker interfaces, so PSR-16 consumers can catch them through the standard
contract and stay backend-agnostic:

```php
use Psr\SimpleCache\InvalidArgumentException;
use Psr\SimpleCache\CacheException;

try {
    $cache->get('illegal:key');     // reserved character → InvalidArgumentException
} catch (InvalidArgumentException $e) {
    // bad input on our side
} catch (CacheException $e) {
    // the cache backend failed (the original RedisException is the chained cause)
}
```

### Behavior notes

- **`clear()` is prefix-scoped.** With a prefix configured it uses
  `SCAN` + `UNLINK` to remove only keys under that prefix; with no prefix it
  falls back to `FLUSHDB` (current database only). It never calls `FLUSHALL`,
  so it will not destroy unrelated data on a shared Redis instance.
- **`get()` distinguishes a stored `false` from a miss.** Because the igbinary
  serializer makes a stored literal `false` indistinguishable from a missing key
  at the protocol level, `get()` adds an `EXISTS` probe: it returns the stored
  `false` when the key exists, and the `$default` only on a true miss.
- **`getMultiple()` does *not* disambiguate.** For throughput it issues a single
  `MGET` with no per-key `EXISTS` probe, so both a missing key and a stored
  `false` map to the default. Use `get()` per key when that distinction matters.
- **Tags read the current value.** Tagged-key membership is stored in a Redis
  `SET` at `TAG:<tag>`; member values are resolved at read time via `MGET`, so
  `getTagged()` always returns each key's current value - an overwrite via
  `set()` after `setTagged()` is reflected immediately.
- **Self-healing reads.** `getTagged()` and `getSorted()` prune entries whose
  underlying key has expired as a side effect of iteration. Breaking out of the
  generator early leaves the un-inspected entries for the next call.

## Contributing

Contributions are welcome! In short:

1. Fork the repository and create a feature branch
   (`git checkout -b feature/your-feature`).
2. Make your change **with tests and documentation**. The project keeps **100%
   unit-test coverage**, passes **PHPStan level 8**, follows PSR-12 + strict
   types, and is mutation-tested - new branches need matching tests.
3. Run the full gate before opening a PR:
   ```bash
   composer test      # unit + functional
   composer analyse   # PHPStan
   composer fix       # php-cs-fixer
   ```
4. Open a Pull Request against `main` with a clear description, and call out any
   breaking changes explicitly.

When reporting an issue, please include your PHP version, Redis/Valkey version,
the library version, a minimal reproduction, and the full error and stack trace.

Full contributor documentation lives in [HUMANS.md](HUMANS.md); the conventions
AI agents must follow are in [AGENTS.md](AGENTS.md).

- **Issues**: [GitHub Issues](https://github.com/ideaconnect/php-rapid-cache-client/issues)
- **Discussions**: [GitHub Discussions](https://github.com/ideaconnect/php-rapid-cache-client/discussions)

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file
for details.

## Thank you

Thank you for using and supporting **IDCT Rapid Cache Client** - whether by
filing issues, opening pull requests, spreading the word, or
[sponsoring the project](#sponsorship-️). Every bit of help keeps the
modernization moving and the library alive. 🙏
