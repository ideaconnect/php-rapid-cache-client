.PHONY: test test-comprehensive up down clean benchmark benchmark-basic benchmark-tagged benchmark-quick

# Start Valkey service
up:
	docker compose up -d valkey

# Stop all services
down:
	docker compose down

# Run comprehensive tests
test-comprehensive: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost && export REDIS_PORT=6380 && ./vendor/bin/behat features/cache_comprehensive.feature --colors
	@$(MAKE) down

# Run original tests
test: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@export REDIS_HOST=localhost && export REDIS_PORT=6380 && ./vendor/bin/behat features/cache.feature --colors
	@$(MAKE) down

# Run full benchmarks (100,000 items)
benchmark: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@cd benchmark && php bin/benchmark.php --adapter=all --items=100000 --type=both --host=localhost --port=6380
	@$(MAKE) down

# Run basic benchmarks only
benchmark-basic: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@cd benchmark && php bin/benchmark.php --adapter=all --items=100000 --type=basic --host=localhost --port=6380
	@$(MAKE) down

# Run tagged benchmarks only
benchmark-tagged: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@cd benchmark && php bin/benchmark.php --adapter=all --items=100000 --type=tagged --host=localhost --port=6380
	@$(MAKE) down

# Run quick benchmarks (10,000 items)
benchmark-quick: up
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@cd benchmark && php bin/benchmark.php --adapter=all --items=10000 --type=both --host=localhost --port=6380
	@$(MAKE) down

# Clean up everything
clean:
	docker compose down -v
	docker system prune -f

# Test connection to Valkey
test-connection: up
	@echo "Testing connection to Valkey..."
	@sleep 5
	@docker exec cache-service-valkey valkey-cli ping
	@$(MAKE) down
