# Financial tracking

La tabella `${PS_TABLE_PREFIX}financial_transactions` è il registro dei movimenti finanziari del marketplace. Ogni riga descrive un movimento atomico: una commissione e un payout correlati sono quindi due righe distinte, collegate quando necessario da `related_transaction_id`.

## Riferimenti e storico

`order_id`, `customer_id`, `artisan_id` e `order_item_id` sono riferimenti tecnici a PrestaShop e sono facoltativi per i movimenti che non hanno un ordine, un cliente o un artigiano. Non hanno vincoli di chiave esterna intenzionalmente: una modifica o una cancellazione nell'applicazione non deve impedire l'audit del ledger. Le viste amministrative espongono per il cliente il solo ID tecnico, non nome, email o indirizzo.

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
| `shipping` | Costo di spedizione esplicito collegabile facoltativamente a un ordine. |
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

## Commissioni per riga d'ordine

`${PS_TABLE_PREFIX}financial_commission_snapshots` conserva il calcolo immutabile effettuato per ciascuna riga d'ordine. La policy `marketplace-commission-v1` applica una commissione fissa del **20%** ai soli prodotti, dopo gli sconti assegnati alla riga; la spedizione è esclusa. Per l'artigiano professionista soggetto a IVA la base è `product_total_tax_excl`; per un artigiano non soggetto a IVA è `product_total_tax_incl`. La tabella conserva entrambi i totali prodotto forniti dal checkout, lo stato IVA, la regola e la versione, la base, la commissione e la spettanza dell'artigiano.

Il calcolo usa aritmetica decimale e `rounding_mode = none`: non vengono applicati arrotondamenti; perciò le colonne hanno scala 12 e mantengono anche frazioni sotto il centesimo. La chiave unica `(order_id, order_item_id)` rende idempotente la registrazione e fa prevalere lo snapshot esistente su qualunque modifica futura alla policy o ai dati del venditore. Più righe dello stesso ordine, anche di artigiani diversi, generano snapshot distinti.

## Stripe Checkout

Il webhook Stripe alimenta il ledger a partire dall'evento firmato del provider, non dallo stato corrente dell'ordine. Per ogni movimento vengono conservati l'importo lordo nella valuta del provider, l'eventuale Checkout Session, il PaymentIntent (come `provider_transaction_id`), l'account Stripe e lo snapshot dell'ordine quando è già disponibile. Se il metadata Stripe contiene `id_customer`, viene trasferito esclusivamente nella colonna tecnica `customer_id`. Nei metadati entrano solo riferimenti tecnici non sensibili (`stripe_session_id`, `cart_id` e stato di pagamento): non vengono mai copiati carta, indirizzi, email o il payload completo.

La mappatura è: webhook Stripe in attesa → `pending`; `payment_failed`/`async_payment_failed` → `failed`; `checkout.session.completed`, `async_payment_succeeded` e `payment_intent.succeeded` → `available`; scadenza della sessione → `cancelled`. In particolare, “completed” non mappa a `paid`: `available` indica un incasso cliente confermato e disponibile, mentre `paid` rimane lo stato di un regolamento successivo, ad esempio un payout. Ogni webhook ha una chiave idempotente derivata dal suo event id; webhook distinti che ripetono lo stesso stato sono comunque eventi append-only del journal.

### Rimborsi, storni e spedizione

Un webhook `refund.*` con oggetto Stripe Refund identificabile (`re_...`) genera un nuovo movimento `refund`, di importo negativo pari all'importo ricevuto da Stripe e collegato, tramite `related_transaction_id`, al movimento `payment` del relativo PaymentIntent. Ciò copre rimborsi totali, parziali e più rimborsi sullo stesso pagamento: ogni Refund ha il proprio identificativo provider e quindi il suo movimento; retry dello stesso webhook sono idempotenti. Gli aggiornamenti successivi del medesimo Refund non mutano la riga, ma aggiungono un evento di stato al journal.

Il webhook viene rifiutato finché il pagamento d'origine non è presente: una riga di rimborso senza legame sarebbe un fatto contabile non riconciliabile. Stripe può quindi ritentare in caso di consegna fuori ordine.

