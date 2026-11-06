#!/usr/bin/env bash
#  tools/ckad/scores.sh — Twoje wyniki: ostatnie podejścia i co powtórzyć.
#  Zadanie „do powtórki” = ostatnie podejście nieudane albo wolniejsze niż limit.
source "$(dirname "$0")/lib.sh"

[[ -s "${SCORES_FILE}" ]] || { echo "Brak wyników (${SCORES_FILE}). Zacznij: make ckad-start t=01"; exit 0; }

echo "Ostatnie 15 podejść:"
tail -n 15 "${SCORES_FILE}" | awk -F'\t' '{ printf "  %s  %-5s %-6s %s/%s min\n", $1, $2, $3, $4, $5 }'

echo
echo "Do powtórki (ostatnie podejście nieudane albo za wolne):"
awk -F'\t' '$2 != "exam" { last[$2] = $3 "\t" $4 "\t" $5 }
  END { for (t in last) { split(last[t], a, "\t"); if (a[1] != "ok" || a[2] + 0 > a[3] + 0) print t } }' "${SCORES_FILE}" \
  | sort | while read -r t; do echo "  ${t}  $(head -1 "$(task_doc "${t}")" | sed 's/^# *//')"; done

echo
done_count=$(awk -F'\t' '$2 != "exam" && $3 == "ok" { print $2 }' "${SCORES_FILE}" | sort -u | wc -l | tr -d ' ')
all_count=$(ls "${CKAD_ROOT}"/tools/ckad/tasks/[0-9][0-9].sh | wc -l | tr -d ' ')
echo "Zaliczone przynajmniej raz: ${done_count}/${all_count}"
