.PHONY: test test-comprehensive up down clean

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
