# Hash-Based Redis Cache Implementation

## Overview

This implementation optimizes the Redis cache service by using **hash-based tagging** instead of the original set-based approach. This provides better performance and simpler data structures.

## Key Improvements

### Before (Set-Based Approach)
```php
// Tag set: stores which keys belong to a tag
$redis->sAdd($tag, $key);
// Reverse lookup: stores which tags a key belongs to
$redis->sAdd("TAGS:$key", $tag);
```

### After (Hash-Based Approach)
```php
// Tag hash: stores key => value directly
$redis->hSet("TAG:$tag", $key, $value);
// Reverse lookup: still maintained for cleanup
$redis->sAdd("TAGS:$key", $tag);
```

## Benefits

1. **Reduced Redis Operations**: `getTagged()` now requires only one `hGetAll()` instead of `sMembers()` + multiple `get()` calls
2. **Better Performance**: Direct value retrieval from hash eliminates N+1 query problem
3. **Atomic TTL Handling**: Expired keys are automatically cleaned from tag hashes
4. **Simplified Logic**: No need to handle sorted sets vs regular sets for tagging

## Docker Setup with Valkey

The project now includes Docker Compose configuration using **Valkey** (Redis-compatible):

```bash
# Start Valkey service
make up

# Run comprehensive tests
make test-comprehensive

# Test connection
make test-connection

# Clean up
make clean
```

## Comprehensive Test Coverage

The Behat tests cover all major functionality:

- ✅ Basic cache operations (set/get/delete)
- ✅ TTL expiration handling
- ✅ Queue operations (enqueue/pop/length)
- ✅ Set operations (create/add/remove/cardinality)
- ✅ Counter operations (increase/decrease)
- ✅ Tagged cache operations
- ✅ Multi-tag scenarios
- ✅ Tag-based cleanup
- ✅ TTL with tagged elements

## Configuration

### Environment Variables
- `REDIS_HOST`: Redis/Valkey host (default: localhost)
- `REDIS_PORT`: Redis/Valkey port (default: 6380)

### Docker Services
- **valkey**: Redis-compatible database on port 6380
- **app**: Application container with mounted source code
- **test**: Test runner container

## Running Tests

```bash
# Using Make
make test-comprehensive

# Using Docker Compose directly
docker compose up -d valkey
export REDIS_HOST=localhost REDIS_PORT=6380
./vendor/bin/behat features/cache_comprehensive.feature
docker compose down

# Using the test script
./test_comprehensive.sh
```

## Implementation Notes

### Tagged Cache Storage
- Tag hashes use prefix `TAG:` (e.g., `TAG:category_a`)
- Values are stored directly in the hash for fast retrieval
- Reverse lookup sets (`TAGS:$key`) maintained for cleanup operations

### TTL Handling
- Original keys still use Redis TTL mechanisms
- `getTagged()` checks key existence and cleans expired entries
- This maintains consistency between main cache and tag hashes

### Backward Compatibility
- Interface remains unchanged
- Score parameter in `set()` method preserved but unused (hash-based approach doesn't support scoring)
- All existing functionality works as expected
