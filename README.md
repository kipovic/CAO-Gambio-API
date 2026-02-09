# CAO-Gambio-API

## Überblick
Dieses Script stellt eine CAO-kompatible Schnittstelle bereit, die Bestellungen aus Gambio abholen und (optional) Produkt-, Preis- und Bestandsupdates an Gambio senden kann. Die API läuft als Entry-Point (`admin/cao-faktura.php`) innerhalb des Shops und spricht je nach Konfiguration die Gambio-REST-API v2 oder v3 an. 

## Benötigte Informationen (Konfiguration)
Die Konfiguration liegt in `kip/CaoApi/config/config.php` (siehe `config.sample.php` als Vorlage). Für die Nutzung werden folgende Angaben benötigt:

* **baseUrl** – Shop-URL ohne `/api.php`, z. B. `https://shop.tld`.  
* **apiVersion** – `v2` oder `v3` (kann pro Request überschrieben werden).  
* **basicUser/basicPass** – HTTP Basic Auth für die Gambio-API (v2/v3).  
* **jwt** – optional für v3, wenn Bearer-Token genutzt wird.  
* **accessToken** – optionaler Token für den CAO-Entry-Point (Header `X-CAO-Token` oder `X-Api-Key`, alternativ `token`-Query).  
* **allowedIps** – optionale IP-Whitelist für den Zugriff auf den Entry-Point.  
* **logFile** – Pfad der Log-Datei.  
* **maxXmlBytes** – Maximale Größe für XML-Uploads (Default 2 MiB).  

## Sichere Einrichtung & Nutzung
Empfohlene Maßnahmen, um den Zugriff abzusichern:

1. **HTTPS nutzen**: Schützt Tokens und Zugangsdaten auf dem Transportweg.  
2. **IP-Whitelist setzen**: Beschränke `allowedIps` auf deine CAO-Server.  
3. **Access-Token aktivieren**: `accessToken` setzen und im Client per Header senden.  
4. **Separate API-Credentials**: Verwende einen eigenen Gambio-API-User mit minimalen Rechten (nur lesen/schreiben, was benötigt wird).  
5. **Logs überwachen**: Aktiviere `logFile` und überwache Fehlermeldungen.  

## Nutzung / Aufrufe
Der Entry-Point unterstützt zwei Arten von Aufrufen:

### 1) GET-Action (CAO-kompatibel)
Beispiele:

* `?action=version`
* `?action=orders_export&order_from=1&order_to=999999`
* `?action=products_export`
* `?action=customers_export`

### 2) POST/GET-Op (eigene Ops)
Beispiele:

* `?op=get_orders_since&since=2024-01-01T00:00:00`
* `?op=set_order_status&order_id=123&status_id=5&notify=1`
* `?op=add_tracking&order_id=123&code=XYZ&carrier=DHL`
* `?op=upsert_product` (Produkt-XML im Body)
* `?op=set_stock&sku=ART-123&qty=10`
* `?op=set_price&sku=ART-123&price=19.95`

### Produkt-XML (für `upsert_product`)
Der XML-Body entspricht der klassischen CAO-Struktur mit `PRODUCT_DATA` und optional `PRODUCT_DESCRIPTION`. Mindestens sollte **PRODUCT_MODEL** oder **PRODUCT_ID** vorhanden sein. Beispiel (gekürzt):

```xml
<PRODUCT_INFO>
  <PRODUCT_DATA>
    <PRODUCT_ID>123</PRODUCT_ID>
    <PRODUCT_MODEL>ART-123</PRODUCT_MODEL>
    <PRODUCT_QUANTITY>10</PRODUCT_QUANTITY>
    <PRODUCT_PRICE>19.95</PRODUCT_PRICE>
    <PRODUCT_TAX_CLASS_ID>1</PRODUCT_TAX_CLASS_ID>
    <PRODUCT_STATUS>1</PRODUCT_STATUS>
    <PRODUCT_DESCRIPTION CODE="de">
      <NAME>Beispielprodukt</NAME>
      <DESCRIPTION>...</DESCRIPTION>
    </PRODUCT_DESCRIPTION>
  </PRODUCT_DATA>
</PRODUCT_INFO>
```

## Umgesetzte Funktionen (Stand aktuell)

### Lesefunktionen
* `orders_export` (inkl. Statusfilter und Detailanreicherung)  
* `get_orders_since`  
* `products_export`  
* `manufacturers_export`  
* `customers_export`  

### Schreibfunktionen
* `upsert_product` – erstellt oder aktualisiert Produkte anhand von `PRODUCT_ID`/`PRODUCT_MODEL`.  
* `set_stock` – setzt den Lagerbestand anhand der SKU (`PRODUCT_MODEL`).  
* `set_price` – setzt den Preis anhand der SKU (`PRODUCT_MODEL`).  

> Hinweis: Die Schreibfunktionen nutzen die Gambio-REST-API. Stelle sicher, dass der API-User die entsprechenden Rechte besitzt.
