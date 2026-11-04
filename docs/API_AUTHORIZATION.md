# Autorizzazione delle API private

Le API customer, cart (inclusa la validazione coupon), order e seller richiedono un token Bearer valido. Il customer viene risolto dal `sub` autenticato nel repository; gli ID customer nel body o nella query non stabiliscono l’identità. Anche nei payload annidati `customer.id_customer` viene sostituito prima di inoltrare aggiornamenti a PrestaShop.

Gli endpoint con customerId nel percorso restituiscono 403 se l’identità non coincide. Lettura, modifica, eliminazione e coupon sui carrelli verificano la proprietà prima di eseguire operazioni. Il dettaglio ordine usa id_order e id_customer nella stessa query. Le richieste senza autenticazione ricevono 401. Per risorse estranee si usa 404; il polling conferma ordine restituisce 202 senza dati se non trova un ordine nel contesto del proprietario.

Il gruppo seller verifica anche che il sub abbia un profilo Manufacturer server-side. Prodotti e promozioni vengono limitati al manufacturer autenticato. Le risposte private seller non usano la cache HTTP, così ogni richiesta ripete l’autorizzazione sulla risorsa. Le operazioni prodotto non implementate restano 501, protette dal middleware del gruppo.

Registrazione/login seller e health check restano pubblici. Le route cart/order non accettano più un semplice id_guest come credenziale: il frontend deve autenticarsi. Un futuro checkout anonimo richiederà una credenziale guest verificabile server-side.

Verifica: `php vendor/bin/phpunit Test`. I test coprono identità contraffatte nel body/query, carrelli estranei, ordini di altri customer, payload customer annidati, accesso seller da customer e aggiornamento di prodotti di altri seller.
