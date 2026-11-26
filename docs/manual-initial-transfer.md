# Trasferimento manuale di un pagamento iniziale

Bozza tecnica: richiede revisione e verifica in ambiente di prova prima del rilascio. Il comando non crea né modifica l'ordine, non registra il pagamento in PrestaShop e non calcola la commissione. Supporta un solo venditore e un pagamento EUR integralmente acquisito con carta o PayPal.

1. Creare l'ordine manualmente, registrare l'incasso e correlare il PaymentIntent. Verificare nella dashboard Stripe che il pagamento sia sulla piattaforma e non sia già stato trasferito, anche manualmente fuori dal WebService. Assicurarsi che non sia gestito da un checkout automatico; non eseguire contemporaneamente rimborsi o altre allocazioni.
2. Identificare ordine, produttore e quota netta esatta; controllare il conto Connect in anteprima.
3. Eseguire l'anteprima e poi, soltanto dopo aver controllato tutti i dati, ripetere con `--execute`:

```sh
php bin/console app:stripe-connect:manual-initial --order-id=1234 --seller-id=7 --payment-intent=pi_SOSTITUIRE --payment-cents=10000 --seller-cents=8000
```

Gli importi sono centesimi. Il trasferimento usa la charge come source_transaction e viene salvato nel ledger. Se l'esito è ambiguo o compare manual_review, riconciliare Stripe e database prima di intervenire: non inviare un secondo trasferimento. I controlli locali non possono rilevare tutti i trasferimenti creati esternamente e non garantiscono la sicurezza in caso di operazioni concorrenti. Verificare separatamente rimborsi, inversioni e riconciliazione prima dell'uso reale.
