# GryfOSS Rapid Cache Client

![Tests status](https://github.com/GryfOSS/rapid-cache-client/workflows/tests/badge.svg)
![GitHub tag (latest SemVer)](https://img.shields.io/github/v/tag/GryfOSS/rapid-cache-client?label=latest%20version&sort=semver)

A high-performance Redis-based caching library for PHP that provides advanced caching features including tagging, queues, sets, and sorted sets. The library implements the `CacheServiceInterface` standard for caching services and offers a fast Redis implementation through `RapidCacheClient`.

## Features

- **Basic Cache Operations**: Get, set, delete, and clear cache entries
- **TTL Support**: Configurable time-to-live for cache entries
- **Tagging System**: Group cache entries by tags for bulk operations
- **Queue Operations**: FIFO queue support with enqueue/dequeue operations
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
composer require gryf-oss/rapid-cache-client
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

use GryfOSS\Cache\RapidCacheClient;

// Basic setup
$cache = new RapidCacheClient('localhost', 6379);

// With prefix (recommended for shared Redis instances)
$cache = new RapidCacheClient('localhost', 6379, 'myapp:');
```

### Basic Cache Operations

```php
// Store a value
$cache->set('user:123', ['name' => 'John Doe', 'email' => 'john@example.com']);

// Retrieve a value
$user = $cache->get('user:123');

// Store with TTL (expires in 1 hour)
$cache->set('session:abc123', $sessionData, null, 3600);

// Delete a specific key
$cache->delete('user:123');

// Clear all cache
$cache->clear();
```

### Working with Tags

Tags allow you to group related cache entries and perform bulk operations:

```php
// Store items with tags
$cache->set('user:123', $userData, 'users');
$cache->set('user:456', $otherUserData, 'users');
$cache->set('post:789', $postData, 'posts');

// Get all items with a specific tag
foreach ($cache->getTagged('users') as $key => $value) {
    echo "Key: $key, Value: " . json_encode($value) . "\n";
}

// Tag an existing item
$cache->tag('user:123', 'premium-users');

// Remove tag from item
$cache->untag('user:123', 'premium-users');

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
```

### Sorted Sets

For ordered collections with scores:

```php
// Add scored items (using Redis sorted sets)
$cache->set('score:user123', 100, 'leaderboard', null, 100);
$cache->set('score:user456', 200, 'leaderboard', null, 200);

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

```php
use GryfOSS\Cache\RapidCacheClient;
use InvalidArgumentException;

try {
    $cache = new RapidCacheClient('localhost', 6379);

    // This will throw an exception
    $cache->set('key', null); // Cannot set null values

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
    $cache->set("user:$id", $userData, 'active-users', 3600);
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

# Run functional tests only
composer test:functional

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

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Changelog

See [RELEASES](https://github.com/GryfOSS/rapid-cache-client/releases) for version history and changes.

## Support

- **Issues**: [GitHub Issues](https://github.com/GryfOSS/rapid-cache-client/issues)
- **Discussions**: [GitHub Discussions](https://github.com/GryfOSS/rapid-cache-client/discussions)
- **Documentation**: This README and inline code documentation
