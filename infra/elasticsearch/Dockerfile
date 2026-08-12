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