Non viene calcolato né inventato alcuno storno di commissione o rettifica payout: per registrarli servono sia un importo esplicito fornito dal provider o da una policy contabile approvata, sia il movimento `commission`/`payout` originario da collegare. Analogamente il Refund Stripe non espone una ripartizione verificabile della spedizione; il pagamento Checkout resta lordo e non viene creata una componente spedizione separata senza un importo provider verificabile, per evitare duplicazioni o allocazioni presunte.

Quando un evento Stripe include un `balance_transaction` con la sua `fee`, il webhook registra anche un movimento negativo `provider_fee`, idempotente sulla balance transaction. Se l'evento contiene solo l'identificativo, il webhook recupera la balance transaction da Stripe e usa la sua valuta. Abilitare gli eventi `charge.succeeded` e `charge.updated` dell'account piattaforma sull'endpoint webhook. Gli eventi di pagamento degli account collegati non alimentano il ledger della piattaforma nel flusso separate charges and transfers. Se la Charge è riuscita ma non contiene ancora una balance transaction, il webhook recupera la Charge corrente e risponde con errore se la balance transaction non è ancora disponibile. Anche l'assenza del pagamento nel ledger produce un errore per consentire a Stripe di riprovare. Non viene applicata alcuna formula stimata.

Per ricevere gli eventi di account Connect tramite una destination Stripe separata, configurare il relativo signing secret in `STRIPE_CONNECT_WEBHOOK_SECRET` e mantenere il signing secret della piattaforma in `STRIPE_WEBHOOK_SECRET`. Il server verifica la firma rispetto ai secret configurati prima di esaminare l'evento; l'opzione Connect può restare vuota se non esiste una destination separata.

Per individuare fee storiche o webhook non consegnati, eseguire la riconciliazione per intervalli di creazione delle Charge, con date UTC: l'inizio è incluso e la fine esclusa. Il primo comando simula la riconciliazione senza scrivere; il secondo la applica in modo idempotente:

```sh
php bin/console app:stripe:reconcile-fees --from=2026-01-01 --to=2026-02-01
php bin/console app:stripe:reconcile-fees --from=2026-01-01 --to=2026-02-01 --apply
```

Il comando esamina l'account piattaforma; `--account=acct_...` permette una verifica mirata di un account collegato solo se i relativi pagamenti sono già presenti nel ledger. Il comando pagina tutte le Charge, ignora quelle non riuscite o senza PaymentIntent e segnala come errore le fee ancora in attesa o non riconciliabili, ad esempio perché manca il pagamento interno. Risolvere i casi segnalati e ripetere il comando: la simulazione o l'esecuzione non dimostrano la completezza dei pagamenti non presenti nel ledger. Le fee recuperate non inventano un ID di evento webhook e riportano come origine `stripe.reconciliation.fee`.

## Riconciliazione amministrativa per ordine

`GET /api/admin/financial/orders/{orderId}/reconciliation?page=1&per_page=50` espone una vista esclusivamente amministrativa dell'ordine. Richiede una sessione finanziaria emessa per un dipendente Back Office PrestaShop attivo e con profilo autorizzato; gli identificativi dell'ordine non attribuiscono alcun diritto di lettura.

La risposta contiene gli identificativi disponibili, una timeline paginata dei movimenti e dei relativi eventi di stato, incassi, rimborsi, commissioni calcolate e registrate, spettanze, payout e residui. I payout sono esposti come importi positivi pagati al venditore, pur essendo negativi nel ledger dal punto di vista del marketplace. La spedizione resta `not_recorded` finché non esiste un movimento esplicito: l'eventuale differenza non spiegata viene mostrata senza attribuirla automaticamente a spedizione, sconti o altre voci.

## Analisi, costi e import iniziale

`GET /api/admin/financial-analytics` usa gli stessi filtri temporali, per ordine e per cliente del report e restituisce i totali per valuta e tipo, oltre ai clienti con più ordini e saldo netto. Richiede il gruppo amministrativo finanziario e non restituisce dati personali.

