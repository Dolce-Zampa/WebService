# Spedizioni per artigiano

Il modulo `webserviceapi` calcola la spedizione in PrestaShop sui prodotti fisici di ciascun artigiano, usando la tariffa del corriere scelto. Un artigiano raggiunge la spedizione gratuita quando il totale dei suoi prodotti raggiunge la soglia.

Impostare `MARKETPLACE_FREE_SHIPPING_THRESHOLD=99` nell'ambiente PHP di PrestaShop (non solo nell'ambiente del web service). Il valore predefinito è 99; impostare 0 disabilita la soglia. Dopo averlo modificato, riavviare i processi PHP se necessario. La soglia è esposta al frontend da `GET /api/shipping-policy`.

La soluzione usa l'hook di calcolo verificato nel codice PrestaShop 9.2 e richiede PrestaShop 9.2 o successivo. Aggiornare il modulo `webserviceapi` alla versione 1.1.2 prima di distribuire frontend e web service, per registrare i nuovi hook.

`GET /api/carriers` espone la tariffa italiana della prima fascia in `price_with_tax`, assieme a `price_ranges` e `price_varies_by_range`. Il valore include le eventuali spese di gestione e imposte configurate per l'Italia. Per tariffe esterne o prive di configurazione valida restituisce `null`; non viene inventato un prezzo. Prima del pagamento il web service verifica il prezzo effettivo di PrestaShop rispetto a quello mostrato e rifiuta l'ordine se sono diversi.

Finché un corriere mantiene fasce successive con prezzi diversi, un ordine che ricade in una di quelle fasce può essere rifiutato dal controllo finale. Per applicare sempre la prima tariffa, mantenere in PrestaShop una configurazione coerente con tale prezzo e con i pesi degli ordini previsti.
