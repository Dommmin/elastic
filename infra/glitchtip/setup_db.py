"""Rola i baza GlitchTipa w istniejącym Postgresie — IDEMPOTENTNIE (ETAP 11b).

Skrypty z `docker-entrypoint-initdb.d` (w tym `01-databases.sh`) działają tylko przy
PIERWSZEJ inicjalizacji wolumenu, a `pg-data` istnieje od dawna. Ten krok można
uruchamiać wielokrotnie (make up-errors, deploy na VPS): tworzy rolę i bazę, gdy ich
nie ma, a przy każdym uruchomieniu ustawia hasło roli z `.env`.

Łączy się jako superużytkownik Postgresa (PGUSER/PGPASSWORD), więc musi być w obrazie,
który ma psycopg (obraz GlitchTipa ma). Nazwy z env trafiają do SQL przez
`sql.Identifier`/`sql.Literal`, nie przez sklejanie tekstu.
"""
import os
import sys

import psycopg
from psycopg import sql


def connect(dbname: str) -> psycopg.Connection:
    return psycopg.connect(
        host=os.environ.get("PGHOST", "postgres"),
        port=int(os.environ.get("PGPORT", "5432")),
        user=os.environ["PGUSER"],
        password=os.environ["PGPASSWORD"],
        dbname=dbname,
        autocommit=True,
    )


def main() -> int:
    database = os.environ["GLITCHTIP_DB"]
    user = os.environ["GLITCHTIP_DB_USER"]
    password = os.environ["GLITCHTIP_DB_PASSWORD"]
    role = sql.Identifier(user)

    # Puste hasło w SQL to rola BEZ hasła (logowanie wyłączone) albo, co gorsza, bez
    # uwierzytelniania, jeśli ktoś potem to „naprawi”. Brak sekretu w .env na VPS ma kończyć
    # się odmową, a nie cichą rolą (compose.prod.yaml nie używa `:?`, patrz tam).
    if not password:
        print("[glitchtip-db] ODMOWA: GLITCHTIP_DB_PASSWORD jest puste (ustaw w .env: tools/vps/gen-env.sh).")
        return 1

    print(f"[glitchtip-db] Rola '{user}' i baza '{database}'...")

    with connect("postgres") as conn:
        role_exists = conn.execute("SELECT 1 FROM pg_roles WHERE rolname = %s", (user,)).fetchone()
        verb = "ALTER ROLE {} PASSWORD {}" if role_exists else "CREATE ROLE {} LOGIN PASSWORD {}"
        conn.execute(sql.SQL(verb).format(role, sql.Literal(password)))

        db_exists = conn.execute("SELECT 1 FROM pg_database WHERE datname = %s", (database,)).fetchone()
        if not db_exists:
            conn.execute(sql.SQL("CREATE DATABASE {} OWNER {}").format(sql.Identifier(database), role))
        conn.execute(sql.SQL("GRANT ALL PRIVILEGES ON DATABASE {} TO {}").format(sql.Identifier(database), role))

    # Postgres >= 15: uprawnienia do schematu public nie są domyślne.
    with connect(database) as conn:
        conn.execute(sql.SQL("GRANT ALL ON SCHEMA public TO {}").format(role))
        conn.execute(sql.SQL("ALTER SCHEMA public OWNER TO {}").format(role))

    print("[glitchtip-db] Gotowe.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
