<?php

require_once 'vendor/autoload.php';

use GryfOSS\Cache\RapidCacheClient;

$service = new RapidCacheClient('dummy-host');

echo "Testing TTL validation...\n";

try {
    $service->set('test', 'value', null, 0);
    echo "ERROR: Should have thrown exception for TTL=0\n";
} catch (InvalidArgumentException $e) {
    echo "SUCCESS: TTL=0 validation works: " . $e->getMessage() . "\n";
}

try {
    $service->set('test', 'value', null, 2592001);
    echo "ERROR: Should have thrown exception for TTL=2592001\n";
} catch (InvalidArgumentException $e) {
    echo "SUCCESS: TTL=2592001 validation works: " . $e->getMessage() . "\n";
}

echo "TTL validation test completed successfully!\n";
