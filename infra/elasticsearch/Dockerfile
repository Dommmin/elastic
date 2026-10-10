# ============================================================================
#  Elasticsearch + pluginy analizy tekstu
#
#  DLACZEGO własny obraz, a nie gotowy:
#  pluginy w ES instaluje się DO OBRAZU — nie da się ich dodać w działającym
#  kontenerze. A bez nich nie ma polskiego stemmingu.
#
#  UWAGA (lekcja na przyszłość): wersja pluginu musi zgadzać się z wersją ES
#  co do znaku. elasticsearch-plugin odmówi instalacji przy niezgodności —
#  i dobrze, bo plugin z inną wersją Lucene uszkodziłby indeksy.
#  To najczęstsza przyczyna nieudanego upgrade'u Elasticsearcha.
# ============================================================================
ARG ES_VERSION
FROM docker.elastic.co/elasticsearch/elasticsearch:${ES_VERSION}

# analysis-stempel — polski stemmer (algorytm Stempel/Egothor).
#   Sprowadza "butów", "butami", "buty" do wspólnego rdzenia.
# analysis-icu — normalizacja Unicode i sortowanie zgodne z regułami języka
#   (ważne dla polskich znaków: ą, ć, ę, ł, ń, ó, ś, ź, ż).
RUN elasticsearch-plugin install --batch analysis-stempel \
 && elasticsearch-plugin install --batch analysis-icu

# --- konfiguracja WBUDOWANA w obraz (ETAP D) --------------------------------
# Lokalnie compose montuje te pliki z repo (bind-mount przykrywa to, co jest
# w obrazie — edycja synonimów działa bez rebuildu). Na serwerze repo NIE MA:
# obraz przychodzi z rejestru i musi mieć wszystko w sobie. Ścieżki są
# identyczne jak punkty montowania w compose.yaml — dlatego oba tryby działają
# bez żadnych warunków.
COPY --chown=1000:0 analysis/ /usr/share/elasticsearch/config/analysis/
COPY --chmod=0755 init-storage.sh setup-security.sh /usr/local/bin/
