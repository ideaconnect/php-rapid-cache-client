#!/bin/bash

echo "Starting Redis Cache Service Tests..."

# Start Valkey in the background
echo "Starting Valkey server..."
docker-compose up -d valkey

# Wait for Valkey to be ready
echo "Waiting for Valkey to be ready..."
sleep 10

# Set environment variables for local testing
export REDIS_HOST=localhost
export REDIS_PORT=6380

# Run the comprehensive tests
echo "Running comprehensive Behat tests..."
./vendor/bin/behat features/cache_comprehensive.feature --colors

# Cleanup
echo "Stopping services..."
docker-compose down

echo "Tests completed!"