`POST /api/admin/financial-expenses` aggiunge un fatto immutabile negativo di tipo `shipping`, `provider_fee` o `adjustment`. Richiede `amount`, `currency`, `occurred_at` e una `idempotency_key`; `order_id` e `reference` sono facoltativi. Se viene indicato l'ordine, il riferimento e il cliente sono risolti lato server: il client non può associare arbitrariamente il costo a un cliente.

Gli ordini PrestaShop validi già esistenti possono essere portati nel ledger con:

```sh
php bin/console app:financial:backfill-orders --dry-run
php bin/console app:financial:backfill-orders
```

L'import registra il totale effettivamente pagato in `orders.total_paid_tax_incl`, la valuta, l'ID cliente e il riferimento ordine. È idempotente per `id_order`, quindi è sicuro rieseguirlo; non genera costi di spedizione o commissioni provider stimati.

## Accesso dipendenti Back Office

`POST /api/admin/auth/login` verifica email e password nella tabella PrestaShop `employee`; è ammesso soltanto un dipendente attivo con `id_profile` incluso in `FINANCIAL_EMPLOYEE_PROFILE_IDS` (per default `1`, SuperAdmin). La risposta rilascia una sessione Bearer firmata di breve durata, limitata alle route `/api/admin/financial-*`; non è un token cliente Cognito e non può essere usata sulle API cliente o seller.

La firma richiede un valore casuale dedicato di almeno 32 caratteri in `FINANCIAL_DASHBOARD_SESSION_SECRET`. `FINANCIAL_DASHBOARD_SESSION_TTL` imposta la durata, con limite massimo di dodici ore. A ogni richiesta il backend ricontrolla `employee.active` e il profilo corrente, così la disattivazione dell'account o il cambio profilo revoca immediatamente l'accesso.
## Notifica vendita al venditore

Dopo un checkout firmato e finalizzato, ogni venditore riceve una sola notifica relativa esclusivamente ai propri prodotti. Il journal di consegna è idempotente e i retry non bloccano la finalizzazione dell'ordine.

## Test e prerequisiti

La suite finanziaria usa fixture SQLite in memoria e copre il journal append-only, precisione `DECIMAL(20,6)`, snapshot della commissione, retry dei webhook, stati pendenti/falliti/disponibili, payout, rimborsi parziali e privacy del metadata Stripe. Per eseguirla:

```sh
composer install
vendor/bin/phpunit Test/FinancialLedgerServiceTest.php Test/CommissionSnapshotServiceTest.php Test/StripeFinancialWebhookLedgerTest.php
```

Servono le estensioni PHP `sqlite3` e `bcmath`, oltre alle dipendenze bloccate in `composer.lock`. Il bootstrap applicativo richiede anche un `.env` valido; i test di dominio/infrastruttura non necessitano di credenziali reali Stripe.

Restano coperti il rifiuto della firma Stripe non valida nel controller webhook e la whitelist di soli identificativi tecnici nel ledger. I test API verificano anche l'isolamento per artigiano e il rifiuto di identificativi d'ordine manomessi o appartenenti ad altri soggetti.

## Riconciliazione dei payout Stripe Connect

Il payout bancario è un fatto relativo all'intero account Connect e può aggregare più movimenti: non viene attribuito automaticamente a un ordine né registrato una seconda volta come movimento nel ledger. I webhook `payout.created`, `payout.updated`, `payout.paid`, `payout.failed` e `payout.canceled` sono conservati in un journal dedicato e aggiornano lo snapshot dell'account; un successivo fallimento può cambiare lo stato di un payout già segnalato come `paid`. Attivare questi eventi per gli account Connect nell'endpoint Stripe. Un payout della piattaforma privo di account Connect viene ignorato; un account Connect non ancora noto resta senza venditore associato finché un evento successivo, una sua ripetizione oppure la riconciliazione non conferma l'associazione.

Per confrontare anche i payout esistenti con lo stato corrente Stripe, prima eseguire l'anteprima per un account già associato a un venditore; usare `--apply` solo per salvare le osservazioni:

