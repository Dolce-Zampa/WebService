# Product cache

Upstream product payloads, slug lookups, catalog lists, counts and filters are cached for five minutes before HTTP. Complete product snapshots are cached separately; restoration never reapplies VAT. Redis with cache tags and atomic locks is required, as for existing tagged caches.

Product cache invalidation accepts the existing webhook payload containing a `product:{id}` tag. It invalidates catalog data and product HTTP responses, then queues a rebuild of the complete product snapshot. Catalog invalidation is deliberately broad to cover moves between categories and embedded accessories/bundles. Category pages rebuild on their next request; no expensive preload of every filter/page combination is performed.

Start the additional worker in the deployment using:

```sh
php bin/workers/worker-product-cache
```

Run it under the existing process manager with automatic restart. It retries a failed refresh up to three times. Invalidation remains effective if the worker is stopped; the next request loads fresh data. Do not invoke a rebuild synchronously from the PrestaShop webhook.

After deployment clear existing cache once to retire indefinitely cached HTTP responses. The service uses a new versioned cache namespace. Check `X-Cache` and upstream timings for repeated product/category requests. The existing `no_cache=1` bypasses HTTP response caching; `APP_DISABLE_CACHE=true` bypasses all product service caching as well.
