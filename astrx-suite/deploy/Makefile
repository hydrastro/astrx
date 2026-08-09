# astrx-suite — deployment convenience targets.
#
# Works from anywhere: it locates the suite-root docker-compose.yml relative to
# this Makefile, so `make -C deploy up` and `cd deploy && make up` both work.

HERE    := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
ROOT    := $(abspath $(HERE)/..)
COMPOSE := docker compose -f $(ROOT)/docker-compose.yml

.DEFAULT_GOAL := help
.PHONY: help config build up down restart ps logs onions verify-tor \
        crawl-onion crawl-web index clean

help: ## Show this help.
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n",$$1,$$2}'

config: ## Validate + render the fully-resolved compose config.
	$(COMPOSE) config

build: ## Build all app images + the tor image (needs the python:3.11-slim base).
	$(COMPOSE) build

up: ## Build (if needed) and start the published stack in the background.
	$(COMPOSE) up -d

down: ## Stop and remove containers + networks (volumes are kept).
	$(COMPOSE) down

restart: ## Recreate the running stack.
	$(COMPOSE) up -d --force-recreate

ps: ## Show service status.
	$(COMPOSE) ps

logs: ## Follow logs (use SVC=tor to scope: make logs SVC=tor).
	$(COMPOSE) logs -f $(SVC)

onions: ## Print the generated .onion hostname for every published surface.
	@$(COMPOSE) exec tor sh -c \
	  'for d in /var/lib/tor/*/; do [ -f "$$d/hostname" ] && \
	    printf "%-22s %s\n" "$$(basename $$d)" "$$(cat $$d/hostname)"; done'

verify-tor: ## Parse-check deploy/torrc inside the tor image.
	$(COMPOSE) run --rm tor --verify-config

crawl-onion: ## One-off onion crawl (ARGS="--seed http://xxx.onion -v").
	$(COMPOSE) run --rm onioncrawler-crawl crawl --db /data/crawl.db \
	  --fetcher tor --tor-host tor --tor-port 9050 $(ARGS)

crawl-web: ## One-off clear-web crawl (ARGS="--scope-domain x.com https://x.com").
	$(COMPOSE) run --rm websearch-crawl crawl --db /var/lib/websearch/web.db $(ARGS)

index: ## Start the optional torrentds DHT harvester (clearnet).
	$(COMPOSE) --profile index up -d torrentds-index

clean: ## DANGER: stop the stack AND delete all named volumes (loses .onions!).
	$(COMPOSE) down -v
