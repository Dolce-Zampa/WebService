# MlabFactory API module

Modulo PrestaShop realizzato da **MlabFactory / Marco De Felice** per esporre API JSON dedicate a:

- registrazione cliente
- creazione cliente
- login cliente
- invio form contatto
- creazione/aggiornamento carrello
- finalizzazione ordine
- integrazione come metodo di pagamento custom

## Autenticazione

Ogni endpoint richiede una chiave Webservice PrestaShop attiva, inviata in uno dei seguenti modi:

- `Authorization: Bearer <webservice_key>`
- header `X-WS-Key: <webservice_key>`
- query string `?ws_key=<webservice_key>`

## Endpoint

Sono registrate sia le route parlanti sia il fallback nativo modulo `index.php?fc=module&module=mlabfactoryapi&controller=...`.
L'elenco seguente descrive **gli endpoint del modulo PrestaShop** (non le route del microservizio Slim).

- `POST /api/register`
- `POST /api/login`
- `POST /api/contact`
- `POST /api/customers`
- `GET /api/account?id_customer=12`
- `PUT /api/account`
- `GET /api/addresses?id_customer=12`
- `PUT /api/addresses`
- `POST /api/logout`
- `GET /api/carts?id_customer=12`
- `GET /api/carts?id_guest=34`
- `POST /api/carts`
- `GET /api/cart_rules`
- `GET /api/cart_rules?code=ESTATE2026&id_cart=55&id_customer=12`
- `POST /api/cart_rules`
- `GET /api/wishlists?id_customer=12`
- `POST /api/wishlists`
- `PUT /api/wishlists`
- `DELETE /api/wishlists`
- `GET /api/orders?id_order=99`
- `GET /api/orders?reference=ABCDEF`
- `GET /api/orders?id_customer=12`
- `GET /api/orders?id_guest=34`
- `POST /api/orders`

## Esempi payload

### Registrazione / creazione cliente

```json
{
  "customer": {
    "email": "mario@example.com",
    "password": "Secret123!",
    "firstname": "Mario",
    "lastname": "Rossi",
    "newsletter": true,
    "delivery_address": {
      "alias": "Casa",
      "address1": "Via Roma 1",
      "city": "Milano",
      "postcode": "20100",
      "id_country": 10,
      "phone_mobile": "+39000000000"
    }
  }
}
```

### Login

```json
{
  "email": "mario@example.com",
  "password": "Secret123!"
}
```

### Invio form contatto

```json
{
  "email": "mario@example.com",
  "firstname": "Mario",
  "lastname": "Rossi",
  "subject": "Richiesta informazioni",
  "message": "Vorrei sapere i tempi di spedizione.",
  "id_contact": 1,
  "id_customer": 12,
  "id_order": 99
}
```

`POST /api/contact` crea un thread contatto PrestaShop, salva il messaggio e invia l'email al reparto configurato. `id_contact`, `id_customer` e `id_order` sono opzionali, ma se presenti vengono validati.

### Creazione / aggiornamento carrello

```json
{
  "cart": {
    "id_customer": 12,
    "id_currency": 1,
    "id_lang": 1,
    "id_carrier": 2,
    "replace_products": true,
    "id_address_delivery": 34,
    "id_address_invoice": 34,
    "products": [
      {
        "id_product": 10,
        "id_product_attribute": 0,
        "quantity": 2
      }
    ]
  }
}
```

### Recupero carrello cliente o guest

```text
GET /api/carts?id_customer=12
GET /api/carts?id_guest=34
GET /api/carts?id_customer=12&id_cart=55
```

Se passi `id_cart`, l'API verifica che il carrello appartenga davvero al cliente o al guest indicato.
Senza `id_cart`, viene restituito il carrello aperto piu recente non ancora convertito in ordine.
Nel payload del carrello viene incluso anche `cart_rules` con i coupon gia applicati.

### Coupon / Regole carrello

```text
GET /api/cart_rules
GET /api/cart_rules?code=ESTATE2026&id_cart=55&id_customer=12
```

- `GET /api/cart_rules` restituisce l'elenco coupon esistenti (code, name, date_to, quantity, reduction_*).
- Se passi `code` + `id_cart` (+ `id_customer` oppure `id_guest`) l'API verifica la validita reale del coupon sul carrello.

```json
{
  "id_cart": 55,
  "id_customer": 12,
  "code": "ESTATE2026"
}
```

`POST /api/cart_rules` applica il coupon al carrello e restituisce il carrello aggiornato.

### Wishlist

```text
GET /api/wishlists?id_customer=12
GET /api/wishlists?id_customer=12&id_wishlist=4
```

- `GET /api/wishlists` restituisce tutte le wishlist del cliente.
- Se passi `id_wishlist`, l'API restituisce solo quella lista e verifica che appartenga davvero al cliente.

```json
{
  "id_customer": 12,
  "name": "Preferiti estate",
  "default": true
}
```

`POST /api/wishlists` crea una nuova wishlist. Se invece nel payload passi `id_product`, l'endpoint aggiunge il prodotto alla wishlist indicata con `id_wishlist` oppure alla wishlist di default del cliente.

```json
{
  "id_customer": 12,
  "id_wishlist": 4,
  "id_product": 55,
  "id_product_attribute": 0,
  "quantity": 1,
  "priority": "1"
}
```

