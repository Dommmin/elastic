#!/usr/bin/env python3
"""
Generuje infra/rabbitmq/definitions.json z szablonu, wstawiając użytkownika
i hash hasła policzony z .env.

DLACZEGO TO ISTNIEJE (warto zrozumieć, to realna pułapka):

RabbitMQ tworzy użytkownika z RABBITMQ_DEFAULT_USER/PASS tylko wtedy, gdy node
startuje z PUSTĄ bazą użytkowników. Gdy w konfiguracji jest `load_definitions`,
import definicji liczy się jako inicjalizacja — i tworzenie domyślnego
użytkownika zostaje POMINIĘTE.

Efekt: broker wstaje, healthcheck jest zielony, kolejki są na miejscu...
i nikt nie może się zalogować. Komunikat to lakoniczne "Not_Authorized".

Rozwiązanie: użytkownicy też muszą być w definitions.json. Ale definicje
przechowują hasła jako HASH, nie plaintext — więc musimy go policzyć.

ALGORYTM HASHOWANIA (rabbit_password_hashing_sha256):
    1. losowa sól: 4 bajty
    2. sha256(sól + hasło_utf8)
    3. base64(sól + wynik_sha256)

Sól jest doklejana z przodu, żeby dało się zweryfikować hasło bez osobnego
pola na sól — RabbitMQ odczytuje pierwsze 4 bajty zdekodowanego stringa.

Uruchamiane automatycznie przez `make up`. Wygenerowany plik jest w .gitignore,
bo zawiera pochodną hasła — w repo trzymamy tylko szablon.
"""
import base64
import hashlib
import json
import os
import secrets
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEMPLATE = ROOT / "infra" / "rabbitmq" / "definitions.template.json"
OUTPUT = ROOT / "infra" / "rabbitmq" / "definitions.json"


def rabbit_password_hash(password: str) -> str:
    """Zwraca hash w formacie akceptowanym przez RabbitMQ (SHA-256)."""
    salt = secrets.token_bytes(4)
    digest = hashlib.sha256(salt + password.encode("utf-8")).digest()
    return base64.b64encode(salt + digest).decode("ascii")


def read_env() -> dict:
    """Czyta .env bez zewnętrznych zależności."""
    env_file = ROOT / ".env"
    if not env_file.exists():
        sys.exit("BŁĄD: brak pliku .env. Uruchom: cp .env.example .env")
    env = {}
    for line in env_file.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        env[key.strip()] = value.strip()
    return env


def main() -> None:
    env = read_env()
    user = env.get("RABBITMQ_USER")
    password = env.get("RABBITMQ_PASSWORD")
    if not user or not password:
        sys.exit("BŁĄD: brak RABBITMQ_USER lub RABBITMQ_PASSWORD w .env")

    definitions = json.loads(TEMPLATE.read_text())

    definitions["users"] = [
        {
            "name": user,
            "password_hash": rabbit_password_hash(password),
            "hashing_algorithm": "rabbit_password_hashing_sha256",
            # administrator: dostęp do panelu i do rabbitmqadmin.
            # W produkcji aplikacje dostałyby użytkownika BEZ tagów
            # (sam dostęp AMQP), a administrator byłby osobnym kontem.
            "tags": ["administrator"],
        }
    ]
    definitions["permissions"] = [
        {
            "user": user,
            "vhost": "/",
            "configure": ".*",
            "write": ".*",
            "read": ".*",
        }
    ]

    OUTPUT.write_text(json.dumps(definitions, indent=2, ensure_ascii=False) + "\n")
    print(f"[rabbitmq] Wygenerowano {OUTPUT.relative_to(ROOT)} (użytkownik: {user})")


if __name__ == "__main__":
    main()
