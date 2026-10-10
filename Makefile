# ============================================================================
#  Marketplace Search Platform — interfejs do wszystkiego
#  `make` lub `make help` wypisuje listę komend.
# ============================================================================

SHELL := /bin/bash
.DEFAULT_GOAL := help

include .env
export

DC       := docker compose
ES_URL   := http://localhost:$(ES_PORT)
ES_AUTH  := elastic:$(ELASTIC_PASSWORD)
CURL_ES  := curl -sS -u $(ES_AUTH)

.PHONY: help
help: ## Pokaż tę pomoc
	@echo ""
	@echo "  Marketplace Search Platform"
	@echo "  ─────────────────────────────────────────────────────────────"
	@awk 'BEGIN {FS = ":.*##"} \
		/^## / { printf "\n  \033[1m%s\033[0m\n", substr($$0, 4); next } \
		/^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2 }' \
		$(MAKEFILE_LIST)
	@echo ""

## Stack
.PHONY: init
init: ## Pierwsze uruchomienie: .env + build obrazów
	@test -f .env || (cp .env.example .env && echo "Utworzono .env z .env.example")
	@python3 tools/render-rabbitmq-definitions.py
	$(DC) build
	@echo "Gotowe. Teraz: make up"

.PHONY: doctor
doctor: ## Sprawdź, czy środowisko udźwignie stack (RAM, CPU, dysk)
	@bash tools/doctor.sh default

.PHONY: doctor-cluster
doctor-cluster: ## To samo, ale dla klastra 3-nodowego
	@bash tools/doctor.sh cluster

.PHONY: up
up: ## Start (profil default: 1 node ES)
	@bash tools/doctor.sh default
	@python3 tools/render-rabbitmq-definitions.py
	$(DC) up -d
	@$(MAKE) --no-print-directory wait

.PHONY: up-cluster
up-cluster: ## Start klastra 3-nodowego (tryb docelowy, D-12)
	@bash tools/doctor.sh cluster
	@python3 tools/render-rabbitmq-definitions.py
	$(DC) --profile cluster up -d
	@$(MAKE) --no-print-directory wait

.PHONY: up-apps
up-apps: ## Start z aplikacjami (Laravel + Symfony)
	@bash tools/doctor.sh cluster apps
	@python3 tools/render-rabbitmq-definitions.py
	$(DC) --profile cluster --profile apps up -d
	@$(MAKE) --no-print-directory wait

.PHONY: wait
wait: ## Czekaj aż klaster odpowie
	@echo -n "Czekam na Elasticsearch"
	@for i in $$(seq 1 60); do \
		if $(CURL_ES) $(ES_URL)/_cluster/health >/dev/null 2>&1; then echo " OK"; break; fi; \
		echo -n "."; sleep 2; \
	done
	@$(MAKE) --no-print-directory es-health

.PHONY: down
down: ## Zatrzymaj (dane zostają)
	$(DC) --profile cluster --profile apps --profile obs --profile tools down

.PHONY: nuke
nuke: ## Zatrzymaj i USUŃ wszystkie dane (wolumeny)
	@read -p "Usunąć WSZYSTKIE dane (ES, Postgres, RabbitMQ)? [y/N] " ok; \
		[ "$$ok" = "y" ] || exit 1
	$(DC) --profile cluster --profile apps --profile obs --profile tools down -v

.PHONY: ps
ps: ## Stan kontenerów
	$(DC) ps

.PHONY: logs
logs: ## Logi (make logs s=elasticsearch)
	$(DC) logs -f --tail=200 $(s)

.PHONY: sh
sh: ## Shell w kontenerze (make sh s=es01)
	$(DC) exec $(s) bash

## Elasticsearch — diagnostyka
.PHONY: es-health
es-health: ## Zdrowie klastra + lista indeksów
	@echo "── CLUSTER ────────────────────────────────────────────────────"
	@$(CURL_ES) "$(ES_URL)/_cluster/health?pretty"
	@echo "── NODES ──────────────────────────────────────────────────────"
	@$(CURL_ES) "$(ES_URL)/_cat/nodes?v&h=name,node.role,master,heap.percent,ram.percent,cpu,load_1m,disk.used_percent"
	@echo "── INDICES ────────────────────────────────────────────────────"
	@$(CURL_ES) "$(ES_URL)/_cat/indices?v&s=index&h=health,status,index,pri,rep,docs.count,docs.deleted,store.size"

