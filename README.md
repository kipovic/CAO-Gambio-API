# CAO-Gambio-API

Bridge-Skript zwischen **CAO-Faktura** und der **Gambio REST API**.

Wichtig: Dieses Repo ist **bewusst kein klassisches Gambio-Modul**, sondern ein pragmatischer Entry-Point für CAO-kompatible XML-Antworten und ergänzende Schreiboperationen gegen die Gambio-API.

## Ziel

Die Bridge soll:
- CAO-kompatible GET-Actions bereitstellen
- Gambio REST API v2 und v3 nutzen
- optional Schreiboperationen wie Produkt-/Preis-/Bestandsupdates ausführen
- sicher und wartbar bleiben, ohne unnötig viel Gambio-Modul-Overhead

## Repo-Struktur

```text
cao-faktura.php          Entry-Point, der später im Shop unter /admin liegt
kip/CaoApi/              Bridge-Code, Bootstrap, Config, Services, Mapper
docs/                    Deployment- und Betriebsdoku
scripts/                 Hilfsskripte, nicht produktiv nötig
reference/               historische/experimentelle Dateien
```

## Wichtige Klarstellung zur Deployment-Struktur

Im Repository liegt der Code als Quellstruktur vor.
Im Shop ist die **Zielstruktur** typischerweise:

```text
/admin/cao-faktura.php
/GXModules/kip/CaoApi/bootstrap.php
/GXModules/kip/CaoApi/config/config.php
/GXModules/kip/CaoApi/src/...
```

Darum referenziert `cao-faktura.php` im Runtime-Betrieb relativ auf:

```php
../GXModules/kip/CaoApi/bootstrap.php
```

Das ist **absichtlich** so, weil die Datei später unter `/admin/` liegt.

## Konfiguration

Als Vorlage dient:
- `kip/CaoApi/config/config.sample.php`

Wichtige Werte:
- `baseUrl`
- `apiVersion` (`v2` oder `v3`)
- `basicUser` / `basicPass`
- optional `jwt`
- optional `accessToken`
- optional `allowedIps`
- `logFile`
- `maxXmlBytes`

## Unterstützte Aufrufe

### CAO-kompatible GET-Actions
- `version`
- `orders_export`
- `products_export`
- `customers_export`
- `manufacturers_export`
- `categories_export`

### Eigene Ops
- `get_orders_since`
- `set_order_status`
- `add_tracking`
- `upsert_product`
- `set_stock`
- `set_price`

## Sicherheit

Empfohlen:
- HTTPS erzwingen
- eigenen Gambio-API-User nutzen
- `accessToken` setzen
- `allowedIps` pflegen
- Logs überwachen

## Tests / Checks

### Lokale Baseline
```bash
./scripts/validate.sh
```

Der Validator bündelt PHP-Syntaxprüfung und einen Offline-Smoke-Test für Status-/XML-Mapper.
Wenn die lokale PHP-CLI kein `ext-simplexml` hat, laufen die Status-Checks weiter und die XML-Mapper-Prüfungen werden klar als übersprungen gemeldet.

### Einzelcheck
```bash
php tests/xml_mapper_smoke.php
```

## Doku

- `docs/DEPLOYMENT.md`

## Aktueller Entwicklungsgrundsatz

Nicht auf "perfektes Gambio-Modul" trimmen, sondern auf:
- stabile Bridge-Struktur
- saubere Deployment-Realität
- robuste Nutzung der Gambio REST API
- möglichst wenig Überraschungen für CAO
