# 06. Budowanie obrazu kontenera

**Domena:** Application Design and Build · **Limit:** 6 min · **Namespace:** `ckad-lab` (zadanie lokalne, bez klastra)

## Zadanie

W katalogu `tools/ckad/fixtures/06/` leży `Dockerfile` małej aplikacji
(busybox `httpd` na porcie 8080). Działa, ale jako root i bez metadanych.
Zbuduj lokalnie dockerem obraz `ckad/hello:1.0` tak, aby:

1. proces działał jako użytkownik **nie-root o UID 1000** (`USER`),
2. obraz miał etykietę `org.opencontainers.image.source` (np. adres repo),
3. obraz został zapisany poleceniem `docker save` do pliku
   `/tmp/ckad-hello-1.0.tar`.

Sprawdź sam: `docker run --rm ckad/hello:1.0 id -u` wypisuje `1000`.

## Po co to na egzaminie i w życiu

Na CKAD trzeba umieć zbudować obraz z Dockerfile (docker lub podman) i
wyeksportować go do archiwum. Uruchamianie jako nie-root to wymóg Pod
Security `restricted`, a etykieta `image.source` pozwala narzędziom powiązać
obraz z repozytorium. Pułapka: `USER nazwa` wymaga wpisu w `/etc/passwd`,
a numeryczny `USER 1000` działa zawsze, i to z niego korzysta
`runAsNonRoot`. Prawdziwy Dockerfile w projekcie: `infra/postgres/Dockerfile`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
docker build -t ckad/hello:1.0 -f - tools/ckad/fixtures/06 <<'EOF'
FROM busybox:1.37
LABEL org.opencontainers.image.source="https://github.com/Dommmin/elastic"
COPY index.html /www/index.html
EXPOSE 8080
USER 1000
CMD ["httpd", "-f", "-p", "8080", "-h", "/www"]
EOF
docker run --rm ckad/hello:1.0 id -u
docker save -o /tmp/ckad-hello-1.0.tar ckad/hello:1.0
ls -l /tmp/ckad-hello-1.0.tar
```

Pułapka: `docker save` zapisuje obraz razem z tagiem tylko wtedy, gdy podasz
`nazwa:tag`; sam identyfikator daje archiwum bez nazwy.

</details>
