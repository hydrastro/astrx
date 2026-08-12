# astrx-suite — deployment convenience targets.
#
# Works from anywhere: it locates the suite-root docker-compose.yml relative to
# this Makefile, so `make -C deploy up` and `cd deploy && make up` both work.

HERE    := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
ROOT    := $(abspath $(HERE)/..)
COMPOSE := docker compose -f $(ROOT)/docker-compose.yml

.DEFAULT_GOAL := help
.PHONY: help config build up down restart ps logs onions verify-tor \
        crawl-onion crawl-web index tracker check test clean

help: ## Show this help.
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n",$$1,$$2}'

config: ## Validate + render the fully-resolved compose config.
	$(COMPOSE) config

build: ## Build every image (one cargo build; five images cut from it).
	$(COMPOSE) build

up: ## Build (if needed) and start the published stack in the background.
	$(COMPOSE) up -d

down: ## Stop and remove containers + networks (volumes are kept).
	$(COMPOSE) down

restart: ## Recreate the running stack.
	$(COMPOSE) up -d --force-recreate

ps: ## Show the stack's containers.
	$(COMPOSE) ps

logs: ## Follow logs (make logs S=websearch-serve to scope to one service).
	$(COMPOSE) logs -f $(S)

onions: ## Print the generated .onion address of every published service.
	@$(COMPOSE) exec -T tor sh -c \
	  'for f in /var/lib/tor/*/hostname; do \
	     printf "%-26s %s\n" "$$(basename $$(dirname $$f))" "$$(cat $$f)"; done'

verify-tor: ## Syntax-check torrc inside the tor image.
	$(COMPOSE) run --rm tor --verify-config

crawl-onion: ## Start the darknet crawler (Tor SOCKS egress only).
	COMPOSE_PROFILES=crawl-onion $(COMPOSE) up -d onioncrawler-crawl

crawl-web: ## Start the clearnet crawler (needs real egress).
	COMPOSE_PROFILES=crawl-web $(COMPOSE) up -d websearch-crawl

index: ## Start the torrentds DHT harvester (needs real egress).
	COMPOSE_PROFILES=index-dht $(COMPOSE) up -d torrentds-index

tracker: ## Start the BitTorrent trackers.
	COMPOSE_PROFILES=tracker $(COMPOSE) up -d torrentds-tracker

check: ## Ask suitedash whether every service is UP (exit non-zero if not).
	$(COMPOSE) exec -T suitedash /suitedash --check

test: ## Run the full workspace test suite + lint gate on the host.
	cd $(ROOT) && bash install_rust_suite.sh --ci

clean: ## Remove containers, networks AND volumes (DESTROYS the .onion keys).
	$(COMPOSE) down -v
