# ============================================================================
#  RabbitMQ z wbudowaną topologią (ETAP D)
#
#  Topologia (exchange'e, kolejki, DLX) jest w obrazie jako SZABLON.
#  Użytkownik i hash jego hasła NIE — bo obraz leży w publicznym rejestrze,
#  a hash hasła jest sekretem (słabe hasło da się z niego odzyskać offline).
#  entrypoint.sh liczy hash przy KAŻDYM starcie z RABBITMQ_USER/PASSWORD.
#
#  Lokalnie compose montuje gotowy definitions.json (z tools/render-
#  rabbitmq-definitions.py) — wtedy entrypoint widzi plik i niczego nie robi.
# ============================================================================
ARG RABBITMQ_VERSION
FROM rabbitmq:${RABBITMQ_VERSION}-management-alpine

# jq: bezpieczne sklejanie JSON-a (sed na JSON-ie to proszenie się o kłopoty).
RUN apk add --no-cache jq

COPY rabbitmq.conf /etc/rabbitmq/conf.d/10-marketplace.conf
COPY enabled_plugins /etc/rabbitmq/enabled_plugins
COPY definitions.template.json /etc/rabbitmq/definitions.template.json
COPY --chmod=0755 entrypoint.sh /usr/local/bin/marketplace-entrypoint.sh

ENTRYPOINT ["marketplace-entrypoint.sh"]
CMD ["rabbitmq-server"]
