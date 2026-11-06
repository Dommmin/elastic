# Punkt wyjścia zadania 06 (docs/ckad/06-budowanie-obrazu.md).
# Obraz działa, ale jako root i bez metadanych — popraw to.
FROM busybox:1.37
COPY index.html /www/index.html
EXPOSE 8080
CMD ["httpd", "-f", "-p", "8080", "-h", "/www"]
