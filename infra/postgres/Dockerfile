# ============================================================================
#  Postgres z wbudowanymi skryptami inicjalizacyjnymi (ETAP D)
#
#  Lokalnie init/ jest montowany z repo; na serwerze repo nie ma, więc
#  skrypty jadą w obrazie. Uruchamiają się TYLKO przy pustym wolumenie
#  (pierwszy start) — to zachowanie oficjalnego obrazu, nie nasze.
# ============================================================================
ARG POSTGRES_VERSION
FROM postgres:${POSTGRES_VERSION}-alpine

COPY --chmod=0755 init/ /docker-entrypoint-initdb.d/
