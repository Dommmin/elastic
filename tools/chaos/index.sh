#!/usr/bin/env bash
#  tools/chaos/index.sh [--print] — spis scenariuszy w docs/chaos/README.md (po markerze).
#  Uruchom po dodaniu albo zmianie scenariusza: bash tools/chaos/index.sh
#  --print: tylko wypisz tabelę (test.sh porównuje ją z README).
source "$(dirname "$0")/lib.sh"

readme="${CHAOS_ROOT}/docs/chaos/README.md"
marker='<!-- spis generowany: tools/chaos/index.sh -->'

table="$(
  echo "| Scenariusz | Objaw | Alert | Limit |"
  echo "|---|---|---|---|"
  for f in "${CHAOS_SCENARIOS_DIR}"/*.sh; do
    s="$(basename "${f}" .sh)"
    (
      set +u
      # shellcheck source=/dev/null
      source "${f}"
      echo "| [${s}](${s}.md) | ${SUMMARY} | ${ALERT:--} | ${LIMIT_MIN} min |"
    )
  done
)"

if [[ "${1:-}" == "--print" ]]; then
  printf '%s\n' "${table}"
  exit 0
fi

awk -v marker="${marker}" '{ print } $0 == marker { exit }' "${readme}" > "${readme}.tmp"
printf '\n%s\n' "${table}" >> "${readme}.tmp"
mv "${readme}.tmp" "${readme}"
echo "Spis: $(printf '%s\n' "${table}" | tail -n +3 | wc -l | tr -d ' ') scenariuszy."
