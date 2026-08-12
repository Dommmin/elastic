#!/usr/bin/env bash
# ============================================================================
#  make versions-check — porównuje wersje z .env z tym, co jest w rejestrach
#
#  ŚWIADOMIE nie aktualizuje niczego automatycznie. Automatyczny bump wersji
#  infrastruktury to proszenie się o niespodziankę w piątek po południu.
#  Ten skrypt ma dać WIEDZĘ, decyzja należy do Ciebie.
# ============================================================================
set -uo pipefail

GRN='\033[0;32m'; YEL='\033[0;33m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck disable=SC1091
set -a; source "${ROOT}/.env"; set +a

row() { # row <nazwa> <obecna> <najnowsza>
  if [ "$2" = "$3" ]; then
    printf "  ${GRN}✓${NC} %-16s %-12s ${DIM}aktualna${NC}\n" "$1" "$2"
  else
    printf "  ${YEL}↑${NC} %-16s %-12s ${YEL}-> %s${NC}\n" "$1" "$2" "$3"
  fi
}

echo -e "\n${BLD}  Wersje: .env vs rejestry${NC}"
echo "  ─────────────────────────────────────────────────────────────"
echo -e "  ${DIM}(pobieranie danych z sieci, chwilę to zajmie...)${NC}\n"

# --- Elasticsearch / Kibana (GitHub Releases) -------------------------------
ES_LATEST=$(curl -sS --max-time 20 "https://api.github.com/repos/elastic/elasticsearch/releases?per_page=20" 2>/dev/null \
  | python3 -c "
import json,sys,re
rs=[r['tag_name'].lstrip('v') for r in json.load(sys.stdin) if not r['prerelease']]
nine=[v for v in rs if v.startswith('9.')]
def k(v): return tuple(int(x) for x in re.findall(r'\d+', v)[:3])
print(sorted(nine, key=k)[-1] if nine else '?')" 2>/dev/null || echo "?")
row "Elasticsearch" "$ES_VERSION" "$ES_LATEST"
row "Kibana" "$KIBANA_VERSION" "$ES_LATEST"

# --- Node.js LTS ------------------------------------------------------------
# Celowo porównujemy do najnowszego LTS, nie do najnowszego w ogóle —
# linia Current łamie API i traci wsparcie po pół roku (docs/07-WERSJE.md).
NODE_LATEST=$(curl -sS --max-time 20 "https://nodejs.org/dist/index.json" 2>/dev/null \
  | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(next((x['version'].lstrip('v') for x in d if x['lts']), '?'))" 2>/dev/null || echo "?")
row "Node.js (LTS)" "$NODE_VERSION" "$NODE_LATEST"

# --- obrazy z Docker Hub ----------------------------------------------------
hub_latest() { # hub_latest <repo> <prefix major>
  curl -sS --max-time 20 "https://hub.docker.com/v2/repositories/library/$1/tags?page_size=100&ordering=last_updated" 2>/dev/null \
    | python3 -c "
import json,sys,re
pref='$2'
tags=[t['name'] for t in json.load(sys.stdin).get('results',[])]
vs=[t for t in tags if re.fullmatch(r'\d+\.\d+\.\d+', t) and t.startswith(pref)]
def k(v): return tuple(int(x) for x in v.split('.'))
print(sorted(vs,key=k)[-1] if vs else '?')" 2>/dev/null || echo "?"
}
row "PostgreSQL" "$POSTGRES_VERSION" "$(hub_latest postgres 18)"
row "RabbitMQ"   "$RABBITMQ_VERSION" "$(hub_latest rabbitmq 4)"
row "Redis"      "$REDIS_VERSION"    "$(hub_latest redis 8)"

# --- FrankenPHP (GitHub) ----------------------------------------------------
FP_LATEST=$(curl -sS --max-time 20 "https://api.github.com/repos/php/frankenphp/releases/latest" 2>/dev/null \
  | python3 -c "import json,sys; print(json.load(sys.stdin)['tag_name'].lstrip('v'))" 2>/dev/null || echo "?")
row "FrankenPHP" "$FRANKENPHP_VERSION" "$FP_LATEST"

echo ""
echo "  ─────────────────────────────────────────────────────────────"
echo -e "  ${DIM}Podbicie: zmień wersję w .env, potem 'make init && make up'.${NC}"
echo -e "  ${DIM}Przy zmianie MAJOR Elasticsearcha najpierw przeczytaj breaking changes.${NC}"
echo -e "  ${DIM}Podbijaj w OSOBNYM commicie — inaczej nie odróżnisz, co zepsuło build.${NC}\n"
