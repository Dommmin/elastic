#!/usr/bin/env bash
# ============================================================================
#  Przygotowanie storage'u dla klastra:
#    1. certyfikaty TLS transportowego (node <-> node)
#    2. katalog repozytorium snapshotów
#
#  Oba wolumeny Dockera powstają jako własność root-a, a Elasticsearch działa
#  jako uid 1000. Bez chown-a dostaniesz AccessDeniedException — raz na
#  keystore, raz przy rejestracji repozytorium snapshotów.
#  To bardzo typowa pułapka Dockera i warto ją zobaczyć raz, świadomie.
#
#  DLACZEGO to jest potrzebne:
#  Gdy xpack.security.enabled=true, Elasticsearch WYMAGA szyfrowania warstwy
#  transportowej w klastrze wielonodowym. To bootstrap check — node po prostu
#  nie wstanie bez tego. I słusznie: bez TLS transportowego każdy, kto dostanie
#  się do sieci, może udawać node'a i dołączyć do klastra.
#
#  DWIE RÓŻNE WARSTWY TLS w Elasticsearchu — nie myl ich:
#    transport (9300) : node <-> node   — tutaj WŁĄCZONE
#    http      (9200) : klient <-> node — w dev WYŁĄCZONE (moduł 12 je włączy)
#
#  Skrypt jest idempotentny: przy kolejnym starcie widzi certy i kończy pracę.
# ============================================================================
set -euo pipefail

CERT_DIR=/certs
SNAPSHOT_DIR=/snapshots

# --- repozytorium snapshotów (ETAP 10) --------------------------------------
# Robimy to zawsze, bo wolumen mógł powstać później niż certyfikaty.
echo "[storage] Nadaję uprawnienia do ${SNAPSHOT_DIR}..."
chown -R 1000:0 "${SNAPSHOT_DIR}"
chmod 0775 "${SNAPSHOT_DIR}"

# --- certyfikaty ------------------------------------------------------------
if [ -f "${CERT_DIR}/es.p12" ]; then
  echo "[certs] Certyfikaty już istnieją w ${CERT_DIR} — pomijam generowanie."
  echo "[certs] Aby wygenerować od nowa: make certs-reset"
  exit 0
fi

echo "[certs] Generowanie CA..."
elasticsearch-certutil ca \
  --silent \
  --out "${CERT_DIR}/ca.p12" \
  --pass ""

echo "[certs] Generowanie certyfikatu node'ów (es01, es02, es03)..."
# Jeden certyfikat współdzielony przez wszystkie node'y, z SAN-ami na każdą nazwę.
# W produkcji dałbyś osobny certyfikat na node — tutaj upraszczamy świadomie,
# bo verification_mode=certificate i tak nie weryfikuje nazwy hosta.
elasticsearch-certutil cert \
  --silent \
  --ca "${CERT_DIR}/ca.p12" \
  --ca-pass "" \
  --out "${CERT_DIR}/es.p12" \
  --pass "" \
  --name es \
  --dns es01,es02,es03,localhost \
  --ip 127.0.0.1

# Skrypt działa jako root (świeży wolumen Dockera należy do root-a), więc na
# końcu musimy oddać pliki użytkownikowi `elasticsearch` (uid 1000, gid 0).
# Bez tego node ES wstanie z AccessDeniedException na keystore.
chown 1000:0 "${CERT_DIR}"/*.p12
chmod 0640 "${CERT_DIR}"/*.p12

echo "[certs] Gotowe:"
ls -la "${CERT_DIR}"
