# Product cache

Product base data and complete snapshots, slug mappings, catalog lists, counts and filters are cached forever. Redis tags and atomic locks are required. Product detail routes use the service snapshot directly, avoiding a second HTTP response cache that could hide changed bundle dependencies. Successful category HTTP responses are cached forever too.

The existing PrestaShop webhook with `product:{id}` invalidates that product and queues its refresh. The product tag clears base data, complete associations and product image builder entries; old slug mappings become unusable through the changed product revision. Other product snapshots stay usable. Catalog pages are invalidated broadly to cover moves between categories. Snapshots embedding accessories or bundles validate those products' revisions before reuse. Global revision changes only on a full cache clear; per-tag revisions prevent stale writes during webhooks.

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
