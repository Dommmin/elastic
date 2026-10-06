#!/usr/bin/env bash
# ============================================================================
#  make doctor — kontrola środowiska PRZED startem stacku
#
#  Po co to istnieje: gdy Elasticsearchowi zabraknie pamięci, kontener ginie
#  z kodem 137 (SIGKILL od OOM killera). W logach nie ma NIC sensownego —
#  proces po prostu znika w połowie startu. Jeśli nie wiesz, że 137 = OOM,
#  możesz stracić godzinę na szukanie błędu w konfiguracji, którego nie ma.
#
#  Ten skrypt sprawdza warunki, zanim się to stanie.
# ============================================================================
set -uo pipefail

PROFILE="${1:-default}"
RED='\033[0;31m'; YEL='\033[0;33m'; GRN='\033[0;32m'; BLD='\033[1m'; NC='\033[0m'
ERRORS=0; WARNINGS=0

ok()   { echo -e "  ${GRN}✓${NC} $*"; }
warn() { echo -e "  ${YEL}!${NC} $*"; WARNINGS=$((WARNINGS+1)); }
err()  { echo -e "  ${RED}✗${NC} $*"; ERRORS=$((ERRORS+1)); }

echo -e "\n${BLD}  Kontrola środowiska (profil: ${PROFILE})${NC}"
echo "  ─────────────────────────────────────────────────────────────"

# --- Docker działa? ---------------------------------------------------------
if ! docker info >/dev/null 2>&1; then
  err "Docker nie odpowiada. Uruchom Docker Desktop."
  exit 1
fi

# --- pamięć -----------------------------------------------------------------
MEM_BYTES=$(docker info --format '{{.MemTotal}}' 2>/dev/null || echo 0)
MEM_GB=$(awk -v b="$MEM_BYTES" 'BEGIN{printf "%.1f", b/1073741824}')

# Ile realnie potrzebujemy? Heap to tylko część — ES bierze drugie tyle na
# off-heap i cache Lucene. Do tego Kibana, Postgres, Rabbit, Redis.
HEAP_NUM=$(echo "${ES_HEAP:-2g}" | tr -d 'gG')
case "$PROFILE" in
  cluster) NODES=3 ;;
  *)       NODES=1 ;;
esac
NEEDED=$(awk -v h="$HEAP_NUM" -v n="$NODES" 'BEGIN{printf "%.1f", (h*1.6*n) + 3.5}')

echo -e "\n  ${BLD}Pamięć${NC}"
echo "    Docker VM:  ${MEM_GB} GB"
echo "    Potrzeba:   ~${NEEDED} GB  (${NODES} x ES z heapem ${ES_HEAP:-2g} + Kibana + reszta)"

if awk -v m="$MEM_GB" -v n="$NEEDED" 'BEGIN{exit !(m < n)}'; then
  err "Za mało pamięci. Elasticsearch zginie z kodem 137 (OOM), bez czytelnego błędu."
  echo ""
  echo "    Masz dwie drogi:"
  echo "      1) Docker Desktop -> Settings -> Resources -> Memory: podnieś do $(awk -v n="$NEEDED" 'BEGIN{printf "%.0f", n+2}') GB"
  echo "      2) Zmniejsz ES_HEAP w .env (patrz tabela w pliku) i/lub użyj 'make up' zamiast 'make up-cluster'"
  echo ""
else
  ok "Pamięci wystarczy"

  # Całkowita pamięć VM to nie to samo co WOLNA pamięć: inne projekty
  # działające równolegle zjadają ją po cichu, a ES zabity przez OOM killera
  # VM-a (nie limit kontenera!) znika bez żadnego logu zamknięcia — patrz
  # RUNBOOK #025. Liczymy więc też to, co zajmują kontenery spoza tego stacku.
  OTHERS_GB=$(docker stats --no-stream --format '{{.Name}}\t{{.MemUsage}}' 2>/dev/null \
    | grep -v "^${COMPOSE_PROJECT_NAME:-marketplace}-" \
    | awk -F'\t' '{split($2, a, " "); v=a[1];
        if (v ~ /GiB/) {sub("GiB","",v); s+=v}
        else if (v ~ /MiB/) {sub("MiB","",v); s+=v/1024}}
        END {printf "%.1f", s}')
  FREE_GB=$(awk -v m="$MEM_GB" -v o="$OTHERS_GB" 'BEGIN{printf "%.1f", m-o}')
  echo "    Inne projekty zajmują już: ${OTHERS_GB} GB  ->  zostaje ${FREE_GB} GB"
  if awk -v f="$FREE_GB" -v n="$NEEDED" 'BEGIN{exit !(f < n)}'; then
    warn "Po odjęciu innych działających projektów pamięci NIE wystarczy — ES może ginąć z OOM bez śladu w logach. Zatrzymaj tamte projekty albo obniż ES_HEAP/ES_MEM_LIMIT w .env."
  fi
fi

# --- CPU --------------------------------------------------------------------
NCPU=$(docker info --format '{{.NCPU}}' 2>/dev/null || echo 0)
echo -e "\n  ${BLD}CPU${NC}"
echo "    Docker VM:  ${NCPU} rdzeni"
[ "$NCPU" -ge 4 ] && ok "Wystarczy" || warn "Mało rdzeni — indeksowanie i merge będą wolne"

