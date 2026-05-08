# MlabFactory API module

Modulo PrestaShop realizzato da **MlabFactory / Marco De Felice** per esporre API JSON dedicate a:

- registrazione cliente
- creazione cliente
- login cliente
- creazione/aggiornamento carrello
- finalizzazione ordine

## Autenticazione

Ogni endpoint richiede una chiave Webservice PrestaShop attiva, inviata in uno dei seguenti modi:

- `Authorization: Bearer <webservice_key>`
- header `X-WS-Key: <webservice_key>`
- query string `?ws_key=<webservice_key>`

## Endpoint

Sono registrate sia le route parlanti sia il fallback nativo modulo `index.php?fc=module&module=mlabfactoryapi&controller=...`.

- `POST /api/register`
- `POST /api/login`
- `POST /api/customers`
- `GET /api/carts?id_customer=12`
- `GET /api/carts?id_guest=34`
- `POST /api/carts`
- `GET /api/cart_rules`
- `GET /api/cart_rules?code=ESTATE2026&id_cart=55&id_customer=12`
- `POST /api/cart_rules`
- `GET /api/orders?id_order=99`
- `GET /api/orders?reference=ABCDEF`
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

### Finalizzazione ordine

```json
{
  "id_cart": 55,
  "payment_module": "ps_wirepayment",
  "payment_label": "Bonifico bancario",
  "id_order_state": 1
}
```

### Recupero riepilogo ordine

```text
GET /api/orders?id_order=99
GET /api/orders?reference=ABCDEF
GET /api/orders?id_order=99&id_customer=12
GET /api/orders?reference=ABCDEF&id_guest=34
```

Il riepilogo include dati ordine, stato, totali, valuta, cliente, indirizzi e righe prodotto.
Se passi `id_customer` o `id_guest`, l'API verifica che l'ordine appartenga davvero al soggetto richiesto.

## Configurazione modulo

Dal pannello del modulo è possibile configurare:

- nome tecnico del modulo pagamento usato da `validateOrder`
- stato ordine di default applicato alla creazione

## Note operative

- Le risposte sono sempre in JSON.
- L'endpoint `GET /api/carts` richiede `id_customer` oppure `id_guest`.
- L'endpoint `GET /api/orders` richiede `id_order` oppure `reference`.
- Per finalizzare un ordine serve un carrello con prodotti, cliente valido e indirizzi di consegna/fatturazione impostati.
- Se un carrello non virtuale non ha un vettore, l'endpoint ordine risponde con errore `422`.

 Backend Return URLs:

success_url: /checkout/success?session_id={CHECKOUT_SESSION_ID}&cart_id=227
cancel_url: /checkout/cancel?cart_id=227
