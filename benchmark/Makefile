.PHONY: help install up down clean test benchmark benchmark-quick

# Connection params used by every benchmark target. Defaults match the host-port
# exposed by ./docker-compose.yml. Override at the command line:
#   make benchmark REDIS_HOST=cache.local REDIS_PORT=6379
REDIS_HOST ?= localhost
REDIS_PORT ?= 6381

# Standard CLI prefix every target uses — single source of truth for host/port.
BENCH := php bin/benchmark.php --host=$(REDIS_HOST) --port=$(REDIS_PORT)

# Default target
help:
	@echo "IDCT Rapid Cache — Feature Benchmark"
	@echo ""
	@echo "Profiles RapidCache's own operations (Core, Tagging, Counters)."
	@echo ""
	@echo "Targets:"
	@echo "  install                       Install dependencies"
	@echo "  up                            Start the Valkey container (port $(REDIS_PORT))"
	@echo "  down                          Stop services"
	@echo "  clean                         Stop services and prune docker resources"
	@echo "  test                          Smoke-test the Redis connection"
	@echo ""
	@echo "  benchmark                     Full run (default: 100k items per operation)"
	@echo "  benchmark-quick               10k items, writes report.html + benchmark.svg"
	@echo ""
	@echo "  benchmark ARGS='...'          Run with custom CLI args, e.g."
	@echo "    make benchmark ARGS='--items=50000 --report=out.html'"
	@echo ""
	@echo "Overrides: REDIS_HOST (default: $(REDIS_HOST)), REDIS_PORT (default: $(REDIS_PORT))"

install:
	composer install

up:
	docker compose up -d valkey

down:
	docker compose down

clean:
	docker compose down -v
	docker system prune -f

test: up
	@echo "Testing Redis connection..."
	@sleep 5
	@docker exec benchmark-valkey valkey-cli ping
	@echo "Connection test completed"

# Full run — all operations, default item count.
benchmark: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) $(ARGS)
	@$(MAKE) down

# Quick run for smoke-testing — fewer items, writes HTML report + SVG chart.
benchmark-quick: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --items=10000 --report=report.html --chart=benchmark.svg
	@echo ""
	@echo "✓ HTML report written to $(CURDIR)/report.html"
	@echo "✓ SVG chart written to $(CURDIR)/benchmark.svg"
	@$(MAKE) down
