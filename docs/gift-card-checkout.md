# Checkout con gift card

Il cliente può usare nello stesso ordine un coupon e una gift card breve `GC-XXXX-XXXX`. All’applicazione del codice il backend verifica il credito disponibile senza prenotarlo e il frontend mostra il totale indicativo; il valore definitivo può cambiare prima della conferma. Il backend calcola il totale dopo lo sconto, prenota il credito e, se il saldo copre tutto, completa l'ordine senza creare una sessione di pagamento Stripe. Con un residuo positivo crea una sessione Stripe per il solo residuo. I codici lunghi precedenti non sono accettati.

Il saldo effettivo viene addebitato solo dopo la verifica del pagamento. Durante la sessione Stripe il credito è temporaneamente prenotato, per evitare che venga speso due volte. Se il cliente annulla tornando al sito, il backend fa prima scadere la specifica sessione Stripe non pagata e poi rilascia la prenotazione; il carrello viene conservato. Se il cliente abbandona senza tornare, il webhook di scadenza libera la prenotazione. L’annullamento non rilascia credito se Stripe segnala un pagamento completato, e si può riprovare in caso di errore temporaneo.

Per i pagamenti parziali si applica tutto il credito disponibile. Se resta un importo positivo inferiore a 0,50 €, Stripe non può elaborare il pagamento con carta: il checkout richiede di modificare il carrello o rimuovere la gift card prima di procedere.

Se totale o credito disponibile cambiano rispetto al riepilogo, il backend non conferma l’ordine: il cliente vede gli importi aggiornati e può confermare nuovamente. Una prenotazione appena creata viene rilasciata; una prenotazione già associata a una sessione non viene rilasciata per questa variazione.

L'ordine viene confermato dopo la cattura della prenotazione. In caso di errore temporaneo dopo la verifica del pagamento, rimane in attesa e lo scheduler riprova la contabilizzazione; è necessario mantenere attivo lo scheduler e non disabilitare la funzionalità finché vi sono operazioni in sospeso. I trasferimenti ai venditori sono successivi e possono utilizzare anche il saldo della piattaforma Stripe. Un saldo insufficiente lascia il trasferimento da riprovare: la gestione operativa del finanziamento è separata.

## Preparazione per prove locali

1. Usare un'istanza PrestaShop e chiavi Stripe di test, non dati o credenziali di produzione. Aggiornare entrambi i moduli `webserviceapi` e `mlabgiftcard` con le rispettive versioni di questa modifica. Aggiornare `mlabgiftcard` alla versione 1.0.2 dal back office, affinché venga eseguito l'upgrade del database. Verificare che il backend e l'API gift card puntino alla stessa istanza PrestaShop.
2. Applicare le migrazioni del backend con `vendor/bin/phinx migrate`, dopo aver verificato che la configurazione del database indichi l'ambiente locale.
3. Configurare `GIFT_CARD_API_URL` e `GIFT_CARD_API_KEY` per l'istanza locale, con i permessi necessari alle operazioni della gift card, inclusi `read` per la verifica del credito e `spend` per la prenotazione. Impostare `GIFT_CARD_CHECKOUT_ENABLED=true` e `APP_DISABLE_CACHE=false`. In produzione è richiesto Redis condiviso per blocchi e limiti; verificare anche la configurazione Stripe Connect e gli endpoint webhook.
4. Avviare o riavviare il backend e lo scheduler `php bin/workers/scheduler`. Lo scheduler esegue anche il recupero dei checkout e la manutenzione delle gift card; la chiave di manutenzione va configurata separatamente come previsto dal backend.
5. Nel frontend impostare `NEXT_PUBLIC_GIFT_CARD_CHECKOUT_ENABLED=true` e riavviare il server di sviluppo. Per le build, la variabile deve essere disponibile durante la compilazione.

Provare almeno un ordine interamente coperto, uno parzialmente coperto, l'applicazione contemporanea di coupon e gift card, e un carrello misto con prodotto fisico e virtuale. Verificare ordine, movimenti della gift card e pagamenti; per il percorso parziale è necessaria la consegna al backend del webhook Stripe firmato. Verificare anche il recupero di un'operazione temporaneamente interrotta.

Il rimborso automatico della quota pagata con gift card e lo storno dei relativi trasferimenti al venditore non sono inclusi in questo flusso di acquisto: eventuali rimborsi di quella quota richiedono gestione e riconciliazione tramite il modulo gift card.
