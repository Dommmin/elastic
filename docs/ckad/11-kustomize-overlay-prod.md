# 11. Kustomize: overlay prod

**Domena:** Application Deployment · **Limit:** 9 min · **Namespace:** `ckad-lab`

## Zadanie

W `tools/ckad/fixtures/11/` jest baza Kustomize (`base/`: Deployment `site` z
`nginx:1.29` i Service `site`; Deployment pobiera zmienne z ConfigMapy
`site-config`) oraz szkielet `overlays/prod/kustomization.yaml`, który tylko
wskazuje bazę. Nie edytuj plików w repozytorium: skopiuj katalog
`tools/ckad/fixtures/11` do `/tmp/ckad-11` i pracuj tam.

Uzupełnij overlay `prod` tak, żeby po `kubectl apply -k` w namespace `ckad-lab`
powstało:

1. Wszystkie zasoby z prefiksem nazwy `prod-` (Deployment `prod-site`,
   Service `prod-site`).
2. Wspólna etykieta `env=prod` na Deploymencie i Service.
3. 3 repliki Deploymentu.
4. ConfigMapa wygenerowana generatorem (`configMapGenerator`) o nazwie bazowej
   `site-config` i danych `APP_MODE=production`. Deployment ma wskazywać
   na nią (nazwa z prefiksem i hashem), nie na `site-config`.

Poczekaj, aż wszystkie repliki będą gotowe.

Sprawdź sam: `kubectl kustomize /tmp/ckad-11/overlays/prod` pokazuje wynik
bez aplikowania.

## Po co to na egzaminie i w życiu

Kustomize jest wbudowany w `kubectl` (`apply -k`, `kustomize`), więc na
egzaminie nie instalujesz nic. Generator ConfigMapy dopisuje hash do nazwy i sam
poprawia odwołania w Deploymencie; zmiana danych = nowa nazwa = automatyczny
rolling update. Pułapka: ręczna nazwa ConfigMapy w Deploymencie, która omija
mechanizm, oraz `namePrefix` stosowany do nazw, które w bazie trzeba było
uzgodnić. To dokładnie wzorzec z `deploy/k8s/kustomization.yaml`
(`secretGenerator`, `images:` i `configurations:` z `kustomizeconfig.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
rm -rf /tmp/ckad-11 && cp -r tools/ckad/fixtures/11 /tmp/ckad-11
cat > /tmp/ckad-11/overlays/prod/kustomization.yaml <<'EOF2'
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization
resources:
  - ../../base
namePrefix: prod-
labels:
  - pairs:
      env: prod
    includeSelectors: true
replicas:
  - name: site
    count: 3
configMapGenerator:
  - name: site-config
    literals:
      - APP_MODE=production
EOF2
kubectl kustomize /tmp/ckad-11/overlays/prod | head -30
kubectl apply -n ckad-lab -k /tmp/ckad-11/overlays/prod
kubectl rollout status deployment/prod-site -n ckad-lab --timeout=120s
kubectl get deploy,svc,cm -n ckad-lab -l env=prod
```

`replicas:` odwołuje się do nazwy z bazy (`site`), a nie do nazwy z prefiksem
(`prod-site`). `includeSelectors: true` dodaje etykietę także do selektorów
Service i Deploymentu, dzięki czemu obie strony się zgadzają.

</details>
