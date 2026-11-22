#!/usr/bin/env bash
# ============================================================================
#  lab-mode.sh on|off — tryb lab (ETAP 14): Docker VM nie mieści naraz głównego klastra i es-lab
#  on : zatrzymuje WŁASNE usługi zależne od głównego ES (lista poniżej); zostają postgres, redis, rabbitmq, minio,
#       catalog-app, catalog-vite, registry-mock
#  off: zatrzymuje es-lab i startuje te same usługi w kolejności zależności
#  Nie dotyka kontenerów innych projektów.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

step() { printf '\n━━ %s\n' "$*"; }
die()  { printf 'BŁĄD: %s\n' "$*" >&2; exit 1; }

ES_NODES=(es01 es02 es03)
# procesy zależne od głównego ES (kolejność startu; stop w odwrotnej)
DEPENDENTS=(kibana search-consumer analytics-consumer seller360-consumer seller360-scheduler seller360-registry search-metrics outbox-publisher catalog-scheduler catalog-worker)

free_mb() {
  local total used
  total=$(( $(docker info --format '{{.MemTotal}}') / 1024 / 1024 ))
  used=$(docker stats --no-stream --format '{{.MemUsage}}' | awk '{
    u=$1; sub(/^[0-9.]+/, "", u); v=$1; sub(/[A-Za-z]+$/, "", v)
    if (u=="GiB") v*=1024; else if (u=="KiB"||u=="kB") v/=1024; else if (u=="B") v/=1048576
    s+=v } END { printf "%d", s }')
  echo "  Docker: ${total} MB, użyte: ${used} MB, zapas: $(( total - used )) MB"
}

main_health() {
  local pw
  pw="$(grep -E '^ELASTIC_PASSWORD=' .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')"
  curl -sS -K <(printf 'user = "elastic:%s"\n' "${pw}") "http://localhost:19200/_cluster/health?wait_for_status=yellow&timeout=1s" 2>/dev/null \
    | grep -oE '"status":"[a-z]+"' || true
}

case "${1:-}" in
  on)
    step "Zatrzymuję własne usługi zależne od głównego ES"
    rev=()
    for ((i=${#DEPENDENTS[@]}-1; i>=0; i--)); do rev+=("${DEPENDENTS[i]}"); done
    docker compose stop "${rev[@]}" "${ES_NODES[@]}"
    echo "  zatrzymane: ${rev[*]} ${ES_NODES[*]}"
    step "Pamięć po zatrzymaniu"
    free_mb
    ;;
  off)
    step "Zatrzymuję es-lab"
    docker compose --profile ml stop es-lab
    step "Start głównego klastra"
    docker compose start "${ES_NODES[@]}"
    ok=0
    for _ in $(seq 1 90); do
      case "$(main_health)" in *yellow*|*green*) ok=1; break;; esac
      sleep 4
    done
    [ "${ok}" = 1 ] || die "główny klaster nie osiągnął yellow w 360 s (docker compose logs es01)"
    step "Start Kibany i procesów"
    docker compose start "${DEPENDENTS[@]}"
    step "Stan końcowy"
    echo "  główny klaster: $(main_health)"
    free_mb
    ;;
  *) die "użycie: lab-mode.sh on|off" ;;
esac
