# Deployment

## Zielbild im Shop

Diese Bridge ist **kein GXModule im engeren Sinn**, sondern ein bewusst eigenständiger CAO-Entry-Point mit Hilfsklassen.

Empfohlene Zielstruktur im Shop:

```text
/admin/cao-faktura.php
/GXModules/kip/CaoApi/bootstrap.php
/GXModules/kip/CaoApi/config/config.php
/GXModules/kip/CaoApi/src/...
/GXModules/kip/CaoApi/logs/
```

## Was aus dem Repo wohin gehört

### In den Shop kopieren
- `cao-faktura.php` → als `/admin/cao-faktura.php`
- `kip/CaoApi/*` → nach `/GXModules/kip/CaoApi/`

### Nicht produktiv nötig
- `docs/`
- `reference/`
- `scripts/`

## Konfiguration

Produktive Konfiguration anlegen unter:

```php
/GXModules/kip/CaoApi/config/config.php
```

als Ableitung von:

```php
kip/CaoApi/config/config.sample.php
```

## Erwartete Laufzeit

`cao-faktura.php` erwartet im Zielsystem relativ zu sich:

```text
../GXModules/kip/CaoApi/bootstrap.php
```

Deshalb muss die Shop-Zielstruktur exakt stimmen, wenn der Entry-Point unter `/admin/` liegt.

## Security-Hinweise

- HTTPS verwenden
- `accessToken` setzen
- `allowedIps` wenn möglich setzen
- eigenen API-User für Gambio verwenden
- Logs regelmäßig prüfen
