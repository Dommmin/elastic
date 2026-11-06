#!/usr/bin/env bash
#  tools/ckad/index.sh — generuje spis zadań w docs/ckad/README.md (po markerze).
#  Uruchom po dodaniu albo zmianie zadania: bash tools/ckad/index.sh
source "$(dirname "$0")/lib.sh"

readme="${CKAD_ROOT}/docs/ckad/README.md"
marker='<!-- spis generowany: tools/ckad/index.sh -->'

table="$(
  echo "| # | Zadanie | Domena | Limit |"
  echo "|---|---|---|---|"
  for f in "${CKAD_ROOT}"/docs/ckad/[0-9][0-9]-*.md; do
    nn="$(basename "${f}" | cut -c1-2)"
    title="$(head -1 "${f}" | sed -e 's/^# *//' -e 's/^[0-9][0-9]\. *//')"
    domain="$(sed -n 's/.*\*\*Domena:\*\* *\([^·]*\)·.*/\1/p' "${f}" | head -1 | sed 's/ *$//')"
    echo "| ${nn} | [${title}]($(basename "${f}")) | ${domain} | $(task_minutes "${f}") min |"
  done
)"

awk -v marker="${marker}" '{ print } $0 == marker { exit }' "${readme}" > "${readme}.tmp"
printf '\n%s\n' "${table}" >> "${readme}.tmp"
mv "${readme}.tmp" "${readme}"
echo "Spis: $(printf '%s\n' "${table}" | tail -n +3 | wc -l | tr -d ' ') zadań."
