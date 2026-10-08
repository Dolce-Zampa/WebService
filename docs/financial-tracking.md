# Financial tracking

La tabella `${PS_TABLE_PREFIX}financial_transactions` è il registro dei movimenti finanziari del marketplace. Ogni riga descrive un movimento atomico: una commissione e un payout correlati sono quindi due righe distinte, collegate quando necessario da `related_transaction_id`.

## Riferimenti e storico

`order_id`, `artisan_id` e `order_item_id` sono riferimenti tecnici a PrestaShop e sono facoltativi per i movimenti che non hanno un ordine o un artigiano. Non hanno vincoli di chiave esterna intenzionalmente: una modifica o una cancellazione nell'applicazione non deve impedire l'audit del ledger.

Le colonne `order_reference`, `artisan_reference` e `order_item_reference` devono contenere gli identificativi disponibili al momento della registrazione. Sono snapshot immutabili e permettono di riconciliare lo storico senza leggere lo stato corrente di ordini, prodotti o artigiani.

Per il provider vanno conservati `provider`, `provider_account_id`, `provider_transaction_id` e, quando presente, `provider_event_id`. La coppia `(provider, provider_event_id)` è univoca e rende idempotente l'importazione degli eventi provider. `source_event_type` e `source_event_id` identificano l'evento applicativo originario. `metadata` può contenere soli dettagli strutturati, non sensibili, indispensabili alla riconciliazione: non deve contenere dati di carte o altri dati di pagamento riservati.

## Tipi, importi e valuta

I tipi ammessi sono:

| Tipo | Significato |
| --- | --- |
| `payment` | Incasso dal cliente per un ordine. |
| `refund` | Rimborso al cliente. |
| `commission` | Commissione maturata dal marketplace. |
| `payout` | Accredito all'artigiano o trasferimento in uscita. |
| `provider_fee` | Costo addebitato dal provider di pagamento. |
| `chargeback` | Storno/disputa addebitata dal provider. |
| `adjustment` | Rettifica manuale o tecnica, sempre motivata nei metadati. |

`amount` è un `DECIMAL(20,6)`: non sono ammessi floating point. Il segno è sempre osservato dal punto di vista del saldo del marketplace: incassi e commissioni sono positivi; rimborsi, payout, commissioni del provider e chargeback sono negativi. Una rettifica può avere entrambi i segni. Tutti gli importi devono essere espressi nella valuta ISO 4217 a tre caratteri in `currency`; non si sommano importi di valute diverse.

## Stati e date

Gli stati ammessi sono `pending`, `available`, `paid`, `reversed`, `failed` e `cancelled`.

* `pending` indica un evento ricevuto ma non ancora confermato.
* `available` indica un importo confermato e disponibile per il successivo regolamento.
* `paid` indica un movimento regolato, ad esempio un payout completato.
* `reversed`, `failed` e `cancelled` sono terminali.

Le transizioni consentite sono `pending → available|paid|reversed|failed|cancelled`, `available → paid|reversed` e `paid → reversed`. Una rettifica economica non modifica l'importo della riga originaria: registra un nuovo movimento `adjustment` collegato tramite `related_transaction_id`. `occurred_at` è il momento economico dell'evento, `available_at` quello in cui diventa disponibile e `settled_at` quello del regolamento. `created_at` e `updated_at` tracciano l'acquisizione e l'ultimo aggiornamento locale.

Gli indici composti per ordine/artigiano e periodo supportano gli estratti e le riconciliazioni; quelli provider e source-event supportano l'importazione e la tracciabilità degli eventi originali.
