# Operations

## Scheduler

Il processo scheduler è un daemon basato su Symfony Scheduler e Messenger. Il provider definisce gli orari, il transport genera i messaggi pianificati e i worker li instradano agli handler. Il job documentato attivo invia ogni giorno le richieste di recensione agli ordini consegnati, evitando duplicati.

Il processo va eseguito sotto un supervisore che ne gestisca riavvio e arresto. La guida include componenti, aggiunta di nuovi job, log e dipendenze: [Scheduler](https://github.com/Dolce-Zampa/WebService/blob/master/bin/SCHEDULER.md).

## Worker e cache prodotti

Per il warmup catalogo, deployment e comandi di avvio dei worker, consultare [Product cache](https://github.com/Dolce-Zampa/WebService/blob/master/docs/PRODUCT_CACHE.md).

## Recupero Stripe Connect

La guida Stripe Connect documenta i comandi per ritentare trasferimenti pendenti, allocazioni in coda e reversal di rimborsi: [Stripe Connect Express](https://github.com/Dolce-Zampa/WebService/blob/master/docs/stripe-connect.md).
