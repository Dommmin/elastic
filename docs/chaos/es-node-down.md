# Klaster wyszukiwarki z jednym węzłem mniej

**Oczekiwany alert:** brak (żadna reguła nie zapłonie) · **Limit:** 10 min

## Objaw

Elasticsearch `marketplace` ma w manifeście 3 węzły, a `kubectl get elasticsearch`
pokazuje `NODES 2` i `PHASE ApplyingChanges`, które nie przechodzi w `Ready`. Przez
pierwsze minuty `HEALTH` jest `yellow`; po około 5 minutach wraca do `green`, choć
węzłów nadal jest 2. W `kubectl get pods` są tylko `marketplace-es-default-0` i `-1`,
oba `1/1`, a StatefulSet wygląda zdrowo. Wyszukiwarka sklepu odpowiada normalnie,
Alertmanager milczy.

## Zacznij od

- Alertmanager/Grafana: nic nie płonie. To też jest wskazówka.
- Pierwsze polecenia:

```bash
kubectl -n marketplace get elasticsearch,statefulset,pdb
kubectl -n marketplace describe elasticsearch marketplace
kubectl -n marketplace get pods,pvc -l elasticsearch.k8s.elastic.co/cluster-name=marketplace
```

## Pytania prowadzące

1. Kto ustala `spec.replicas` StatefulSetu `marketplace-es-default` i dlaczego nie
   przywraca 3? Co mówią `Conditions` w `kubectl describe elasticsearch`, a co
   adnotacje zasobu (`kubectl get elasticsearch marketplace -o yaml`)?
2. Dlaczego nie płonie żaden alert, skoro brakuje węzła? Spójrz na wyrażenie
   `KubeStatefulSetReplicasMismatch`: co z czym porównuje i co widzi przy StatefulSecie `2/2`?
3. Wyszukiwarka działa. Czy dzięki replikom? Sprawdź `number_of_replicas` indeksu za
   aliasem `products-search` i węzeł, na którym leży jego shard (`_cat/shards/products*`).
   Co by się stało, gdyby zniknął węzeł z tym shardem?
4. Dlaczego health był `yellow`, a po kilku minutach wrócił do `green`, choć węzłów
   nadal jest 2? Co mówi `_cluster/allocation/explain` o nieprzydzielonej replice
   i co pokazuje `_nodes/shutdown`?
5. PVC trzeciego węzła nadal jest `Bound`. Co to daje, gdy węzeł wróci?

Zapytania do ES: Kibana (Dev Tools) albo z terminala, bez wypisywania hasła:

```bash
kubectl -n marketplace port-forward svc/marketplace-es-http 9200 &
P="$(kubectl -n marketplace get secret marketplace-es-elastic-user -o jsonpath='{.data.elastic}' | base64 -d)"
curl -s -u "elastic:${P}" 'localhost:9200/_cluster/health?pretty'
curl -s -u "elastic:${P}" 'localhost:9200/_cat/shards/products*?v'
```

## Po co to w życiu

Klaster opisuje [`deploy/k8s/elasticsearch.yaml`](../../deploy/k8s/elasticsearch.yaml)
(`nodeSets[0].count: 3`); StatefulSet `marketplace-es-default` tworzy i pilnuje operator
ECK, nie `kubectl apply`. ECK ma wyłącznik na czas prac serwisowych: adnotację, która
wstrzymuje zmiany sterowane spec (skalowanie, rolling restart). Zapomniana po pracach
zostawia klaster w stanie, który nikt nie wybrał, a ECK nie zgłasza błędu, bo działa
zgodnie z poleceniem. `kube-state-metrics` nie zna zasobu `Elasticsearch`, więc reguły
z chartu porównują StatefulSet sam ze sobą i nie widzą różnicy między `count: 3`
a `replicas: 2`. Indeks produktów ma w [`infra/elasticsearch/mappings/products-v1.json`](../../infra/elasticsearch/mappings/products-v1.json)
`number_of_replicas: 0`: trzy węzły nie dają mu żadnej odporności, tylko pojemność.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** na zasobie `elasticsearch/marketplace` jest adnotacja
`eck.k8s.elastic.co/pause-orchestration: "true"` (warunek `OrchestrationPaused`:
„Orchestration paused via annotation”), a StatefulSet `marketplace-es-default`
zeskalowano ręcznie do 2. Ostatni pod (`-2`) zniknął, jego PVC zostało. Hook `preStop`
od ECK zgłosił węzeł przez `_nodes/shutdown` jako restart z `allocation_delay: 5m`,
więc ES przez 5 minut trzymał repliki tego węzła jako nieprzydzielone (`yellow`,
`NODE_RESTARTING`), a potem przydzielił je na pozostałe dwa (`green` na 2 węzłach).

Wyszukiwarka działała nie dzięki replikom: `products-v1` ma `number_of_replicas: 0`,
a jego jedyny shard leżał na węźle, który został. `break` odmawia, gdy jedyna kopia
jakiegoś shardu leży na węźle, który zniknie; bez tego bezpiecznika ten sam scenariusz
dałby `red` i niedziałające wyszukiwanie. Dowód:

```bash
kubectl -n marketplace get elasticsearch marketplace \
  -o jsonpath='{.metadata.annotations}{"\n"}{.status.conditions[?(@.type=="OrchestrationPaused")]}{"\n"}'
kubectl -n marketplace get sts marketplace-es-default -o jsonpath='{.spec.replicas}{"\n"}'
kubectl -n marketplace get sts marketplace-es-default --show-managed-fields \
  -o jsonpath='{range .metadata.managedFields[*]}{.manager} {.subresource}{"\n"}{end}'   # kubectl scale
```

**Naprawa:** zdejmij pauzę, potem przywróć liczbę replik (ECK po wznowieniu nie
poprawił jej sam w ciągu minuty), i poczekaj na 3 węzły:

```bash
kubectl -n marketplace annotate elasticsearch marketplace eck.k8s.elastic.co/pause-orchestration-
kubectl -n marketplace scale statefulset marketplace-es-default --replicas=3
kubectl -n marketplace rollout status statefulset marketplace-es-default
kubectl -n marketplace get elasticsearch marketplace -w   # green, NODES 3, PHASE Ready
make chaos-check s=es-node-down
```

Nie kasuj podów ani PVC Elasticsearcha: wracający węzeł podnosi swoje shardy z PVC.

**Jak temu zapobiec:** alert na zdrowie samego ES (eksporter albo reguła na
`_cluster/health`: `number_of_nodes` mniejsze niż `count`), bo reguły StatefulSetu tego
nie widzą; pauza ECK tylko z wpisem w runbooku i datą zdjęcia; indeks produktów
z `number_of_replicas: 1` na klastrze z 3 węzłami.

</details>
