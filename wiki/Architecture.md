# Architecture

Il progetto è un microservizio che integra PrestaShop con il marketplace DolceZampa e servizi esterni. Le aree documentate sono:

- **API e autenticazione**: endpoint per storefront e seller; l'identità del seller viene derivata dal token autenticato.
- **Catalogo**: snapshot di prodotto, mapping slug, liste e filtri con cache Redis e invalidazione tramite webhook.
- **Promozioni**: pacchetti e richieste seller, checkout Stripe e attivazione tramite webhook.
- **Pagamenti e finanza**: checkout, Stripe Connect, ledger immutabile, commissioni e strumenti di riconciliazione.
- **Processi asincroni**: scheduler e worker per job pianificati, aggiornamenti e recuperi.

## Documenti di approfondimento

- [README del progetto](https://github.com/Dolce-Zampa/WebService/blob/master/README.md)
- [Scheduler](https://github.com/Dolce-Zampa/WebService/blob/master/bin/SCHEDULER.md)
- [Cache prodotti](https://github.com/Dolce-Zampa/WebService/blob/master/docs/PRODUCT_CACHE.md)
