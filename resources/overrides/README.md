# PrestaShop overrides

Questa cartella contiene gli **override del core PrestaShop** usati dal progetto DolceZampa.
Sono destinati alla cartella `override/` del negozio PrestaShop (immagine `ghcr.io/dolce-zampa/prestashop`).

## Elenco

| File | Scopo |
|------|-------|
| `classes/PrestaShopLogger.php` | Invia ogni `PrestaShopLogger::addLog()` anche verso **BetterStack (Logtail)**, replicando la configurazione di `config/logger.php` del microservizio webservice. |

## Installazione

Prima dell'installazione verificare se esiste già `override/classes/PrestaShopLogger.php`.
PrestaShop consente un solo override per classe: se esiste, confrontare e integrare
le funzionalità necessarie invece di sovrascriverlo. Provare l'integrazione in
staging prima del deploy e conservare una copia per il rollback.

```bash
# rigenerare la class index di PrestaShop
rm -f <prestashop-root>/var/cache/prod/class_index.php   # o svuotare var/cache
```

## Requisiti lato PrestaShop

Questa implementazione usa Monolog 3 e `logtail/monolog-logtail` 3. Il lockfile
della copia PrestaShop locale esaminata indica Monolog 2.10: non è compatibile
con questa combinazione. Non aggiornare le dipendenze del negozio alla cieca:
occorre prima verificare i vincoli di PrestaShop e dei moduli e validare una
soluzione compatibile in staging. Senza le dipendenze richieste il forwarding
non funziona; la chiamata al logger nativo continua a essere eseguita.

## Configurazione

Le stesse variabili d'ambiente usate da `config/logger.php` del webservice:

| Variabile | Descrizione | Default |
|-----------|-------------|---------|
| `LOGTAIL_API_KEY` | Source token BetterStack (se assente, forwarding disattivato) | – |
| `LOGTAIL_ENDPOINT` | Endpoint Logtail | `https://in.logtail.com` |
| `APP_ENV` | Forwarding attivo di default solo in `production` (come config/logger.php) | – |
| `APP_LOG_LEVEL` | Livello minimo Monolog (`debug`..`emergency`) | `debug` |
| `PS_LOG_DISABLE_BETTERSTACK` | `1` = disattiva completamente il forwarding | `0` |

Override runtime senza redeploy (tabella `ps_configuration`):

- `PS_LOG_BETTERSTACK_ENABLED` → `1`/`0` forza on/off indipendentemente da `APP_ENV`
- `PS_LOG_BETTERSTACK_LEVEL` → livello minimo (es. `warning`)

Note di sicurezza:

- Il forwarding è **best-effort**: qualunque errore viene catturato e scritto su `error_log`, non rompe mai i flussi PrestaShop.
- Se il modulo `logfordev` è installato e attivo, il forwarding viene saltato per evitare doppi invii.
- Il salvataggio nativo in tabella `ps_log` resta invariato (`parent::addLog()`).
