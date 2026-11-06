# 18. ConfigMap jako env i jako plik

**Domena:** Application Environment, Configuration and Security · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz ConfigMap `app-config` z literałów `LOG_LEVEL=debug` i `REGION=eu-west`
   oraz z pliku `tools/ckad/fixtures/18/app.properties` (klucz ma się nazywać
   tak jak plik, czyli `app.properties`).
2. Utwórz pod `cfg-demo` (obraz `busybox:1.37`, polecenie `sleep 3600`), który:
   - ma zmienną `MY_LEVEL` wziętą z klucza `LOG_LEVEL` ConfigMapy,
   - dostaje **wszystkie** klucze ConfigMapy jako zmienne przez `envFrom`,
   - montuje ConfigMap jako wolumen w `/etc/app`.

Sprawdź sam: `kubectl exec cfg-demo -- env | grep -E 'MY_LEVEL|REGION'` oraz
`kubectl exec cfg-demo -- cat /etc/app/app.properties`.

## Po co to na egzaminie i w życiu

ConfigMap to najczęstszy temat tej domeny: trzeba umieć stworzyć go z literałów
i z pliku oraz wstrzyknąć na trzy sposoby (pojedyncza zmienna, `envFrom`,
wolumen). Pułapka: zmienne env są czytane tylko przy starcie kontenera, a pliki
z wolumenu odświeżają się same po zmianie ConfigMapy (z opóźnieniem). Klucz
z kropką (`app.properties`) nie jest poprawną nazwą zmiennej, więc `envFrom`
go pominie — zmienne dostaną tylko `LOG_LEVEL` i `REGION`. W sklepie cała
konfiguracja nie-tajna jest w jednym ConfigMapie `marketplace-config` wstrzykiwanym
przez `envFrom` (`deploy/k8s/search-consumer.yaml`, źródło: `deploy/k8s/config.env`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create configmap app-config -n ckad-lab \
  --from-literal=LOG_LEVEL=debug --from-literal=REGION=eu-west \
  --from-file=app.properties=tools/ckad/fixtures/18/app.properties
```

`kubectl run` nie ma flag na ConfigMapy, więc pod to YAML:

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: v1
kind: Pod
metadata:
  name: cfg-demo
spec:
  containers:
    - name: app
      image: busybox:1.37
      command: ["sleep", "3600"]
      env:
        - name: MY_LEVEL
          valueFrom:
            configMapKeyRef: { name: app-config, key: LOG_LEVEL }
      envFrom:
        - configMapRef: { name: app-config }
      volumeMounts:
        - { name: cfg, mountPath: /etc/app }
  volumes:
    - name: cfg
      configMap: { name: app-config }
EOF2
kubectl wait -n ckad-lab --for=condition=Ready pod/cfg-demo --timeout=90s
kubectl exec -n ckad-lab cfg-demo -- sh -c 'echo $MY_LEVEL $REGION; cat /etc/app/app.properties'
```

Pułapka: `--from-file=app.properties=ścieżka` ustawia nazwę klucza przed `=`;
bez tego kluczem jest nazwa pliku.

</details>
