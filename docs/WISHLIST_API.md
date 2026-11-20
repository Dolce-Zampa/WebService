# API Wish List personale

Applicare la migration `20261120130000_create_personal_wishlists.php` prima del rilascio. Il servizio usa il database PrestaShop e `PS_TABLE_PREFIX`. Alla prima visita, se esiste, importa una sola volta la wishlist PrestaShop predefinita (o la prima per ID); le altre liste non vengono importate. Da quel momento le nuove tabelle sono la fonte di verità e le successive modifiche al vecchio modulo non vengono sincronizzate.

Le rotte di gestione richiedono `Authorization: Bearer <access token>` Cognito. Il cliente è ricavato dal `sub` verificato, mai dal corpo o dalla query. Le risposte hanno il consueto involucro `{ "success": true, "data": ... }` e `Cache-Control: no-store`.

- `GET /api/wishlists`: crea se necessario e restituisce `data.wishlist` con `name`, `share_token` e `products`.
- `POST /api/wishlists`: aggiunge `{ "id_product": 123, "id_product_attribute": 0 }`. Ripetere la richiesta è idempotente; l'attributo opzionale identifica una variante.
- `DELETE /api/wishlists`: rimuove il prodotto e la variante indicati nello stesso corpo. È possibile rimuovere anche prodotti eliminati dal catalogo.
- `PUT /api/wishlists`: cambia il nome con `{ "name": "Compleanno" }` (1–128 caratteri).
- `GET /api/wishlists/shared/{share_token}`: pubblico, sola lettura; restituisce nome e prodotti, senza identificativo cliente o token. Il token contiene 256 bit casuali, è stabile e non scade. La pagina frontend comporrà l'URL pubblico approvato a partire dal token; non includerlo in analytics o log.

Ogni prodotto contiene `id_product`, `id_product_attribute`, `date_add`, `available` e `stock_quantity`. `available` considera lo stato nel negozio predefinito, la variante, la quantità e le impostazioni PrestaShop per ordini fuori stock; in assenza di dati di stock è `false`. I dettagli di visualizzazione (nome, immagine, prezzo e URL) sono da recuperare dalle API catalogo esistenti. Un prodotto non più presente o acquistabile rimane nella lista con `available: false`. L'aggiunta rifiuta prodotti inesistenti, disattivati o non ordinabili e varianti non appartenenti al prodotto; i prodotti temporaneamente esauriti possono essere salvati.

Il link è una credenziale di lettura: chi lo possiede può vedere nome e prodotti. La pagina pubblica deve inviare `Referrer-Policy: no-referrer`, evitare indicizzazione e risorse terze che possano ricevere il percorso e non memorizzare la risposta in cache. La revoca o rigenerazione del link non è inclusa nei requisiti approvati.
