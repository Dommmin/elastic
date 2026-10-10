#!/usr/bin/env bash
# ============================================================================
#  latest-image-tag.sh — SHA ostatniego UDANEGO buildu obrazów w CI
#
#  Obrazy powstają tylko dla commitów, które zmieniają apps/, infra/ itd.
#  (filtr `paths` w .github/workflows/images.yml). Commit z samą dokumentacją
#  nie ma własnych obrazów — więc "HEAD z main" to NIE jest poprawny tag.
# ============================================================================
set -euo pipefail
gh run list --repo Dommmin/elastic --workflow images.yml --branch main \
  --status success --limit 1 --json headSha -q '.[0].headSha'
