.PHONY: help install up down clean test \
        benchmark benchmark-all benchmark-basic benchmark-tagged benchmark-quick \
        benchmark-rapid-cache benchmark-symfony-igbinary benchmark-symfony-json

# Connection params used by every benchmark target. Defaults match the host-port
# exposed by ./docker-compose.yml. Override at the command line:
#   make benchmark-all REDIS_HOST=cache.local REDIS_PORT=6379
REDIS_HOST ?= localhost
REDIS_PORT ?= 6381

# Standard CLI prefix every target uses — single source of truth for host/port.
BENCH := php bin/benchmark.php --host=$(REDIS_HOST) --port=$(REDIS_PORT)

# Default target
help:
	@echo "Cache Benchmark Makefile"
	@echo ""
	@echo "Targets:"
	@echo "  install                       Install dependencies"
	@echo "  up                            Start the Valkey container (port $(REDIS_PORT))"
	@echo "  down                          Stop services"
	@echo "  clean                         Stop services and prune docker resources"
	@echo "  test                          Smoke-test the Redis connection"
	@echo ""
	@echo "  benchmark-all                 All adapters, basic + tagged (default: 100k items)"
	@echo "  benchmark-basic               All adapters, basic only (100k items)"
	@echo "  benchmark-tagged              All adapters, tagged only (100k items)"
	@echo "  benchmark-quick               All adapters, 10k items, basic + tagged, writes report.html + benchmark.svg"
	@echo ""
	@echo "  benchmark-rapid-cache         IDCT Rapid Cache only (basic + tagged)"
	@echo "  benchmark-symfony-igbinary    Symfony cache (igbinary) only (basic + tagged)"
	@echo "  benchmark-symfony-json        Symfony cache (JSON) only (basic + tagged)"
	@echo ""
	@echo "  benchmark ARGS='...'          Run with custom CLI args, e.g."
	@echo "    make benchmark ARGS='--adapter=all --items=5000 --type=tagged --report=out.html'"
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

benchmark-all: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=all --type=both
	@$(MAKE) down

benchmark-basic: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=all --type=basic
	@$(MAKE) down

benchmark-tagged: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=all --type=tagged
	@$(MAKE) down

benchmark-rapid-cache: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=rapid-cache --type=both
	@$(MAKE) down

benchmark-symfony-igbinary: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=symfony-igbinary --type=both
	@$(MAKE) down

benchmark-symfony-json: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=symfony-json --type=both
	@$(MAKE) down

# Quick run for smoke-testing — fewer items, both types, writes HTML report + SVG chart.
benchmark-quick: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) --adapter=all --items=10000 --type=both --report=report.html --chart=benchmark.svg
	@echo ""
	@echo "✓ HTML report written to $(CURDIR)/report.html"
	@echo "✓ SVG chart written to $(CURDIR)/benchmark.svg"
	@$(MAKE) down

# Custom benchmark — pass any CLI flags via ARGS='...'.
benchmark: up install
	@echo "Waiting for Valkey to be ready..."
	@sleep 10
	@$(BENCH) $(ARGS)
	@$(MAKE) down
