# Agent Guidelines

Rules for AI agents (Claude, Copilot, Codex, etc.) working on this codebase.
Humans should read [HUMANS.md](HUMANS.md) instead — it covers the same ground
in a friendlier, narrative form.

## TL;DR

This is a PHP 8.2+ library: a Redis/Valkey-backed cache client
(`IDCT\Cache\RapidCacheClient`) that implements PSR-16 SimpleCache and extends
it with tagging, queues, sets, sorted sets, and atomic counters. The whole
public surface is documented inline with PHPDoc and `@see` links back to the
tests that pin each behavior. Quality bars are strict: **100% unit coverage**,
**PHPStan level 8**, and a **mutation score** floor.

## Mandatory

### Leave the test suite green

**Every task must end with all tests passing.** A task is not "done" if any
test is failing, skipped without justification, or commented out. If a change
you make breaks tests, fix them in the same task — do not defer, do not hand
off broken state.

Run before declaring a task complete:

```bash
composer test:unit   # PHPUnit unit tests (also enforces 100% coverage in CI)
composer test:bdd    # Behat BDD / functional tests against a live Valkey
```

Both must exit with status 0 and report all tests passing.

If the test suite genuinely cannot be run in your environment (e.g., no Docker
available), say so explicitly in your final message instead of claiming
success. **Never claim a green suite you did not actually observe.**

### Keep coverage at 100%

CI fails the build if **method coverage or line coverage drops below 100.00%**
(see [.github/workflows/ci.yml](.github/workflows/ci.yml)). Any new branch,
guard, or early return you add needs a matching unit test. When you add a
public method, also add the `@see` PHPDoc links pointing at its tests — that
convention is how this codebase documents the test⇄code mapping (grep existing
methods in [src/RapidCacheClient.php](src/RapidCacheClient.php) for the
pattern).

### Pass static analysis and style

```bash
composer analyse   # PHPStan level 8 + strict-rules + phpunit extension
composer fix       # php-cs-fixer (PSR-12 + Symfony + strict extras), --allow-risky
```

`composer analyse` must report no errors. Run `composer fix` before finishing
so the diff is already style-clean. Existing PHPStan `ignoreErrors` entries in
[phpstan.neon.dist](phpstan.neon.dist) are deliberate (phpredis camelCase
aliases, PHPUnit idioms) — extend them only with a justifying comment, never
silence a real error.

### Mutation testing floor

```bash
composer test:mutation   # Infection; minMsi 90, minCoveredMsi 93
```

If you touch core logic, a passing line-coverage number is not enough — a test
must actually *fail* when the logic is mutated. Infection thresholds live in
[infection.json](infection.json).

## How the project is laid out

| Path | What it is |
| --- | --- |
| [src/RapidCacheClient.php](src/RapidCacheClient.php) | The implementation. Heavily commented; read the PHPDoc before changing behavior. |
| [src/CacheServiceInterface.php](src/CacheServiceInterface.php) | The public contract (extends `Psr\SimpleCache\CacheInterface`). |
| [src/RedisConnectionConfig.php](src/RedisConnectionConfig.php) | Immutable, validated connection settings (host, auth, timeouts, pipeline batch size, retry-once). |
| [src/Exception/](src/Exception/) | `CacheException` (storage/transport faults) and `InvalidArgumentException` (caller mistakes). Both bridge a PSR-16 marker interface onto an SPL base. |
| [tests/Unit/](tests/Unit/) | PHPUnit tests; phpredis is mocked via `php-mock`, so the assertions exercise logic without depending on server round-trips. |
| [features/](features/) | Behat `.feature` files + `FeatureContext`; these run against a **real** Valkey container. |
| [features/docker/docker-compose.yml](features/docker/docker-compose.yml) | Valkey 7.2 on host port **6380** (container 6379). |

## Architecture facts you must not break

These are load-bearing design decisions. Changing them silently will break
callers or corrupt the tag index.

- **Lazy connect.** The constructor opens no socket; the first operation
  triggers `reconnect()`. `getRedis()` transparently reconnects if the handle
  was lost.
- **igbinary serializer.** Set at connect time. It is why a stored literal
  `false` is indistinguishable from a miss at the protocol level — `get()` and
  `getSorted()` deliberately add an `EXISTS` probe to disambiguate. Do not
  "simplify" that probe away.
- **Dual tag index.** Tagging maintains two mirrored SETs per relationship:
  `TAG:<tag>` (tag → keys) and `TAGS:<key>` (key → tags). Every tag write
  updates both inside one `MULTI/EXEC` pipeline. Deletes must call
  `unindexKey()` so the reverse index stays consistent.
- **Self-healing reads.** `getTagged()` and `getSorted()` prune entries whose
  underlying key has expired, as a side effect of iteration. Preserve that.
- **Error translation.** All phpredis `RedisException`s are funneled through
  `wrap()` → `toCacheException()` so callers only ever see PSR-16 exceptions.
  `wrap()` also implements the optional retry-once-on-transient-error logic
  guarded by the `$retrying` re-entrancy flag.
- **Bulk chunking.** `getMultiple`/`setMultiple`/`deleteMultiple`/`clearByTag`
  chunk by `RedisConnectionConfig::$pipelineBatchSize` (default 1000) to bound
  request size and avoid stalling Redis's single-threaded event loop.
- **`clear()` is prefix-scoped.** With a prefix it `SCAN` + `UNLINK`s only
  matching keys (never `FLUSHALL`); without one it `FLUSHDB`s the current db.

## Documentation expectations

The maintainer actively values verbose, explanatory documentation. When you add
or change code:

- Write PHPDoc on every public/protected method: a summary line, `@param` /
  `@return` / `@throws`, and prose explaining any non-obvious behavior or
  "gimmick" (race windows, side effects, protocol quirks).
- Add `@see` links to the test(s) that pin the behavior, matching the existing
  style.
- Explain *why*, not just *what*, in inline comments where the reasoning is not
  self-evident from the code.
- Keep [README.md](README.md), [HUMANS.md](HUMANS.md), and this file in sync
  when behavior or workflow changes.

## Environment notes

- Requires `ext-redis` (built with igbinary support) and `ext-igbinary`.
- Tests expect Valkey on `REDIS_HOST`/`REDIS_PORT` (defaults `localhost:6380`,
  set in [phpunit.xml](phpunit.xml)). `composer redis:start` / `redis:stop`
  manage the container; the `test:*` scripts start and stop it for you.
- Interactive git flags are unavailable here; commit/push only when asked.
