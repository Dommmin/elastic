# ============================================================================
#  GlitchTip ze skryptami etapu 11b w środku (ETAP 11b, ADR-0009)
#
#  Lokalnie skrypty były montowane z repo; na serwerze repo nie ma (reguła „zero
#  bind-mountów”), więc jadą w obrazie. Jeden obraz obsługuje trzy usługi:
#    glitchtip           -> serwer (CMD obrazu bazowego) + healthcheck.py
#    glitchtip-db-setup  -> python /opt/marketplace/setup_db.py   (rola i baza)
#    glitchtip-setup     -> guard.py + bootstrap.py               (projekty, klucze)
#  Dev i prod używają TEGO SAMEGO obrazu. Obraz nie zawiera sekretów: skrypty czytają
#  wszystko ze zmiennych środowiskowych, a guard.py zna tylko publiczne wartości dev.
# ============================================================================
ARG GLITCHTIP_VERSION
FROM glitchtip/glitchtip:${GLITCHTIP_VERSION}

COPY --chmod=0755 healthcheck.py guard.py bootstrap.py setup_db.py /opt/marketplace/
