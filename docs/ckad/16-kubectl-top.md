# 16. `kubectl top`: który pod zjada CPU

**Domena:** Application Observability and Maintenance · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działają trzy pody z etykietą `task=cpu`: `cpu-a`,
`cpu-b` i `cpu-c`. Jeden z nich intensywnie obciąża procesor.

Zapisz w lokalnym pliku `/tmp/ckad-16.txt` **samą nazwę** poda z największym
zużyciem CPU.

Wskazówka: metrics-server potrzebuje około minuty na pierwsze dane, więc zaraz
po starcie `kubectl top` zgłasza brak metryk.

Sprawdź sam: `kubectl top pod -n ckad-lab -l task=cpu`.

## Po co to na egzaminie i w życiu

`kubectl top` (pods, nodes, `--containers`, `--sort-by=cpu`) to najszybsze
narzędzie do szukania obciążenia, a egzamin lubi pytanie „który pod zużywa
najwięcej”. Pułapka: bez działającego metrics-servera polecenie nie zwraca nic,
a dane są średnią z krótkiego okna, nie wartością chwilową. W projekcie to samo
źródło metryk napędza autoskalowanie (`deploy/k8s/hpa.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
until [ "$(kubectl top pod -n ckad-lab -l task=cpu --no-headers 2>/dev/null | awk '$2+0 >= 50' | wc -l)" -ge 1 ] \
  && [ "$(kubectl top pod -n ckad-lab -l task=cpu --no-headers 2>/dev/null | wc -l)" -eq 3 ]; do sleep 5; done
kubectl top pod -n ckad-lab -l task=cpu --sort-by=cpu --no-headers | head -1 | awk '{print $1}' > /tmp/ckad-16.txt
cat /tmp/ckad-16.txt
```

`--sort-by=cpu` sortuje malejąco, więc pierwszy wiersz to zwycięzca.

</details>
