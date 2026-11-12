# Pull Request: feat(prestashop): override PrestaShopLogger per log su BetterStack

## Descrizione

Aggiunge un **override di `PrestaShopLogger`** per il negozio PrestaShop usato dal
webservice (stesso stack del modulo `resources/prestashop-modules/webserviceapi`), che
inoltra i log di PrestaShop verso **BetterStack (Logtail)** con la stessa logica già
presente in `config/logger.php` del microservio Slim.

Ogni chiamata a `PrestaShopLogger::addLog()` (usata estesamente nel modulo
`webserviceapi`: webhook prodotto, mailer, validation, order, helper) ora:

1. esegue il comportamento originale (`parent::addLog()` → tabella `ps_log`);
2. inoltra il record a BetterStack tramite Monolog + `LogtailHandlerBuilder`,
   riusando le env `LOGTAIL_API_KEY`, `LOGTAIL_ENDPOINT`, `APP_ENV`, `APP_LOG_LEVEL`.

## Tipo di logging

- Mapping severità PrestaShop → Monolog: `0→Debug`, `1→Info`, `2→Warning`, `3→Error`, `4→Critical`.
- Contesto strutturato: `shop_id`, `shop_name`, `request_uri`, `ip`, `error_code`, `object_type`, `object_id`.
- Stesso `LineFormatter` di `config/logger.php` (timestamp `Y-m-d H:i:s.v`, stacktrace, JSON pretty).

## Sicurezza / affidabilità

- Forwarding **best-effort**: ogni eccezione è catturata e scritta su `error_log`; mai rompe i flussi PrestaShop.
- Di default attivo solo con `APP_ENV=production` e `LOGTAIL_API_KEY` presente (come `config/logger.php`).
- Kill-switch senza redeploy: env `PS_LOG_DISABLE_BETTERSTACK=1`, oppure Configuration BO
  `PS_LOG_BETTERSTACK_ENABLED` / `PS_LOG_BETTERSTACK_LEVEL`.
- Se il modulo ufficiale `logfordev` è attivo, il forwarding viene saltato (evita doppi invii).

## File aggiunti

| File | Ruolo |
|------|-------|
| `resources/overrides/classes/PrestaShopLogger.php` | L'override da integrare con eventuali override esistenti |
| `resources/overrides/README.md` | Istruzioni di installazione, requisiti composer, configurazione |
| `Test/PrestaShopLoggerOverrideTest.php` | Smoke test isolato con stub di Core/Monolog/Logtail |

## Test

```bash
php -l resources/overrides/classes/PrestaShopLogger.php   # OK
vendor/bin/phpunit Test/PrestaShopLoggerOverrideTest.php
```

Verificato: forwarding attivo in produzione, mapping severità, contesto, skip senza API key,
force-on/off via Configuration, salvataggio `ps_log` sempre garantito.

## Deploy (per il team ops)

Il deploy richiede una verifica in staging: lo shop esaminato ha già un override
di `PrestaShopLogger` e il suo lockfile indica Monolog 2.10, mentre questa
implementazione richiede Monolog 3 e Logtail 3. Integrare gli override e risolvere
la compatibilità delle dipendenze prima di installare; vedere `resources/overrides/README.md`.

## Checklist

- [x] Nessun comportamento esistente alterato (`parent::addLog()` invariato)
- [x] Nessun segreto committato
- [x] Test incluso e verde
- [ ] Review + merge
