# Integrations

## Facebook tramite Amazon Cognito

Il frontend usa la Hosted UI Cognito con provider Facebook. Cognito ritorna alla callback dello storefront; il WebService valida lo state OAuth monouso, scambia il codice e crea o collega il cliente PrestaShop tramite il `sub` Cognito.

Configurare il provider Facebook nell'User Pool, la callback del client Hosted UI, authorization code grant e scope `openid`, `email`, `profile`. Il backend richiede `AWS_COGNITO_DOMAIN` e `AWS_COGNITO_REDIRECT_URI`. Segreti Facebook e Cognito restano lato server.

Dettagli: [Facebook authentication](https://github.com/Dolce-Zampa/WebService/blob/master/docs/FACEBOOK_AUTH.md).

## Stripe Connect Express

Connect è opzionale. Il flusso si attiva con entrambe le impostazioni:

```dotenv
STRIPE_PAYMENT_FLOW=connect
STRIPE_CONNECT_ENABLED=true
```

Gli endpoint seller creano e gestiscono l'account Express, l'onboarding, lo stato, il login link alla Dashboard e i trasferimenti. L'identità del produttore e l'account Stripe vengono risolti dal backend; il client non deve inviare tali identificativi. L'attivazione onboarding dipende dallo stato verificato (`ready_for_transfers`), non dal solo redirect di ritorno.

La guida copre anche job di recupero, rimborsi, dispute e webhook: [Stripe Connect Express](https://github.com/Dolce-Zampa/WebService/blob/master/docs/stripe-connect.md).

## Promozioni prodotto

Il frontend seller carica pacchetti e prodotti promuovibili, crea la richiesta e avvia il checkout. Il prezzo è sempre determinato dal backend. L'attivazione è webhook-driven dopo la conferma Stripe; il redirect di successo non è una conferma di attivazione.

Endpoint e flusso: [FE Integration — Product Promotions](https://github.com/Dolce-Zampa/WebService/blob/master/docs/README_FE_PROMOTIONS.md).
