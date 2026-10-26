"""Strażnik bootstrapu GlitchTipa (ETAP 11b, ADR-0009): wartości z repo poza localhostem.

Klucze DSN, token API i hasła z `.env.example` są PUBLICZNE (leżą w repozytorium).
Poza localhostem bootstrap nie może z nich skorzystać, bo GlitchTip dostępny pod
prawdziwą domeną miałby znane każdemu klucze zapisu i token API. Czysty Python bez
zależności (uruchamiany przed `manage.py shell`, testowany na hoście).

Exit 0 = można startować, exit 1 = odmowa z listą powodów.
"""
import os
import sys

# Wartości deweloperskie z .env.example (i z domyślnych w compose.yaml).
DEV_KEY_PREFIX = "0c5a1d4e8b7f4a2c9e3d6f1a5b8c7d0"
DEV_VALUES = {
    "GLITCHTIP_API_TOKEN": "7d1f3a9c5e2b48d6a0c4f8e1b3d5a7c9e2f4b6d8a0c1e3f5a7b9d1c3e5f70912",
    "GLITCHTIP_ADMIN_PASSWORD": "glitchtip_dev_2026",
    "SECRET_KEY": "glitchtip-dev-only-secret-key-change-me",
}
DEV_DB_PASSWORD = "glitchtip_dev_2026"
LOCAL_PREFIXES = ("http://localhost", "https://localhost", "http://127.0.0.1")


def main() -> int:
    domain = os.environ.get("GLITCHTIP_DOMAIN", "")
    if domain.startswith(LOCAL_PREFIXES):
        return 0

    problems = []
    for name in ("GLITCHTIP_KEY_CATALOG", "GLITCHTIP_KEY_FRONTEND", "GLITCHTIP_KEY_SEARCH"):
        if os.environ.get(name, "").startswith(DEV_KEY_PREFIX):
            problems.append(f"{name}: klucz deweloperski z .env.example")
    for name, dev_value in DEV_VALUES.items():
        if os.environ.get(name, "") == dev_value:
            problems.append(f"{name}: wartość deweloperska z .env.example")
    if DEV_DB_PASSWORD in os.environ.get("DATABASE_URL", ""):
        problems.append("DATABASE_URL: hasło bazy z .env.example")
    if "__SET_ME__" in os.environ.get("GLITCHTIP_ADMIN_EMAIL", ""):
        problems.append("GLITCHTIP_ADMIN_EMAIL: niewypełnione (__SET_ME__), wpisz własny adres w .env")

    if problems:
        print(f"ODMOWA: GLITCHTIP_DOMAIN={domain!r} nie jest localhostem, a:")
        for problem in problems:
            print(f"  - {problem}")
        print("Wygeneruj własne: tools/vps/gen-env.sh .env.prod.example .env (patrz RUNBOOK #069).")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