```sh
php bin/console app:stripe-connect:backfill-payouts --account=acct_...
php bin/console app:stripe-connect:backfill-payouts --account=acct_... --apply
```

Il comando scorre tutte le pagine dell'API Stripe e inserisce o aggiorna soltanto gli snapshot diversi da quelli locali. Per ogni modifica conserva un'osservazione `reconciliation.snapshot`, distinta dagli eventi webhook e senza inventare un identificativo webhook. L'orario della scansione indica quando è stata acquisita l'osservazione, non quando è avvenuta la transizione originaria: non è quindi utilizzabile per attribuire quel cambio a una settimana storica. Il riepilogo settimanale non conteggia queste osservazioni come eventi webhook del periodo. Un webhook ricevuto durante la scansione con data almeno pari all'inizio della scansione prevale sull'osservazione; la ripetizione del comando è idempotente e, dopo un errore, conserva quanto già acquisito. I dati storici non disponibili nell'API non possono essere ricostruiti dal comando.

### Attribuzione automatica di un payout agli ordini

Per un payout automatico `paid`, associato a un venditore e con riconciliazione Stripe completata, il seguente comando legge tutte le balance transaction del payout nel contesto dell'account Connect e confronta ogni pagamento con `destination_payment` del Transfer della piattaforma:

```sh
php bin/console app:stripe-connect:reconcile-payout-orders --account=acct_... --payout=po_...
php bin/console app:stripe-connect:reconcile-payout-orders --account=acct_... --payout=po_... --apply
```

La prima esecuzione è un'anteprima. L'operazione attribuisce importi agli ordini soltanto quando l'intero payout è dimostrabile: importi e valute coincidono, ogni pagamento corrisponde a un unico Transfer locale e l'insieme non contiene fee, storni, conversioni o transazioni estranee. Gli altri casi restano non attribuiti e non producono righe parziali. I payout manuali non sono attribuibili con questa API Stripe. Le associazioni già registrate rimangono come evidenza anche se il payout diventa fallito o cancellato, ma non contano come pagamento; possono allora essere seguite da una nuova attribuzione su un payout successivo. Il comando restituisce errore in caso di payout manuale, non pronto, non disponibile o non attribuibile, e successo per anteprima, acquisizione o attribuzione già registrata.

## Attribuzione manuale dei payout Connect

Quando le prove disponibili non consentono l'attribuzione automatica, un operatore con accesso amministrativo al server può associare esplicitamente quote di un payout bancario pagato ai Transfer dello stesso artigiano e della stessa valuta. Prima di procedere occorre verificare l'evidenza esterna: il comando non deduce la relazione e non muove fondi. L'importo è espresso nelle unità minime Stripe. La chiave è univoca e va riutilizzata invariata per ritentare la stessa operazione; indicare sempre motivo, riferimento all'evidenza e identificativo dell'operatore, senza dati personali o credenziali. Per impostazione predefinita viene eseguita solo una simulazione:

```sh
php bin/console app:stripe-connect:attribute-payout --account=acct_EXAMPLE --payout=po_EXAMPLE --transfer=tr_EXAMPLE --amount-minor=500 --key=case-123 --reason='Riconciliazione verificata' --evidence=report-123 --operator=admin-1
# Ripetere gli stessi argomenti aggiungendo --apply per salvare.
```

Le quote cumulate non possono superare il payout o il Transfer. Gli importi assegnati a payout successivamente falliti o annullati restano nello storico ma non impediscono di attribuire un payout sostitutivo; i Transfer con storni richiedono verifica separata. Per correggere un'attribuzione esistente, usare il suo ID e registrare uno storno totale con una nuova chiave, motivo ed evidenza; quindi, se necessario, registrare una nuova attribuzione corretta:

```sh
php bin/console app:stripe-connect:attribute-payout --reverse-id=123 --key=case-123-reversal --reason='Correzione attribuzione' --evidence=report-124 --operator=admin-1
# Aggiungere --apply dopo aver esaminato la simulazione.
```

Il journal conserva sia l'attribuzione originaria sia lo storno; questa correzione riguarda soltanto la classificazione contabile, non il pagamento bancario.
