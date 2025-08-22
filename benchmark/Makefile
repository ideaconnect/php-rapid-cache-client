.PHONY: help install up down clean benchmark benchmark-all benchmark-praetorian benchmark-symfony-igbinary benchmark-symfony-json test

# Default target
help:
	@echo "Cache Benchmark Makefile"
	@echo ""
	@echo "Targets:"
	@echo "  install                  Install dependencies"
	@echo "  up                       Start Valkey service"
	@echo "  down                     Stop services"
	@echo "  clean                    Clean up everything"
	@echo "  benchmark-all            Run all benchmarks (default: 100k items)"
	@echo "  benchmark-praetorian     Run Praetorian cache benchmark"
	@echo "  benchmark-symfony-igb    Run Symfony cache (igbinary) benchmark"
	@echo "  benchmark-symfony-json   Run Symfony cache (JSON) benchmark"
	@echo "  benchmark-quick          Run quick benchmark (10k items)"
	@echo "  test                     Test Redis connection"

# Install dependencies
install:
	composer install

# Start Valkey service
up:
	docker compose up -d valkey

# Stop all services
down:
	docker compose down

# Clean up everything
clean:
	docker compose down -v
	docker system prune -f

# Test connection
test: up
	@echo "Testing Redis connection..."
	@sleep 5
	@docker exec benchmark-valkey valkey-cli ping
	@echo "Connection test completed"

# Run all benchmarks
benchmark-all: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php --adapter=all
	@$(MAKE) down

# Run individual benchmarks
benchmark-praetorian: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php --adapter=praetorian
	@$(MAKE) down

benchmark-symfony-igb: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php --adapter=symfony-igbinary
	@$(MAKE) down

benchmark-symfony-json: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php --adapter=symfony-json
	@$(MAKE) down

# Quick benchmark with fewer items
benchmark-quick: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php --adapter=all --items=10000
	@$(MAKE) down

# Custom benchmark
benchmark: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost REDIS_PORT=6381 && php bin/benchmark.php $(ARGS)
	@$(MAKE) down
