# keyslip

**Le array_merge qui efface tes IDs.** CLI PHP, zéro dépendance.

La facture `1042` est payée. `array_merge()` ne garde pas `1042`, et ne remplace pas non plus la ligne : le remboursement est ajouté à côté. `json_encode()` envoie alors une liste. Le spread `...` fait la même chose.

```bash
php keyslip.php demo
php keyslip.php explain '[1042 => "paid"]' '[1042 => "refunded", 1044 => "open"]'
php keyslip.php scan src/
```

Rien n'est envoyé nulle part. Le scan lit des fichiers locaux. Pas de télémétrie.

## Le problème

Un tableau PHP dont les clés sont des entiers n'est pas une map JSON. `array_merge()` et `[...$map]` renumérotent ces clés à partir de 0. Les clés string (`"id_1042"`, `"08"`) restent. Une vraie liste `0..n-1` aussi : c'est le cas où `array_merge()` est le bon outil.

La sortie ci-dessous est celle du binaire, pas un exemple inventé.

```text
$ php keyslip.php demo
keyslip  the merge that deletes your ids

Invoice 1042 is paid. array_merge() does not override it and does not keep it.
The spread operator copies a map the same way. json_encode() then ships a list,
so the id never reaches the client.

  input          [1042 => "paid", 1043 => "open"]
  json input     {"1042":"paid","1043":"open"}

  array_merge    [0 => "paid", 1 => "open"]   KEYS LOST
  json merged    ["paid","open"]
  spread ...     [0 => "paid", 1 => "open"]   KEYS LOST
  json spread    ["paid","open"]
  array_replace  [1042 => "paid", 1043 => "open"]   keys kept
  json replace   {"1042":"paid","1043":"open"}

The refund does not replace the payment. Both rows survive, and the id is gone:

  left           [1042 => "paid"]
  right          [1042 => "refunded", 1044 => "open"]
  array_merge    [0 => "paid", 1 => "refunded", 2 => "open"]   KEYS LOST
  json merged    ["paid","refunded","open"]
  array_replace  [1042 => "refunded", 1044 => "open"]   keys kept, right wins
  union +        [1042 => "paid", 1044 => "open"]   keys kept, left wins

String keys are left alone:

  string keys    ["id_1042" => "paid", "id_1044" => "open"]   keys kept

Fix: array_replace() when the right map should win, or + when the left map should.
Do not copy a map with [...]. A list (0, 1, 2, …) can still use array_merge().
```

`explain` accepte un littéral PHP ou un objet / tableau JSON. Pas de `eval`. Les clés passent par une vraie assignation PHP : `"1042"` devient l'entier `1042`, `"08"` reste une string. Exit `1` si des clés sautent, `0` sinon. `--json` pour une CI.

```bash
php keyslip.php explain '{"1042":"paid","1043":"open"}'
php keyslip.php explain --json '[1042 => "paid"]' '[1044 => "open"]'
```

## Réparer

```php
$orders = [1042 => 'paid', 1043 => 'open'];

$copy = array_replace([], $orders);     // pas [...$orders]
$withRefund = array_replace($orders, [1042 => 'refunded']); // la droite gagne
$keepLeft = $orders + [1042 => 'refunded', 1044 => 'open']; // la gauche gagne

$pages = array_merge($pageA, $pageB);   // listes 0..n-1, rien à garder
```

`json_decode($payload, true)` caste déjà les clés `"1042"` en entiers. Le merge d'après est le même piège.

## Scanner

`scan` ne crie que dans trois cas :

| règle | sévérité | exemple |
| --- | --- | --- |
| `merge-literal` / `spread-literal` | high | `array_merge([1042 => 'paid'], …)` |
| `merge-map` / `spread-map` | high | `array_merge($ordersById, $extra)`, `[...$invoiceMap]` |
| `merge-unresolved` / `spread-unresolved` | medium | `array_merge([$id => $row], $rest)` |

Un nom sent la map si le dernier morceau est `map`, `index`, `lookup`, `dict`, `dictionary`, `keyed`, ou s'il finit par `ById` / `_by_id`. `$rows`, `$userId`, `$mapping` ne comptent pas.

Il ignore `vendor/`, `node_modules/`, `.git/`, une ligne marquée `keyslip:ignore`, les chaînes et les commentaires. `Foo\array_merge()` non plus : ce n'est pas la fonction du langage. `\array_merge()` oui.

Ce n'est pas un analyseur de types. `array_merge($data, $more)` passe au travers, même si `$data` est indexé par des ids. Un `$zIndex` qui n'est pas une map peut sortir : on l'ignore avec le commentaire, on ne baisse pas le seuil en silence.

```bash
php keyslip.php scan src/                 # exit 1 s'il y a un high
php keyslip.php scan src/ --fail-on=medium
php keyslip.php scan src/ --fail-on=none  # affiche, exit 0
php keyslip.php scan examples/dirty --json
```

```text
examples/dirty/Orders.php:7  high  merge-map
  return array_merge($ordersById, $refundsById);

examples/dirty/Orders.php:12  high  spread-map
  return [...$invoiceMap];

examples/dirty/Orders.php:17  high  merge-literal
  return array_merge([1042 => 'paid'], [1043 => 'open']);
```

`examples/clean` est le même code avec `array_replace()`, des listes, des clés string, et un `keyslip:ignore`. Le scan y est vide.

## CI

```yaml
- run: php keyslip.php scan src/
```

Les tests du dépôt tournent sur PHP 8.2, 8.3 et 8.4.

```bash
php tests/run.php
```

PHP `>= 8.1`. Aucune extension requise.

## Licence

MIT.
