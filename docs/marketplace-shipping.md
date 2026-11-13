# Spedizioni per artigiano

Il modulo `webserviceapi` calcola la spedizione in PrestaShop sui prodotti fisici di ciascun artigiano, usando la tariffa del corriere scelto. Un artigiano raggiunge la spedizione gratuita quando il totale dei suoi prodotti raggiunge la soglia.

Impostare `MARKETPLACE_FREE_SHIPPING_THRESHOLD=99` nell'ambiente PHP di PrestaShop (non solo nell'ambiente del web service). Il valore predefinito è 99; impostare 0 disabilita la soglia. Dopo averlo modificato, riavviare i processi PHP se necessario. La soglia è esposta al frontend da `GET /api/shipping-policy`.

La soluzione usa l'hook di calcolo verificato nel codice PrestaShop 9.2 e richiede PrestaShop 9.2 o successivo. Aggiornare il modulo `webserviceapi` alla versione 1.1.2 prima di distribuire frontend e web service, per registrare i nuovi hook. Il preventivo usa un indirizzo e un carrello temporanei all'interno di una transazione che viene annullata. Prima del pagamento il web service ricalcola il prezzo e rifiuta un preventivo che non corrisponde più.
