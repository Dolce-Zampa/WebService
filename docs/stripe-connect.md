# Stripe Connect Express

Connect is an optional payment module. The legacy platform Stripe Checkout
remains active unless both settings below are enabled:

```dotenv
STRIPE_PAYMENT_FLOW=connect
STRIPE_CONNECT_ENABLED=true
```

All endpoints require a seller Bearer token. The API derives the manufacturer
from that token; clients must never supply a manufacturer or Stripe account ID.

## Create the Express account

`POST /api/seller/stripe-connect/accounts`

Creates one Stripe Express connected account for the authenticated
manufacturer. The account is created with the server-configured country and
the `transfers` and `card_payments` capabilities. The current Checkout flow
remains a charge on the DolceZampa platform: `card_payments` does not route
customer payments to the seller account by itself. Repeating the request is safe: it returns the
already associated account and `created: false`.

```json
{
  "success": true,
  "data": {
    "created": true,
    "account": {
      "connected": true,
      "account_id": "acct_...",
      "details_submitted": false,
      "payouts_enabled": false,
      "transfers_enabled": false,
      "ready_for_transfers": false,
      "requirements_due": []
    }
  }
}
```

## Start or resume onboarding

`POST /api/seller/stripe-connect/onboarding`

Optional JSON body fields `return_url` and `refresh_url` override the two
server defaults. The response contains a single-use `onboarding_url`; return
it only to the authenticated seller and do not send it by email.

## Read account status

`GET /api/seller/stripe-connect/status`

Refreshes the account state from Stripe. A redirect to `return_url` does not
by itself mean onboarding is complete: use `ready_for_transfers` before
allowing a seller to receive transfers.

## Commissione marketplace

Per ogni ordine e venditore, il WebService trattiene la commissione applicando
la percentuale configurata sul venditore, con default del 20% e minimo di 1 €.
Il valore legacy `0.00` nella colonna PrestaShop `manufacturer_details.commission`
viene interpretato come non configurato e quindi usa il default della
piattaforma. La commissione non include le spese di spedizione. I valori di
default si possono sovrascrivere con `STRIPE_CONNECT_DEFAULT_COMMISSION_RATE` e
`STRIPE_CONNECT_MINIMUM_COMMISSION_EUR`.

## Open the Express Dashboard

`POST /api/seller/stripe-connect/login-link`

Creates a single-use Stripe Express Dashboard URL for the authenticated seller.
The WebService resolves the connected account from its own manufacturer mapping;
the client never sends an account id. Call this only after `ready_for_transfers`
is `true`, then redirect the seller to the returned `dashboard_url`. Sellers can
request an Instant Payout there when Stripe marks their account and available
balance eligible. This is an on-demand payout; Stripe's standard payout schedule
remains in effect for funds they do not request instantly.

## Metodi di pagamento

Checkout usa la configurazione metodi di pagamento dell'account piattaforma
DolceZampa. I metodi vengono selezionati e gestiti nel Dashboard Stripe da
DolceZampa; non vengono scelti dal venditore. Il flusso Connect attuale usa
separate charges and transfers, quindi i metodi di pagamento configurati per
gli account connessi non controllano questo Checkout.

## Ordini pagati prima dell'onboarding

Un ordine pagato non viene annullato né fatto ritentare perché l'artigiano non
ha ancora completato Stripe Connect. Per ogni quota venditore il WebService
salva invece un trasferimento con stato `pending`, anche quando non esiste
ancora un `stripe_account_id`.

Quando Stripe invia `account.updated` per un account collegato e il servizio
verifica `details_submitted`, `payouts_enabled` e `transfers_enabled`, i
trasferimenti pendenti vengono eseguiti usando la stessa chiave di idempotenza.
Il recupero è anche eseguito ogni 15 minuti dallo scheduler:

```bash
php bin/console app:stripe-connect:retry-pending-transfers --limit=100
```

## Ordini non ancora leggibili da PrestaShop

Un webhook Stripe può arrivare quando PrestaShop ha accettato la creazione
dell'ordine ma la riga `orders` non è ancora disponibile per la lettura. Il
webservice salva in `stripe_connect_transfer_jobs` l'allocazione del pagamento
prima di provare a creare i transfer. Il job viene ritentato ogni cinque minuti
e usa `id_cart` per risolvere direttamente l'ordine nel database, senza
dipendere dalla cache o da una seconda richiesta HTTP a PrestaShop. Dopo cinque
tentativi falliti passa a `manual_review` e genera un log critical; resta
recuperabile con il comando manuale.

Per elaborare manualmente i job già in coda:

```bash
php bin/console app:stripe-connect:retry-queued-allocations --limit=100
```

Per recuperare un ordine storico che non aveva ancora un job, usare i valori
verificati dell'ordine, del carrello e del PaymentIntent:

```bash
php bin/console app:stripe-connect:recover-allocation \
  --order-id=83 \
  --cart-id=713 \
  --payment-intent=pi_... \
  --transfer-group=dz_cart_713
```

Il comando è idempotente: per ogni PaymentIntent conserva un solo job e i
transfer per artigiano mantengono la loro chiave di idempotenza Stripe.

Configurare l'endpoint webhook `/api/webhooks/stripe/checkout` anche per
l'evento Stripe `account.updated`; il job pianificato resta la protezione
aggiuntiva contro eventi in ritardo o mancati.

## Rimborsi e contestazioni

Un rimborso del charge della piattaforma non annulla automaticamente i
Transfer già eseguiti. Al webhook `refund.*` riuscito, il WebService calcola
la quota venditore in proporzione all'importo effettivamente trasferito:
riduce i Transfer ancora pendenti oppure crea un `Transfer Reversal` per quelli
già eseguiti. La quota della piattaforma, inclusi commissione e spedizione, non
viene addebitata al venditore.

Se Stripe non può completare il reversal, la posizione resta
`recovery_required` per una verifica operativa. Il retry è esplicito e limitato:

```bash
php bin/console app:stripe-connect:retry-refund-reversals --limit=100
```

Un evento `charge.dispute.*` non genera invece un reversal automatico: salva le
quote attribuibili ai venditori con stato `pending_review`, così da permettere
la raccolta delle evidenze e una decisione sul recupero prima di spostare fondi.

Le quote senza un account pronto non consumano il batch dello scheduler. Un
Transfer che fallisce dopo che l'account è pronto viene tentato al massimo
cinque volte con la stessa chiave di idempotenza; poi passa a `manual_review`.
In quel caso la piattaforma conserva i fondi e l'operatore riconcilia il
Transfer in Stripe prima di decidere se riprenderlo o rimborsare il cliente.
