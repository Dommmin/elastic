#!/bin/sh
# ============================================================================
#  Start kontenera catalog (prod): cache konfiguracji Laravela W RUNTIME.
#
#  Dlaczego nie w buildzie (tak było): `php artisan config:cache` zamraża
#  wartości env() w bootstrap/cache/config.php. W czasie buildu nie ma
#  zmiennych z compose (DB_CONNECTION, hasła...), więc w cache lądowały
#  wartości domyślne — m.in. DB_CONNECTION=sqlite. A gdy cache istnieje,
#  Laravel w ogóle NIE czyta env() — zmienne z compose byłyby po cichu
#  ignorowane. Tu, przy starcie, zmienne już są.
# ============================================================================
set -e

php artisan config:cache --no-interaction >/dev/null

exec "$@"
