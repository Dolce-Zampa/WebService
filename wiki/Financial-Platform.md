# Financial Platform

## Ledger

`financial_transactions` registra movimenti atomici, mentre `financial_transaction_events` conserva il journal append-only. Importi e valute sono espliciti; retry ed eventi duplicati sono gestiti con chiavi di idempotenza. Le correzioni economiche producono nuovi movimenti collegati, senza riscrivere lo storico.

Il ledger può registrare pagamenti, rimborsi, commissioni, payout, costi provider, spedizione, chargeback e rettifiche. Gli snapshot di commissione per riga d'ordine conservano la policy e i valori usati al momento del calcolo.

## Stripe e riconciliazione

I webhook Stripe alimentano il ledger solo dopo la verifica della firma. La guida descrive la mappatura degli eventi, rimborsi, fee provider, backfill degli ordini e viste amministrative di riconciliazione e analisi.

## Sicurezza

Le route finanziarie applicano autorizzazione esplicita e default deny. Il proprietario viene determinato lato server; gli ID inviati dal client non dimostrano proprietà. Le risposte evitano dati personali e payload provider, mentre i metadati sono limitati a riferimenti scalari di riconciliazione.

## Riferimenti

- [Financial tracking](https://github.com/Dolce-Zampa/WebService/blob/master/docs/financial-tracking.md)
- [Sicurezza Financial Tracking](https://github.com/Dolce-Zampa/WebService/blob/master/docs/FINANCIAL_SECURITY.md)
- [Stripe Connect Express](https://github.com/Dolce-Zampa/WebService/blob/master/docs/stripe-connect.md)
