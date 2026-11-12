# Product cache

Product base data and complete snapshots, slug mappings, catalog lists, counts and filters are cached forever. Redis tags and atomic locks are required. Product detail routes use the service snapshot directly, avoiding a second HTTP response cache that could hide changed bundle dependencies. Successful category HTTP responses are cached forever too.

The PrestaShop webhook with `product:{id}` invalidates that product and queues its refresh. When it includes complete previous and current categories, the HTTP pages, lists, counts and filters for those categories are invalidated across all pages; unrelated category caches remain usable. Unscoped catalog responses are also invalidated. The affected pages are rebuilt on their next request. The product tag clears base data, complete associations and product image builder entries; old slug mappings become unusable through the changed product revision. Other product snapshots stay usable.

If category context is absent or incomplete, multiple products are sent together, or the product is used by a bundle, accessory or PrestaShop pack, the webhook conservatively invalidates the whole catalog. Database errors while checking those dependencies also fall back to global invalidation. Snapshots embedding accessories or bundles validate their product revisions before reuse; an additional write revision prevents a request in flight from repopulating cache after an invalidation.

Deploy the WebService first. Until the updated PrestaShop module is installed, older webhook payloads continue to trigger global invalidation. Install the updated `resources/prestashop-modules/webserviceapi` module separately in PrestaShop and open its configuration page to register the new hooks. Confirm the before/after form and deletion hooks are registered and test creation, editing, moving and deletion in the actual PrestaShop environment. A full cache flush is not required by this change; category HTTP keys use a new namespace and individual product snapshot keys are preserved.

## Nightly warmup

At 03:00 Europe/Rome, the application scheduler enqueues **only active products missing a valid complete snapshot**, starting at the highest product ID. A cached base product without full associations still needs warming. Existing complete snapshots are not refreshed or cleared. Pending jobs are deduplicated for 24 hours.

Manual command (optional cap counts missing products, not scanned products):

```sh
php bin/console cache:warm-products
php bin/console cache:warm-products --limit=500
```

The command reads IDs from the existing database connection and does not call PrestaShop. The product cache worker processes a low-priority `product-cache-warm-jobs` queue sequentially, pausing 200ms after successful warmups. Webhook refresh jobs have priority. A queued warmup rechecks the cache on execution, so a product warmed in the meantime requires no reload. Failures are retried up to three times.

The job fills missing products; it is not a reconciliation job for missed webhook updates. Invalidation of prices, stock, promotions, reviews and configurators must reach the cache through the relevant hooks or explicit clear. Nightly warmup does not preload every category/filter/page combination; category pages populate on demand.

## Deployment

Rebuild the image and restart the scheduler and product cache worker. Scheduler Supervisor now points to `bin/workers/scheduler`. The existing Elasticsearch Supervisor configuration starts `bin/workers/worker-product-cache` too. They can also run under the deployment's process manager:

```sh
php bin/workers/scheduler
php bin/workers/worker-product-cache
```

Clear old cache once after deployment to retire previous namespaces and HTTP responses. Service keys now use v3. No production job or cache clear is executed by these source changes. `APP_DISABLE_CACHE=true` disables product service caching and skips nightly enqueue. `no_cache=1` bypasses category HTTP caching but still uses the service cache.

Il dettaglio prodotto espone `promotion` (`start` e `end` ISO 8601, oppure `null`) e, per compatibilità, `promotion_start` e `promotion_end` sul prodotto e sulle combinazioni. I dati sono letti dalla tabella PrestaShop `specific_price` tramite Eloquent a ogni richiesta: sono considerate le promozioni attive anche se iniziate nei giorni precedenti, con quantità unitaria e senza vincoli di cliente o carrello. Le date PrestaShop `0000-00-00 00:00:00` indicano un limite non impostato e sono esposte come `null`; in assenza di una scadenza il frontend non mostra il countdown. Il fuso usato per la giornata e per le date è `APP_TIMEZONE` (default `Europe/Rome`). Una promozione generica si applica anche alle combinazioni prive di una riga specifica. In presenza di più righe per la stessa combinazione viene selezionata quella con inizio più recente, poi con ID maggiore; questa selezione non replica tutte le priorità e i contesti di prezzo PrestaShop (negozio, gruppo, valuta e paese).
