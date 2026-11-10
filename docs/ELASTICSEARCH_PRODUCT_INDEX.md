# Product search index

`/api/search` reads product results from Elasticsearch. PrestaShop search is
used only if the Elasticsearch request fails, including an incomplete response.
Product changes enqueue updates; the Elasticsearch worker must be running for
the index to reflect those changes.

After deploying the updated API and worker, an operator can enqueue all active
products with `php bin/console elk:index-bulk --all`. The command only enqueues
work: a successful exit does not mean that indexing has finished. Failed jobs
are retried and then kept in `elk_queue_failed` for investigation.

Once the queue has drained, `php bin/console elk:audit-products` compares the
active product IDs in the catalog with usable active documents in Elasticsearch.
It reports missing IDs, extra active IDs, duplicate IDs and invalid documents as
JSON and returns a nonzero exit status when any are found. It reads both legacy
`data` documents and current root-level documents. Counts can change while the
catalog or index is being updated, so repeat the audit after writes have settled.

The backfill updates canonical product IDs but does not remove legacy documents
with different Elasticsearch IDs. Investigate duplicate and extra documents
before choosing a separate cleanup or index-rebuild procedure. A product without
the identity, name, price or URL required to display it is reported as a failed
indexing job and needs its source data corrected.