# --- vm.max_map_count -------------------------------------------------------
# ES używa mmap do czytania segmentów Lucene. Domyślny limit systemowy jest
# za niski i node nie wstanie ("max virtual memory areas ... too low").
echo -e "\n  ${BLD}Ustawienia jądra (VM Dockera)${NC}"
MAX_MAP=$(docker run --rm --privileged alpine sysctl -n vm.max_map_count 2>/dev/null || echo "?")
if [ "$MAX_MAP" = "?" ]; then
  warn "Nie udało się odczytać vm.max_map_count"
elif [ "$MAX_MAP" -ge 262144 ]; then
  ok "vm.max_map_count = ${MAX_MAP}"
else
  err "vm.max_map_count = ${MAX_MAP}, wymagane min. 262144 — ES nie wstanie"
fi

# --- miejsce na dysku -------------------------------------------------------
echo -e "\n  ${BLD}Dysk${NC}"
DISK_AVAIL=$(docker run --rm alpine df -BG /  2>/dev/null | awk 'NR==2{gsub("G","",$4); print $4}')
if [ -n "${DISK_AVAIL:-}" ]; then
  echo "    Wolne w VM:  ${DISK_AVAIL} GB"
  if [ "$DISK_AVAIL" -lt 20 ]; then
    err "Mało miejsca. Powyżej 95% zajęcia ES przełącza indeksy w tryb READ-ONLY (flood stage)."
  elif [ "$DISK_AVAIL" -lt 50 ]; then
    warn "Wystarczy na teraz, ale 5 mln dokumentów (ETAP 8) tego nie zmieści"
  else
    ok "Miejsca wystarczy"
  fi
fi

# --- porty na hoście --------------------------------------------------------
# Kilka stacków dockerowych naraz (inne projekty) to norma. `docker compose up`
# przy zajętym porcie startuje stack W POŁOWIE: część kontenerów wstaje, a jeden
# pada z "Bind for 0.0.0.0:5672 failed: port is already allocated" — reszta
# zostaje w stanie "Created". Lepiej wiedzieć PRZED startem (RUNBOOK #022).
#
# Port na hoście zmienia TYLKO to, jak Ty łączysz się z hosta (curl, Kibana,
# psql). Kontenery rozmawiają między sobą po sieci dockerowej (es01:9200,
# rabbitmq:5672) — zmiana ES_PORT w .env nie dotyka aplikacji.
PROJECT="${COMPOSE_PROJECT_NAME:-marketplace}"
CONTAINER_PORTS=$(docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null)

check_port() {
  local var="$1" severity="$2"
  local port="${!var:-}"
  [ -z "$port" ] && return

  # Własne kontenery (stack już działa) to nie kolizja.
  if echo "$CONTAINER_PORTS" | grep "^${PROJECT}-" | grep -q ":${port}->"; then
    ok "${var}=${port} (używa go już ten stack)"
    return
  fi

  local owner
  owner=$(echo "$CONTAINER_PORTS" | grep -v "^${PROJECT}-" | grep ":${port}->" | cut -f1 | head -1)
  if [ -n "$owner" ]; then
    "$severity" "${var}=${port} zajęty przez kontener '${owner}' (inny projekt)"
    PORT_CONFLICTS=$((PORT_CONFLICTS+1))
    return
  fi

  local process
  process=$(lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | awk 'NR==2{print $1" (PID "$2")"}')
  if [ -n "$process" ]; then
    "$severity" "${var}=${port} zajęty przez proces na hoście: ${process}"
    PORT_CONFLICTS=$((PORT_CONFLICTS+1))
    return
  fi

  ok "${var}=${port}"
}

PORT_CONFLICTS=0
echo -e "\n  ${BLD}Porty na hoście${NC}"
for var in POSTGRES_PORT REDIS_PORT ES_PORT KIBANA_PORT RABBITMQ_PORT RABBITMQ_UI_PORT; do
  check_port "$var" err
done

# Porty aplikacji liczą się tylko przy profilu `apps` (make up-apps).
if [ "${2:-}" = "apps" ]; then
  for var in CATALOG_HTTP_PORT CATALOG_HTTPS_PORT VITE_PORT; do
    check_port "$var" err
  done
fi

if [ "$PORT_CONFLICTS" -gt 0 ]; then
  echo ""
  echo "    Zmień kolidujące porty w .env (np. ES_PORT=19200) albo zatrzymaj tamten projekt."
  echo "    To zmienia tylko dostęp z hosta — kontenery i tak gadają po sieci dockerowej."
fi

# --- podsumowanie -----------------------------------------------------------
echo ""
echo "  ─────────────────────────────────────────────────────────────"
if [ "$ERRORS" -gt 0 ]; then
  echo -e "  ${RED}${BLD}Błędów: ${ERRORS}${NC}, ostrzeżeń: ${WARNINGS}. Napraw powyższe przed startem.\n"
  exit 1
elif [ "$WARNINGS" -gt 0 ]; then
  echo -e "  ${YEL}Ostrzeżeń: ${WARNINGS}${NC}. Można startować.\n"
else
  echo -e "  ${GRN}${BLD}Wszystko w porządku.${NC}\n"
fi
