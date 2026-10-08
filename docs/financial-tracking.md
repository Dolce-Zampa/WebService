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

## Journal append-only e idempotenza

`${PS_TABLE_PREFIX}financial_transaction_events` conserva il journal immutabile del movimento. La riga `recorded` viene creata insieme al movimento; i cambi di stato producono una riga `status_changed`, mentre correzioni e storni producono rispettivamente `correction` e `reversal` sul nuovo movimento collegato. Perciò `financial_transactions.status` è lo stato iniziale e lo stato corrente si ricava dall'ultimo evento `recorded` o `status_changed`, ordinato per `occurred_at` e `id`.

Ogni evento richiede una `idempotency_key` univoca. Il servizio la controlla prima dell'inserimento e la chiave unica del database risolve le richieste duplicate concorrenti: una ripetizione restituisce l'evento già registrato. Le transizioni con chiavi diverse vengono serializzate tramite lock della riga del movimento e validate contro l'ultimo stato registrato.

Il journal conserva sia `occurred_at` (quando il fatto economico o il cambio di stato è avvenuto) sia `received_at` (quando il sistema lo ha acquisito), oltre ai riferimenti `source_event_type` e `source_event_id` dell'evento che l'ha originato. Il servizio non calcola commissioni, non cambia il segno degli importi e non interpreta payload del provider: queste decisioni restano ai chiamanti che registrano il movimento.

## Stripe Checkout

Il webhook Stripe alimenta il ledger a partire dall'evento firmato del provider, non dallo stato corrente dell'ordine. Per ogni movimento vengono conservati l'importo lordo nella valuta del provider, l'eventuale Checkout Session, il PaymentIntent (come `provider_transaction_id`), l'account Stripe e lo snapshot dell'ordine quando è già disponibile. Nei metadati entrano solo riferimenti tecnici non sensibili (`stripe_session_id`, `cart_id` e stato di pagamento): non vengono mai copiati carta, indirizzi, email o il payload completo.

La mappatura è: webhook Stripe in attesa → `pending`; `payment_failed`/`async_payment_failed` → `failed`; `checkout.session.completed`, `async_payment_succeeded` e `payment_intent.succeeded` → `available`; scadenza della sessione → `cancelled`. In particolare, “completed” non mappa a `paid`: `available` indica un incasso cliente confermato e disponibile, mentre `paid` rimane lo stato di un regolamento successivo, ad esempio un payout. Ogni webhook ha una chiave idempotente derivata dal suo event id; webhook distinti che ripetono lo stesso stato sono comunque eventi append-only del journal.
