# IDCT Rapid Cache Client

![Tests status](https://github.com/ideaconnect/php-rapid-cache-client/workflows/tests/badge.svg)
![GitHub tag (latest SemVer)](https://img.shields.io/github/v/tag/ideaconnect/php-rapid-cache-client?label=latest%20version&sort=semver)

A high-performance Redis-based caching library for PHP. `RapidCacheClient` implements [PSR-16 (SimpleCache)](https://www.php-fig.org/psr/psr-16/) and extends it with tagging, queues, sets, sorted sets, and atomic counters via the `CacheServiceInterface` contract.

## Features

- **PSR-16 SimpleCache**: Drop-in compatible with any PSR-16 consumer
- **Basic Cache Operations**: `get`, `set`, `delete`, `clear`, `has`, plus multi-key variants
- **TTL Support**: `int` seconds or `\DateInterval`
- **Tagging System**: Group cache entries by tags for bulk operations
- **Queue Operations**: FIFO queue support with enqueue/pop/peek
- **Set Operations**: Redis set operations for unique collections
- **Sorted Sets**: Ordered collections with scoring
- **Atomic Operations**: Increment/decrement numeric values
- **Prefix Support**: Namespace your cache keys
- **Auto-reconnection**: Handles Redis connection drops gracefully

## Requirements

- PHP 8.2 or higher
- Redis extension (`ext-redis`)
- Igbinary extension (`ext-igbinary`)
- Redis server (tested with Redis 6.0+)

## Installation

Install the package via Composer:

```bash
composer require idct/php-rapid-cache-client
```

### System Requirements

Make sure you have the required PHP extensions installed:

```bash
# On Ubuntu/Debian
sudo apt-get install php-redis php-igbinary

# On CentOS/RHEL
sudo yum install php-redis php-igbinary

# Or using PECL
pecl install redis igbinary
```

## Basic Usage

### Setting up the Client

```php
<?php

use IDCT\Cache\RapidCacheClient;

// Basic setup
$cache = new RapidCacheClient('localhost', 6379);

// With prefix (recommended for shared Redis instances)
$cache = new RapidCacheClient('localhost', 6379, 'myapp:');
```

### Basic Cache Operations (PSR-16)

```php
// Store a value (returns bool)
$cache->set('user.123', ['name' => 'John Doe', 'email' => 'john@example.com']);

// Retrieve a value (returns $default on miss)
$user = $cache->get('user.123');
$user = $cache->get('user.123', $defaultValue);

// Store with a TTL — int seconds or DateInterval
$cache->set('session.abc', $sessionData, 3600);
$cache->set('session.abc', $sessionData, new DateInterval('PT1H'));

// Check existence
if ($cache->has('user.123')) { /* ... */ }

// Delete / clear
$cache->delete('user.123');
$cache->clear();

// Multi-key operations
$cache->setMultiple(['k1' => 'v1', 'k2' => 'v2'], 60);
$values = $cache->getMultiple(['k1', 'k2'], 'fallback');
$cache->deleteMultiple(['k1', 'k2']);
```

> **PSR-16 key rules** — keys must be non-empty strings; characters `{}()/\@:` are reserved and rejected with a `Psr\SimpleCache\InvalidArgumentException`. A TTL of `0` (or negative) deletes the entry per the spec.

### Working with Tags

Tags allow you to group related cache entries and perform bulk operations. Since
PSR-16 `set()` no longer takes a tag, use `setTagged()` or call `tag()` after `set()`:

```php
// Store items with tags in a single call
$cache->setTagged('user.123', $userData, 'users');
$cache->setTagged('user.456', $otherUserData, 'users', 3600); // with TTL

// Or set first, tag later
$cache->set('post.789', $postData);
$cache->tag('post.789', 'posts');

// Get all items with a specific tag
foreach ($cache->getTagged('users') as $key => $value) {
    echo "Key: $key, Value: " . json_encode($value) . "\n";
}

// Remove tag from item
$cache->untag('user.123', 'premium-users');

// Clear all items with a specific tag
$cache->clearByTag('users');
```

### Queue Operations

Use Redis lists as FIFO queues:

```php
// Add items to queue
$cache->enqueue('email-queue', ['to' => 'user@example.com', 'subject' => 'Welcome']);
$cache->enqueue('email-queue', ['to' => 'admin@example.com', 'subject' => 'New user']);

// Process queue items
while ($email = $cache->pop('email-queue')) {
    // Process email
    echo "Sending email to: " . $email['to'] . "\n";
}

// Get queue length
$queueLength = $cache->getQueueLength('email-queue');

// Get all items without removing them
$allEmails = $cache->getQueue('email-queue');
```

### Set Operations

Work with unique collections:

```php
// Create a set
$cache->createSet('user-roles:123', ['admin', 'editor', 'viewer']);

// Add to set
$cache->addToSet('user-roles:123', 'moderator');

// Remove from set
$cache->removeFromSet('user-roles:123', 'viewer');

// Get all set members
$roles = $cache->getSet('user-roles:123');

// Get set size
$count = $cache->getCardinality('user-roles:123');

// Get number of keys tagged with a given tag
$taggedCount = $cache->getTagCardinality('users');
```

### Sorted Sets

For ordered collections with scores:

```php
// Get sorted items (highest first)
foreach ($cache->getSorted('leaderboard', 10, 0, true) as $key => $value) {
    echo "User: $key, Score: $value\n";
}

// Get cardinality of sorted set
$playerCount = $cache->getCardinality('leaderboard', true);
```

### Atomic Operations

For counters and metrics:

```php
// Initialize counter
$cache->set('page-views', 0);

// Increment
$cache->increase('page-views', 1);

// Decrement
$cache->decrease('page-views', 1);

// Get current value
$views = $cache->get('page-views');
```

## Advanced Usage

### Error Handling

All argument-validation errors are thrown as `IDCT\Cache\Exception\InvalidArgumentException`,
which implements `Psr\SimpleCache\InvalidArgumentException` so PSR-16 consumers can
catch it through the standard interface:

```php
use IDCT\Cache\RapidCacheClient;
use Psr\SimpleCache\InvalidArgumentException;

try {
    $cache = new RapidCacheClient('localhost', 6379);
    $cache->get('illegal:key'); // reserved char → throws
} catch (InvalidArgumentException $e) {
    echo "Cache error: " . $e->getMessage();
}
```

### Working with Complex Data

```php
// The client automatically serializes/deserializes complex data
$complexData = [
    'user' => new User(123, 'John Doe'),
    'metadata' => ['created' => new DateTime(), 'tags' => ['vip', 'customer']],
    'settings' => (object)['theme' => 'dark', 'notifications' => true]
];

$cache->set('complex:data', $complexData);
$retrieved = $cache->get('complex:data'); // Automatically unserialized
```

### Batch Operations with Tags

```php
// Tag multiple related items
$userIds = [123, 456, 789];
foreach ($userIds as $id) {
    $userData = getUserFromDatabase($id);
    $cache->setTagged("user.$id", $userData, 'active-users', 3600);
}

// Later, invalidate all active users
$cache->clearByTag('active-users');
```

## Configuration

### Redis Connection

The client supports standard Redis connection parameters:

```php
// Default values
$cache = new RapidCacheClient(
    host: 'localhost',
    port: 6379,        // Default Redis port
    prefix: null       // No prefix
);
```

### TTL Limits

The client enforces TTL limits for safety:

- Minimum TTL: 1 second
- Maximum TTL: 30 days (2,592,000 seconds)

```php
// Valid TTL
$cache->set('key', 'value', null, 3600); // 1 hour

// Invalid TTL (will throw InvalidArgumentException)
$cache->set('key', 'value', null, 0);        // Too low
$cache->set('key', 'value', null, 9999999);  // Too high
```

## Testing

The library includes comprehensive tests. To run them:

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run unit tests only
composer test:unit

# Run BDD (Behat) tests only
composer test:bdd

# Run tests without coverage
composer test:unit-no-coverage
```

### Running with Docker

The project includes Docker support for testing:

```bash
# Start Redis container
composer redis:start

# Run tests
composer test:unit

# Stop Redis container
composer redis:stop
```

## Contributing

We welcome contributions! Please follow these guidelines:

### Getting Started

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/your-username/rapid-cache-client.git
   cd rapid-cache-client
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Development Guidelines

- **PSR-12 Coding Standards**: Follow PSR-12 coding standards
- **Type Declarations**: Use strict typing (`declare(strict_types=1)`)
- **Documentation**: Add PHPDoc for all public methods
- **Tests**: Write tests for new features and bug fixes
- **Backward Compatibility**: Maintain backward compatibility in minor versions

### Code Quality

The project uses several tools to maintain code quality:

```bash
# Fix code style
composer fix

# Run static analysis
./vendor/bin/phpstan analyze

# Run tests with coverage
composer test:unit
```

### Submitting Changes

1. Ensure all tests pass:
   ```bash
   composer test
   ```

2. Fix code style:
   ```bash
   composer fix
   ```

3. Commit your changes:
   ```bash
   git add .
   git commit -m "Add your descriptive commit message"
   ```

4. Push to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

5. Create a Pull Request on GitHub

### Pull Request Guidelines

- **Clear Description**: Provide a clear description of your changes
- **Issue Reference**: Reference any related issues
- **Tests**: Include tests for new functionality
- **Documentation**: Update documentation if needed
- **Breaking Changes**: Clearly mark any breaking changes

### Reporting Issues

When reporting issues, please include:

- PHP version
- Redis version
- Library version
- Minimal code example reproducing the issue
- Full error message and stack trace

## Architecture

### Interface Design

The library follows a clean interface-based design:

- **`CacheServiceInterface`**: Defines the contract for cache implementations
- **`RapidCacheClient`**: Redis-based implementation of the interface

This design allows for:
- Easy testing with mock implementations
- Swapping cache backends without code changes
- Clear contracts for cache operations

### Redis Strategy

The implementation uses Redis data structures efficiently:

- **Strings**: Basic key-value storage
- **Hashes**: Tag associations and metadata
- **Lists**: Queue operations
- **Sets**: Unique collections
- **Sorted Sets**: Ordered collections with scores

## Performance Considerations

### Best Practices

1. **Use Prefixes**: Always use prefixes in shared Redis instances
2. **Set Appropriate TTLs**: Prevent memory bloat with reasonable TTLs
3. **Batch Operations**: Use tags for bulk operations instead of individual deletes
4. **Connection Pooling**: Consider connection pooling for high-traffic applications

### Benchmarks

The library includes benchmark tests in the `benchmark/` directory. Run them to test performance in your environment:

```bash
cd benchmark
composer install
php bin/benchmark.php
```

## Behavior Notes

- **`clear()` is prefix-scoped.** When a key prefix is configured, `clear()` uses `SCAN` + `UNLINK` to remove only keys under that prefix. With no prefix it falls back to `FLUSHDB` (current database only). It never calls `FLUSHALL`, so it will not destroy unrelated data on the same Redis instance.
- **Tag storage format.** Tagged-key membership is stored in a Redis `SET` at `TAG:<tag>` (member values resolved at read time via `MGET`). This means `getTagged()` always returns the **current** value of each key — overwrites via `set()` after `setTagged()` are reflected immediately.
- **`get()` cannot distinguish a stored literal `false`.** With the igbinary serializer, a missing key and a key holding the value `false` both produce `false` from the underlying call. `get()` therefore returns the `$default` argument for both cases.
- **`getTagCardinality(string $tag)`** is the preferred way to count keys associated with a tag; `getCardinality()` no longer special-cases the internal `TAG:` prefix.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Changelog

See [RELEASES](https://github.com/ideaconnect/php-rapid-cache-client/releases) for version history and changes.

## Support

- **Issues**: [GitHub Issues](https://github.com/ideaconnect/php-rapid-cache-client/issues)
- **Discussions**: [GitHub Discussions](https://github.com/ideaconnect/php-rapid-cache-client/discussions)
- **Documentation**: This README and inline code documentation
