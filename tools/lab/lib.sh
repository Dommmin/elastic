#!/usr/bin/env bash
# ============================================================================
#  lib.sh — wspólne funkcje skryptów laboratorium (ETAP 14); do wczytania przez `source`.
#  Hasło es-lab (ES_LAB_PASSWORD) czytamy z .env i nie wypisujemy; do curl trafia przez `-K` (nie trafia do argumentów procesu).
# ============================================================================

step() { printf '\n━━ %s\n' "$*"; }
die()  { printf 'BŁĄD: %s\n' "$*" >&2; exit 1; }
envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[ -f .env ] || die "brak .env"
LAB_PORT="$(envval ES_LAB_PORT)"
LAB_URL="http://127.0.0.1:${LAB_PORT:-19201}"
export LAB_URL
LAB_PW="$(envval ES_LAB_PASSWORD)"
[ -n "${LAB_PW}" ] || die "ES_LAB_PASSWORD nie jest ustawione w .env (echo \"ES_LAB_PASSWORD=\$(openssl rand -hex 16)\" >> .env)"
case "${LAB_PW}" in *\"*|*\\*) die "ES_LAB_PASSWORD zawiera cudzysłów lub ukośnik: nieobsługiwane w konfiguracji curl -K";; esac

# lab_es <argumenty curl…> — wywołanie es-lab jako `elastic`; podaj pełny URL: "${LAB_URL}/_cluster/health"
lab_es() { curl -sS -K <(printf 'user = "elastic:%s"\n' "${LAB_PW}") "$@"; }

# need_free_mb <MB> — odmawia, gdy wolna pamięć Dockera (VM minus użycie kontenerów) jest mniejsza niż <MB>
need_free_mb() {
  local min="$1" total used free
  total=$(( $(docker info --format '{{.MemTotal}}') / 1024 / 1024 ))
  used=$(docker stats --no-stream --format '{{.MemUsage}}' | awk '{
    split($1, a, /[A-Za-z]+/); u=$1; sub(/^[0-9.]+/, "", u); v=a[1]
    if (u=="GiB") v*=1024; else if (u=="KiB"||u=="kB") v/=1024; else if (u=="B") v/=1048576
    s+=v } END { printf "%d", s }')
  free=$(( total - used ))
  echo "  Docker: ${total} MB, użyte przez kontenery: ${used} MB, zapas: ${free} MB"
  if [ "${free}" -lt "${min}" ]; then
    die "za mało pamięci (zapas ${free} MB < ${min} MB); zwolnij własną Kibanę: docker compose stop kibana (~1,1 GB). Cudzych kontenerów nie zatrzymuję"
  fi
}
