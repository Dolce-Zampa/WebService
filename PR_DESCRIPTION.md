# Pull Request: feat(prestashop): override PrestaShopLogger per log su BetterStack

> ⚠️ Nota: in questo ambiente non è configurato alcun remote Git né un token GitHub
> (`gh auth status` = non autenticato), quindi il push del branch e l'apertura
> automatica della PR non sono stati possibili. Il branch **`feat/ps-log-override-betterstack`**
> è stato creato e committato localmente (commit `02722a1`, 3 file, +612 righe).
> Di seguito il testo pronto per la PR.

---

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

- Mapping severità PrestaShop → Monolog: `0→Notice`, `1→Warning`, `2→Error`, `3→Critical`, `4→Info`.
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
| `resources/overrides/classes/PrestaShopLogger.php` | L'override da copiare in `<prestashop>/override/classes/` |
| `resources/overrides/README.md` | Istruzioni di installazione, requisiti composer, configurazione |
| `Test/PrestaShopLoggerOverrideTest.php` | Smoke test autonomo (stub di Core/Monolog/Logtail) — tutti verdi |

## Test

```bash
php -l resources/overrides/classes/PrestaShopLogger.php   # OK
php Test/PrestaShopLoggerOverrideTest.php                 # ALL TESTS PASSED (15 asserzioni)
```

Verificato: forwarding attivo in produzione, mapping severità, contesto, skip senza API key,
force-on/off via Configuration, salvataggio `ps_log` sempre garantito.

## Deploy (per il team ops)

1. Copiare `resources/overrides/*` in `<prestashop-root>/override/` (o aggiungerlo alla build dell'immagine `ghcr.io/dolce-zampa/prestashop`).
2. Nel container PrestaShop: `composer require logtail/monolog-logtail` (Monolog è già bundled).
3. Passare le env `LOGTAIL_API_KEY` (+ opzionalmente `LOGTAIL_ENDPOINT`, `APP_ENV`) al servizio `prestashop` di docker-compose.
4. Cancellare `var/cache/*/class_index.php` per rigenerare l'override loader.

## Checklist

- [x] Nessun comportamento esistente alterato (`parent::addLog()` invariato)
- [x] Nessun segreto committato
- [x] Test incluso e verde
- [ ] Review + merge