`PUT /api/wishlists` aggiorna nome/default della wishlist oppure la quantità di un prodotto già presente.

```json
{
  "id_customer": 12,
  "id_wishlist": 4,
  "id_product": 55,
  "quantity": 3
}
```

`DELETE /api/wishlists` rimuove un prodotto dalla wishlist oppure elimina l'intera wishlist se non passi `id_product`.

```json
{
  "id_customer": 12,
  "id_wishlist": 4,
  "id_product": 55,
  "id_product_attribute": 0
}
```

### Finalizzazione ordine

```json
{
  "id_cart": 55,
  "payment_module": "webserviceapi",
  "payment_label": "Pagamento personalizzato API",
  "id_order_state": 1
}
```

Se `payment_module` non viene passato, l'API usa il valore configurato nel modulo. Ora il modulo stesso puo essere usato come payment module tecnico con nome `webserviceapi`, quindi puoi finalizzare l'ordine senza dipendere da un altro modulo pagamento installato.

## Metodo di pagamento custom

Il modulo si registra anche come metodo di pagamento PrestaShop minimale:

- compare tra le opzioni checkout con etichetta `Pagamento personalizzato API`
- espone un controller di validazione interno che crea l'ordine con `validateOrder`
- l'hook di return del pagamento restituisce volutamente una stringa vuota

Il controller di checkout usato dal metodo di pagamento e il fallback modulo `index.php?fc=module&module=webserviceapi&controller=validation`.

### Recupero riepilogo ordine

```text
GET /api/orders?id_order=99
GET /api/orders?reference=ABCDEF
GET /api/orders?id_order=99&id_customer=12
GET /api/orders?reference=ABCDEF&id_guest=34
```

Il riepilogo include dati ordine, stato, totali, valuta, cliente, indirizzi e righe prodotto.
Se passi `id_customer` o `id_guest`, l'API verifica che l'ordine appartenga davvero al soggetto richiesto.

### Area riservata: profilo, indirizzi, logout, storico ordini

#### Recupero profilo utente

```text
GET /api/account?id_customer=12
```

Risposta: dati anagrafici cliente + elenco indirizzi.

#### Modifica informazioni utente

```json
{
  "id_customer": 12,
  "firstname": "Mario",
  "lastname": "Rossi",
  "email": "mario@example.com",
  "birthday": "1988-04-01",
  "newsletter": true,
  "optin": false,
  "password": "NuovaPassword123!"
}
```

```text
PUT /api/account
```

Puoi includere anche `delivery_address` e/o `invoice_address` nel payload per aggiornarli insieme al profilo.

#### Recupero indirizzi spedizione/fatturazione

```text
GET /api/addresses?id_customer=12
```

#### Modifica indirizzi spedizione/fatturazione

```json
{
  "id_customer": 12,
  "delivery_address": {
    "id_address": 45,
    "address1": "Via Roma 12",
    "city": "Milano",
    "postcode": "20100",
    "id_country": 10
  },
  "invoice_address": {
    "address1": "Via Torino 8",
    "city": "Milano",
    "postcode": "20121",
    "id_country": 10
  }
}
```

```text
PUT /api/addresses
```

Se passi `id_address` aggiorna un indirizzo esistente del cliente, altrimenti ne crea uno nuovo.

#### Logout

```text
POST /api/logout
```

Rimuove il contesto cliente dalla sessione/cookie PrestaShop.

#### Storico ordini area riservata

```text
GET /api/orders?id_customer=12
GET /api/orders?id_guest=34
```

Se non passi `id_order` o `reference`, l'endpoint restituisce lo storico ordini del cliente/guest.

## Chiamate esposte nel webservice (`routes/api.php`)

Le nuove API sono richiamabili anche dal webservice tramite:

- `POST /api/logout`
- `GET /api/customers/{customerId}`
- `PUT /api/customers/{customerId}`
- `GET /api/customers/{customerId}/addresses`
- `PUT /api/customers/{customerId}/addresses`
- `GET /api/order/history/{customerId}`

## Configurazione modulo

Dal pannello del modulo è possibile configurare:

- nome tecnico del modulo pagamento usato da `validateOrder`

Se imposti `webserviceapi`, le API ordine e il checkout PrestaShop usano il modulo stesso come payment module custom.

## Note operative

- Le risposte sono sempre in JSON.
- L'endpoint `GET /api/carts` richiede `id_customer` oppure `id_guest`.
- Gli endpoint wishlist richiedono le tabelle standard `wishlist` e `wishlist_product` del modulo wishlist PrestaShop.
- L'endpoint `GET /api/orders` richiede `id_order` oppure `reference`.
- Per finalizzare un ordine serve un carrello con prodotti, cliente valido e indirizzi di consegna/fatturazione impostati.
- Se un carrello non virtuale non ha un vettore, l'endpoint ordine risponde con errore `422`.
- Se vuoi usare il modulo come metodo di pagamento nel checkout standard, dopo l'aggiornamento conviene reinstallarlo oppure resettarlo dal back office per registrare i nuovi hook.

 Backend Return URLs:

success_url: /checkout/success?session_id={CHECKOUT_SESSION_ID}&cart_id=227
cancel_url: /checkout/cancel?cart_id=227