.PHONY: es-shards
es-shards: ## Rozmieszczenie shardów (UNASSIGNED na górze)
	@$(CURL_ES) "$(ES_URL)/_cat/shards?v&s=state,index&h=index,shard,prirep,state,docs,store,node,unassigned.reason"

.PHONY: es-explain
es-explain: ## DLACZEGO shard nie jest przypisany (klaster yellow/red)
	@$(CURL_ES) "$(ES_URL)/_cluster/allocation/explain?pretty" -H 'Content-Type: application/json' -d '{}'

.PHONY: es-hot
es-hot: ## Co robi CPU w tej chwili
	@$(CURL_ES) "$(ES_URL)/_nodes/hot_threads"

.PHONY: es-tasks
es-tasks: ## Długo działające zadania (reindex, update_by_query)
	@$(CURL_ES) "$(ES_URL)/_cat/tasks?v&detailed"

.PHONY: es-breakers
es-breakers: ## Stan circuit breakerów
	@$(CURL_ES) "$(ES_URL)/_nodes/stats/breaker?pretty&filter_path=nodes.*.name,nodes.*.breakers.*.limit_size,nodes.*.breakers.*.estimated_size,nodes.*.breakers.*.tripped"

.PHONY: es-threadpool
es-threadpool: ## Kolejki i odrzucenia (diagnoza błędów 429)
	@$(CURL_ES) "$(ES_URL)/_cat/thread_pool/search,write,get?v&h=node_name,name,active,queue,rejected,completed"

.PHONY: es-analyze
es-analyze: ## Test analizatora (make es-analyze t="butów do biegania" a=polish)
	@$(CURL_ES) -X POST "$(ES_URL)/_analyze?pretty" -H 'Content-Type: application/json' \
		-d '{"analyzer":"$(or $(a),standard)","text":"$(t)"}'

.PHONY: es-slowlog-on
es-slowlog-on: ## Loguj KAŻDE zapytanie do products-search (próg 0ms) — dowody w ETAPIE 7
	@$(CURL_ES) -X PUT "$(ES_URL)/products-search/_settings" -H 'Content-Type: application/json' \
		-d '{"index.search.slowlog.threshold.query.trace":"0ms","index.search.slowlog.include.user":true}'
	@echo ""

.PHONY: es-slowlog-off
es-slowlog-off: ## Wyłącz slowlog-wszystkiego (przywróć domyślne progi)
	@$(CURL_ES) -X PUT "$(ES_URL)/products-search/_settings" -H 'Content-Type: application/json' \
		-d '{"index.search.slowlog.threshold.query.trace":null,"index.search.slowlog.include.user":null}'
	@echo ""

.PHONY: es-slowlog
es-slowlog: ## Zapytania ze slowloga z ostatnich N sekund (make es-slowlog since=60s)
	@$(DC) logs --no-log-prefix --since $(or $(since),60s) es01 es02 es03 2>/dev/null \
		| grep 'index_search_slowlog' \
		| python3 tools/slowlog-summary.py

.PHONY: search-proof
search-proof: ## DoD ETAP 7: które zapytania do ES wywołuje każda akcja na /search (slowlog)
	@bash tools/search-slowlog-proof.sh

.PHONY: certs-reset
certs-reset: ## Wygeneruj certyfikaty TLS od nowa
	$(DC) down
	docker volume rm $(COMPOSE_PROJECT_NAME)_es-certs || true
	$(DC) up -d es-init

## RabbitMQ
.PHONY: mq-status
mq-status: ## Kolejki: głębokość, konsumenci, niepotwierdzone
	@$(DC) exec rabbitmq rabbitmqctl list_queues \
		name type messages messages_ready messages_unacknowledged consumers

.PHONY: mq-publish
mq-publish: ## Testowa wiadomość (make mq-publish rk=offer.created)
	@$(DC) exec rabbitmq rabbitmqadmin \
		--username $(RABBITMQ_USER) --password $(RABBITMQ_PASSWORD) \
		publish message --exchange marketplace.events \
		--routing-key $(or $(rk),offer.created) \
		--payload '{"id":"test-001","type":"$(or $(rk),offer.created)","data":{"hello":"world"}}'

