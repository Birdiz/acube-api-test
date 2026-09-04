COMPOSE   ?= docker compose
SERVICE   ?= app
HTTP_PORT ?= 8000
CMD       ?= sh
APPLY     ?=

# Exported so `make up HTTP_PORT=8080` actually changes the published port.
export HTTP_PORT

.DEFAULT_GOAL := help
.PHONY: help up exec analyse refactor stop purge

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-8s\033[0m %s\n", $$1, $$2}'

up: ## Build if needed, start the container and wait until it is healthy
	$(COMPOSE) up -d --build --wait
	@echo ""
	@echo "  App running on http://localhost:$(HTTP_PORT)"
	@echo ""

exec: ## Open a shell in the container, or run CMD="composer install"
	$(COMPOSE) exec $(SERVICE) $(CMD)

analyse: ## Run PHPStan at level 10 over src/ and tests/
	$(COMPOSE) exec $(SERVICE) sh -c 'APP_DEBUG=1 php bin/console cache:warmup --env=test && vendor/bin/phpstan analyse --no-progress'

refactor: ## Show what Rector would change, or apply it with APPLY=1
	$(COMPOSE) exec $(SERVICE) vendor/bin/rector process $(if $(APPLY),,--dry-run) --no-progress-bar

stop: ## Stop the container without deleting it
	$(COMPOSE) stop

purge: ## Remove the container, its volumes and the image built for it
	$(COMPOSE) down --volumes --remove-orphans --rmi local
