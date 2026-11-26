# Trasferimento manuale di un pagamento iniziale

Bozza tecnica: richiede revisione e verifica in ambiente di prova prima del rilascio. Il comando non crea né modifica l'ordine e non registra il pagamento in PrestaShop. Supporta un solo venditore e un pagamento EUR integralmente acquisito con carta o PayPal.

Il calcolo automatico usa la commissione attuale di `manufacturer_details` per l'artigiano indicato e la stessa commissione minima, i valori predefiniti e l'arrotondamento del flusso Stripe Connect ordinario. La base è il totale pagato meno la spedizione registrata nell'ordine; il totale ordine deve coincidere con l'importo pagato. È adatto a un ordine di un solo artigiano con soli prodotti e spedizione: altri servizi o voci aggiuntive richiedono una quota manuale. Le impostazioni di commissione sono quelle correnti, non quelle congelate al momento del pagamento; verificare gli accordi in anteprima.

1. Creare l'ordine manualmente, registrare l'incasso e correlare il PaymentIntent. Verificare nella dashboard Stripe che il pagamento sia sulla piattaforma e non sia già stato trasferito, anche manualmente fuori dal WebService. Assicurarsi che non sia gestito da un checkout automatico; non eseguire contemporaneamente rimborsi o altre allocazioni.
2. Identificare ordine e produttore; controllare nell'anteprima conto, importi, spedizione e commissione.
3. Eseguire l'anteprima, ad esempio:

```sh
php bin/console app:stripe-connect:manual-initial --order-id=1234 --seller-id=7 --payment-intent=pi_SOSTITUIRE --payment-cents=10000
```

4. Per eseguire il calcolo automatico ripetere aggiungendo `--execute --confirm-seller-cents=IMPORTO_MOSTRATO`. Se la quota calcolata cambia dopo l'anteprima, il comando si ferma e richiede una nuova conferma. Gli importi sono centesimi.

Per stabilire una quota diversa, inserire `--seller-cent=8000` (o `--seller-cents=8000`) sia nell'anteprima sia nell'esecuzione. In questo caso aggiungere `--execute` per eseguire e non usare `--confirm-seller-cents`. La quota manuale non applica alcun calcolo di commissione.

Il trasferimento usa la charge come source_transaction e viene salvato nel ledger. Se l'esito è ambiguo o compare manual_review, riconciliare Stripe e database prima di intervenire: non inviare un secondo trasferimento. I controlli locali non possono rilevare tutti i trasferimenti creati esternamente e non garantiscono la sicurezza in caso di operazioni concorrenti. Verificare separatamente rimborsi, inversioni e riconciliazione prima dell'uso reale.
