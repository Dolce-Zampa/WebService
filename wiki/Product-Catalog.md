# Product Catalog

I dati base e gli snapshot completi dei prodotti, i mapping slug, gli elenchi catalogo, conteggi e filtri sono memorizzati in cache. La cache usa tag Redis e lock atomici. Le route dettaglio usano direttamente lo snapshot del servizio; le pagine categoria HTTP riuscite sono anch'esse memorizzate.

## Invalidazione e aggiornamento

Il webhook PrestaShop `product:{id}` invalida il prodotto e ne accoda l'aggiornamento. Le pagine catalogo vengono invalidate in modo ampio per coprire gli spostamenti tra categorie. Gli snapshot che includono accessori o bundle verificano la revisione dei prodotti associati.

Il warmup notturno, alle 03:00 Europe/Rome, accoda solo prodotti attivi privi di uno snapshot completo valido, iniziando dagli ID più alti. I job sono deduplicati per 24 ore e processati in sequenza a bassa priorità; gli aggiornamenti da webhook hanno precedenza.

Comandi e note di deployment sono nella guida [Product cache](https://github.com/Dolce-Zampa/WebService/blob/master/docs/PRODUCT_CACHE.md).
