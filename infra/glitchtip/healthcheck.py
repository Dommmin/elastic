"""Healthcheck GlitchTipa. Obraz nie ma curl ani wget, jest za to Python."""
import sys
import urllib.request

try:
    with urllib.request.urlopen("http://localhost:8000/_health/", timeout=3) as r:
        sys.exit(0 if r.status == 200 else 1)
except Exception:
    sys.exit(1)