.PHONY: mq-get
mq-get: ## Podejrzyj wiadomość bez usuwania (make mq-get q=search.product.sync)
	@# --ack-mode jawnie: domyślne w rabbitmqadmin v2 to ack_requeue_false,
	@# czyli "get" KASUJE wiadomości z kolejki (RUNBOOK #027).
	@$(DC) exec rabbitmq rabbitmqadmin \
		--username $(RABBITMQ_USER) --password $(RABBITMQ_PASSWORD) \
		get messages --queue $(or $(q),search.product.sync) --count 5 \
		--ack-mode ack_requeue_true

## Dane
.PHONY: seed
seed: ## Seeduje katalog pod wyszukiwarkę (make seed n=1500), realnym pipeline'em outboxu
	$(DC) exec catalog-app php artisan marketplace:seed --n=$(or $(n),1500)

.PHONY: eval
eval: ## nDCG@10 na zapytaniach kontrolnych (tests/relevance/queries.yaml)
	$(DC) exec catalog-app php artisan search:eval

## Bazy danych
.PHONY: psql
psql: ## Konsola Postgresa (make psql db=catalog)
	$(DC) exec postgres psql -U $(POSTGRES_USER) -d $(or $(db),catalog)

.PHONY: redis-cli
redis-cli: ## Konsola Redisa
	$(DC) exec redis redis-cli -a $(REDIS_PASSWORD)

## Narzędzia
.PHONY: smoke
smoke: ## Test dymny: czy stack FAKTYCZNIE działa (DoD etapów 1-3)
	@bash tools/smoke-test.sh

.PHONY: versions-check
versions-check: ## Sprawdź, czy przypięte wersje są aktualne
	@bash tools/versions-check.sh

.PHONY: urls
urls: ## Wypisz adresy usług
	@echo ""
	@echo "  Kibana          http://localhost:$(KIBANA_PORT)      (elastic / $(ELASTIC_PASSWORD))"
	@echo "  Elasticsearch   http://localhost:$(ES_PORT)"
	@echo "  RabbitMQ UI     http://localhost:$(RABBITMQ_UI_PORT)     ($(RABBITMQ_USER) / $(RABBITMQ_PASSWORD))"
	@echo "  Postgres        localhost:$(POSTGRES_PORT)"
	@echo "  Redis           localhost:$(REDIS_PORT)"
	@echo ""

## VPS (ETAP D — docs/08-PLAN-ETAP-D-VPS.md)
.PHONY: vps-verify
vps-verify: ## Weryfikacja ETAPU D z Maca (make vps-verify faza=images|access|all)
	@bash tools/vps/verify.sh $(or $(faza),all)

.PHONY: prod-check
prod-check: ## Obrazy prod z czystego klonu + nakładka compose.prod (przed pushem)
	@bash tools/vps/prod-image-check.sh
	@bash tools/vps/compose-prod-check.sh

# Komendy na serwerze — przez SSH, w /opt/marketplace. COMPOSE_FILE
# i COMPOSE_PROFILES są w .env serwera, więc `docker compose` wystarcza.
VPS      ?= elastic-vps
VPS_DC   := ssh $(VPS) cd /opt/marketplace '&&' docker compose

.PHONY: prod-deploy
prod-deploy: ## Wdróż wersję na VPS (make prod-deploy tag=<SHA>); rollback = starszy SHA
	@test -n "$(tag)" || (echo "Podaj tag: make prod-deploy tag=\$$(git rev-parse origin/main)"; exit 1)
	@bash tools/vps/deploy.sh $(tag)

.PHONY: prod-ps
prod-ps: ## Stan kontenerów na VPS
	@$(VPS_DC) ps --format "'table {{.Service}}\t{{.Image}}\t{{.Status}}'"

.PHONY: prod-logs
prod-logs: ## Logi na VPS (make prod-logs s=catalog-app)
	@$(VPS_DC) logs --tail 100 $(s)

.PHONY: prod-seed
prod-seed: ## Seed katalogu na VPS (make prod-seed n=1500)
	@$(VPS_DC) exec -T catalog-app php artisan marketplace:seed --n=$(or $(n),1500)

.PHONY: prod-eval
prod-eval: ## nDCG@10 na VPS
	@$(VPS_DC) exec -T catalog-app php artisan search:eval
