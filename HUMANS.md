# Developer Guide (for Humans)

Welcome. This is the human-facing companion to [AGENTS.md](AGENTS.md): if
you're a person who wants to hack on, extend, or understand **IDCT Rapid Cache
Client**, start here. For end-user *usage* (how to call the API in your own
app) see [README.md](README.md); this document is about *developing the library
itself*.

---

## What this project is

`IDCT\Cache\RapidCacheClient` is a high-performance cache client for PHP backed
by Redis (or any Redis-compatible server - we test against
[Valkey](https://valkey.io/)). It does two things:

1. **Implements PSR-16** (`Psr\SimpleCache\CacheInterface`) so it's a drop-in
   for any framework or library that speaks the standard SimpleCache contract.
2. **Extends PSR-16** through `CacheServiceInterface` with features the standard
   doesn't cover: tag-based grouping and invalidation, FIFO queues, sets and
   sorted sets, and atomic counters.

It leans on two PHP extensions for speed:

- **`ext-redis`** (phpredis) - the C client, far faster than a pure-PHP one.
- **`ext-igbinary`** - a compact binary serializer. We turn it on at connect
  time so any PHP value (objects, nested arrays, `DateTime`, …) round-trips
  losslessly and more compactly than `serialize()`.

The whole codebase is small - essentially one class plus a config object and
two exception types - but it's written to a high bar: 100% test coverage,
PHPStan level 8, mutation-tested, and densely documented inline.

---

## Prerequisites

- **PHP 8.2+** with `ext-redis` (built with igbinary support) and
  `ext-igbinary` enabled.
  - Ubuntu/Debian: `sudo apt-get install php-redis php-igbinary`
  - PECL: `pecl install redis igbinary` (answer "yes" to enable igbinary
    support when building redis).
- **Composer** (v2).
- **Docker + Docker Compose** - the test suites spin up a Valkey container; you
  don't need a Redis installed on your host.

Verify your extensions:

```bash
php -m | grep -E '(redis|igbinary)'
php --ri redis | grep -i igbinary   # should show igbinary support => enabled
```

---

## First-time setup

```bash
git clone https://github.com/ideaconnect/php-rapid-cache-client.git
cd php-rapid-cache-client
composer install
```

That's it. The `composer test:*` scripts manage the Valkey container
automatically (start → wait → run → stop), so you can go straight to running
tests.

---

## The everyday workflow

Most changes follow this loop:

1. **Write or change code** in [src/](src/).
2. **Add/adjust tests** - unit tests in [tests/Unit/](tests/Unit/) for logic and
   branch coverage, and/or a Behat scenario in [features/](features/) for
   end-to-end behavior against a real server.
3. **Run the checks** (see below).
4. **Fix style**, commit, open a PR.

```bash
composer test:unit            # PHPUnit + coverage (boots Valkey)
composer test:bdd             # Behat functional tests (boots Valkey)
composer analyse              # PHPStan level 8
composer fix                  # php-cs-fixer - auto-formats your diff
composer test:mutation        # Infection (optional locally; slow but revealing)
```

Handy extras:

```bash
composer test                      # install + unit + bdd, the full gate
composer test:unit-no-coverage     # faster unit run while iterating
composer redis:start               # bring Valkey up by hand (port 6380)
composer redis:stop                # tear it down
composer test:connection           # sanity-check the Valkey container
composer clean                     # down -v + docker system prune
```

> **Why port 6380?** The Compose file maps the container's 6379 to host
> **6380** so it won't collide with a Redis you might already run locally. The
> test config ([phpunit.xml](phpunit.xml)) and Behat point at
> `localhost:6380` via `REDIS_HOST`/`REDIS_PORT`.

---

## How the code is organized

```
src/
  RapidCacheClient.php       ← the implementation (read its PHPDoc first)
  CacheServiceInterface.php  ← the public contract
  RedisConnectionConfig.php  ← immutable, validated connection settings
  Exception/
    CacheException.php           ← storage/transport failures (PSR-16 CacheException)
    InvalidArgumentException.php  ← caller mistakes (PSR-16 InvalidArgumentException)
tests/Unit/                  ← PHPUnit; phpredis is mocked with php-mock
features/                    ← Behat .feature files run against real Valkey
  Contexts/FeatureContext.php
  docker/docker-compose.yml  ← Valkey 7.2, host port 6380
```

Two layers of tests, on purpose:

- **Unit tests** mock the phpredis calls (via `php-mock`/`php-mock-phpunit`), so
  they pin *exactly which Redis commands we issue and in what shape* - fast,
  deterministic, and the source of our 100% coverage number.
- **Behat features** talk to a **real** Valkey, so they catch the things mocks
  can't: serialization round-trips, TTL expiry, pipeline semantics, actual data
  structures.

---

## Design decisions worth understanding before you change anything

These are the load-bearing ideas. The inline PHPDoc in
[src/RapidCacheClient.php](src/RapidCacheClient.php) explains each in detail;
here's the map so you know what you're looking at.

- **Lazy connection.** Constructing the client opens *no* socket - the first
  cache operation does (`reconnect()`). `getRedis()` re-establishes the
  connection on demand if it was dropped. This keeps object construction cheap
  and survives transient network blips.

- **igbinary, and the "stored `false`" gotcha.** Because every value is
  igbinary-serialized, a key holding a literal `false` and a missing key both
  come back as `false` from the underlying GET. So `get()` and `getSorted()`
  add an `EXISTS` probe to tell the two apart. If you ever feel tempted to
  delete that "redundant" probe - don't; it's the fix for a real ambiguity.

- **Dual tag index.** Tags are not a Redis feature; we build them from two
  mirrored sets:
  - `TAG:<tag>` → the set of keys carrying that tag (forward lookup, used by
    `getTagged()`/`clearByTag()`),
  - `TAGS:<key>` → the set of tags on that key (reverse lookup, used by cleanup
    so deleting a key can efficiently remove it from every tag).
  Every tag write updates **both** sets inside one `MULTI/EXEC` pipeline so they
  never drift apart, and `delete()` routes through `unindexKey()` to keep the
  reverse index honest.

- **Self-healing reads.** `getTagged()` and `getSorted()` quietly prune entries
  whose underlying key has expired or been deleted, as a side effect of
  iterating. (Note: if you `break` out of the generator early, only what you've
  already seen gets cleaned - the rest is left for next time.)

- **One exception vocabulary.** Callers should only ever see PSR-16 exceptions.
  Raw phpredis `RedisException`s are caught in `wrap()` and translated by
  `toCacheException()`. `wrap()` also implements optional *retry-once* on a
  transient error (off by default; toggle via `RedisConnectionConfig`), guarded
  by a `$retrying` re-entrancy flag so a retry can't recurse.

- **Bulk chunking.** The multi-key methods chunk their input by
  `RedisConnectionConfig::$pipelineBatchSize` (default 1000). Redis is
  single-threaded; an unbounded `MGET`/pipelined `MULTI/EXEC` can blow past the
  client query-buffer limit or stall the event loop. Chunking bounds both.

- **`clear()` never nukes the whole server.** With a prefix configured it
  `SCAN`s for `<prefix>*` and `UNLINK`s in batches, leaving other apps' keys
  alone. With no prefix it `FLUSHDB`s the current database only. It never calls
  `FLUSHALL`.

---

## Quality gates (what CI enforces)

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs on PHP 8.2 and
8.5 and will fail your PR unless:

| Gate | Requirement | Run locally |
| --- | --- | --- |
| Unit tests | all pass | `composer test:unit` |
| Coverage | **100.00%** methods *and* lines | (reported by the unit run) |
| Functional tests | all Behat scenarios pass | `composer test:bdd` |
| Static analysis | PHPStan level 8, no errors | `composer analyse` |
| Mutation score | MSI ≥ 90, covered MSI ≥ 93 | `composer test:mutation` |
| `composer validate` | composer.json/lock valid | `composer validate --strict` |

The 100% coverage rule is the one that bites most often: **every new branch or
early return needs a test that exercises it.** When you add a method, follow the
house style and attach `@see` PHPDoc tags pointing at the tests that pin its
behavior - grep an existing method in
[src/RapidCacheClient.php](src/RapidCacheClient.php) for the pattern.

---

## Documentation conventions

This project deliberately over-documents. The expectation for any non-trivial
method is:

- a one-line summary, then prose explaining **why** and any "gimmicks" (race
  windows, side effects, protocol quirks);
- full `@param` / `@return` / `@throws`;
- `@see` links to the covering tests.

If you change behavior, update [README.md](README.md) (user docs), this file,
and [AGENTS.md](AGENTS.md) (AI-agent rules) so all three stay consistent. Inline
comments should explain reasoning the code can't express on its own - not
restate the obvious.

---

## What has been done so far

The library has been through a substantial modernization and hardening pass:

- **PSR-16 compliance** end to end, with `CacheServiceInterface` layering the
  extra (tagging/queue/set/counter) operations on top.
- **PHP 8.2+ rewrite**: strict types everywhere, readonly value object for
  connection config, constructor property promotion, union types for the TTL
  parameter (`null|int|DateInterval`).
- **Tagging** reworked onto the dual-index set scheme with atomic pipelined
  writes and self-healing reads. (Heads-up: [IMPLEMENTATION.md](IMPLEMENTATION.md)
  describes an earlier *hash-based* tagging design and is now historical/stale -
  the live behavior is the set-based one documented in the source.)
- **Queues** with `enqueue`/`pop`/`peek`/`getQueue`/`getQueueLength`, including
  `peek` (non-destructive inspection) added recently.
- **Resilience**: lazy connect, transparent reconnect, error translation to
  PSR-16 exceptions, and opt-in retry-once.
- **Bulk operations** with configurable pipeline batching.
- **Test & quality infrastructure**: PHPUnit unit suite with mocked phpredis at
  100% coverage, Behat functional suite against Valkey, PHPStan level 8 with
  strict rules, php-cs-fixer config, Infection mutation testing, and a CI matrix
  across PHP versions.
- **Benchmarks** comparing against Symfony Cache (igbinary and JSON adapters);
  see [benchmark/](benchmark/) and the chart in the README.

See `git log` and the GitHub
[releases](https://github.com/ideaconnect/php-rapid-cache-client/releases) for
the detailed history.

---

## Contributing

1. Fork, branch (`git checkout -b feature/your-thing`).
2. Make your change with tests and docs.
3. Run the full gate: `composer test`, `composer analyse`, `composer fix`.
4. Commit with a clear message, push, open a PR against `main`.
5. In the PR description, note any behavior or breaking changes explicitly.

When reporting a bug, include your PHP version, Redis/Valkey version, the
library version, a minimal reproduction, and the full error + stack trace.

---

## Troubleshooting

- **`Class "Redis" not found` / serializer errors** - `ext-redis` isn't
  installed, or wasn't built with igbinary. Check `php --ri redis`.
- **Connection refused in tests** - the Valkey container isn't up or finished
  starting. `composer redis:start` then `composer test:connection`; the
  `redis:wait` step sleeps to give it time.
- **Coverage check fails in CI but tests pass** - you added a code path without
  a test. Look at the per-line HTML report under `report/` after a coverage run.
- **php-cs-fixer warns about your PHP version** - it just means you're running a
  newer PHP than the 8.2 floor; `composer fix` still works. Prefer running it on
  8.2 if a fix looks suspicious.
- **Port 6380 already in use** - something else grabbed it; stop it or change
  the mapping in [features/docker/docker-compose.yml](features/docker/docker-compose.yml)
  (and the matching `REDIS_PORT`).

---

## License

MIT - see [LICENSE](LICENSE).
