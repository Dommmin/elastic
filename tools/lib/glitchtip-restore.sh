# Sprzątanie GlitchTipa po testach, które go zatrzymują albo zawieszają (errors-smoke).
# Przerwanie w środku (Ctrl-C, SIGTERM, błąd) nie może zostawić GlitchTipa spauzowanego
# ani zatrzymanego: kolejny smoke wisiałby, a UI nie odpowiadało (RUNBOOK #076).
#
#   . tools/lib/glitchtip-restore.sh
#   GT_CONTAINER=marketplace-glitchtip-1
#   gt_install_exit_trap      # raz, na początku
#   gt_mark_touched           # tuż przed pierwszym pause/stop
#
# Dopóki nic nie oznaczono, wyjście nie dotyka dockera.
GT_TOUCHED=""

gt_mark_touched() { GT_TOUCHED=1; }

# Idempotentne: unpause na niespauzowanym i start na działającym kończą się bez skutku.
gt_restore() {
  [ -n "$GT_TOUCHED" ] || return 0
  docker unpause "$GT_CONTAINER" >/dev/null 2>&1 || true
  docker start "$GT_CONTAINER" >/dev/null 2>&1 || true
  GT_TOUCHED=""
}

# EXIT łapie normalne wyjście i `exit` z INT/TERM; sprzątanie katalogu tymczasowego
# ($TMP) zostaje po stronie wołającego, bo ten skrypt go nie zna.
gt_install_exit_trap() {
  trap 'gt_restore; [ -n "${TMP:-}" ] && rm -rf "$TMP"' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}
