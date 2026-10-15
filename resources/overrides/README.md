# PrestaShop overrides

Questa cartella contiene gli **override del core PrestaShop** usati dal progetto DolceZampa.
Vanno copiati nella cartella `override/` del negozio PrestaShop (immagine `ghcr.io/dolce-zampa/prestashop`).

## Elenco

| File | Scopo |
|------|-------|
| `classes/PrestaShopLogger.php` | Invia ogni `PrestaShopLogger::addLog()` anche verso **BetterStack (Logtail)**, replicando la configurazione di `config/logger.php` del microservizio webservice. |

## Installazione

```bash
# dalla root del repository webservice
cp -r resources/overrides/* <prestashop-root>/override/

# rigenerare la class index di PrestaShop
rm -f <prestashop-root>/var/cache/prod/class_index.php   # o svuotare var/cache
```

Nell'immagine Docker ufficiale del team l'override può essere montato/copiato in build, es.:

```dockerfile
COPY resources/overrides/ /var/www/html/override/
RUN rm -rf /var/www/html/var/cache/*
```

## Requisiti lato PrestaShop

L'override usa Monolog + `logtail/monolog-logtail`, quindi nel negozio serve:

```bash
composer require monolog/monolog logtail/monolog-logtail
```

(PrestaShop 1.7/8 bundla già Monolog; il pacchetto Logtail va aggiunto.)

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
