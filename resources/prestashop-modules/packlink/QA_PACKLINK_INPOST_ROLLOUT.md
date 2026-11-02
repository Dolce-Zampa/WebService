# QA e rilascio — selettore punto InPost tramite Packlink

## Confini dell'integrazione

Il selettore dedicato deve usare **solo** il modulo Packlink e il suo
`LocationService`. Non sono ammessi API, SDK, widget, chiavi o richieste HTTP
dirette verso InPost. Il solo sistema esterno da cui provengono i punti e a cui
vengono inoltrate le spedizioni è Packlink.

La regressione di riferimento è il flusso nativo:

1. `controllers/front/locations.php` risolve i punti da `methodId`, indirizzo e
   pacchi del carrello tramite `LocationService::getLocations()`;
2. la scelta è associata al carrello e al carrier in
   `CartCarrierDropOffMapping`;
3. alla creazione dell'ordine il modulo trasferisce il punto all'ordine e alla
   spedizione Packlink.

Il selettore/API dedicato può migliorare il contratto di sicurezza, ma deve
mantenere questi comportamenti. In particolare, il browser invia soltanto
`pointId`; il server lo deve ritrovare nel risultato Packlink corrente prima di
salvarlo.

## Precondizioni

- L'ambiente di test usa una configurazione Packlink separata e un metodo
  InPost attivo, configurato come **punto di ritiro**.
- Sono disponibili: un CAP con più punti, un CAP senza punti e due clienti
  distinti con un carrello ciascuno.
- Il flag `PACKLINK_INPOST_SELECTOR_ENABLED` è inizialmente disattivo. Se il
  nome definitivo cambia, aggiornare qui, nei runbook e nel dashboard prima del
  rilascio.
- Annotare ID del carrier PrestaShop, `methodId` Packlink e versione del modulo
  usati nel collaudo; non inserire credenziali nei report.
- Eseguire prima `bash modules/packlink/tests/qa/verify-packlink-only-inpost.sh`.

## Checklist funzionale

| Caso | Azione | Esito atteso |
| --- | --- | --- |
| Servizio attivo | Scegliere il carrier InPost Packlink con un CAP servito. | Il pulsante del selettore è disponibile e la ricerca passa da Packlink. |
| CAP con punti | Aprire il selettore. | Sono mostrati nome, indirizzo, CAP/città e orari dei punti restituiti da Packlink; nessuna richiesta diretta a InPost. |
| CAP senza punti | Aprire il selettore su un CAP non servito. | Stato “nessun punto” comprensibile; checkout bloccato per quel carrier finché non si cambia indirizzo o carrier. |
| Scelta e refresh | Selezionare un punto, aggiornare il checkout e rientrare nel carrello. | Rimane solo il punto associato a quel carrello e carrier; il riepilogo mostra lo stesso ID/indirizzo. |
| Cambio indirizzo/CAP | Dopo una scelta, modificare indirizzo o CAP. | La scelta precedente è invalidata; il checkout richiede un nuovo punto compatibile. |
| Cambio carrier | Dopo una scelta, passare a carrier non InPost e poi tornare a InPost. | Il carrier non InPost non richiede un punto; tornando a InPost non si riusa un punto incompatibile. |
| Carrello variato | Cambiare quantità/prodotti in modo da modificare i pacchi. | La selezione viene ricalcolata o invalidata prima dell'ordine. |
| Punto non più disponibile | Selezionare un punto, simulare/ricevere una risposta Packlink che non lo contiene più, quindi confermare. | Il server rifiuta la selezione, non persiste dati obsoleti e chiede una nuova scelta. |
| Errore Packlink | Simulare timeout/5xx/risposta non valida dal proxy Packlink. | Messaggio non tecnico, nessun punto inventato/cache scaduta, nessun ordine InPost senza punto valido; errore osservabile. |
| Regressione nativa | Disattivare il flag e ripetere scelta/ordine nel flusso Packlink esistente. | Il checkout nativo Packlink continua a funzionare. |

### Contratto e sicurezza

Eseguire queste prove da browser autenticato e anonimo dove applicabile, usando
strumenti di rete senza registrare cookie o dati personali nei ticket.

- L'endpoint dedicato deriva `cartId`, cliente, indirizzo e carrier dalla
  sessione; ignorare o rifiutare valori inviati dal client per tali campi.
- L'azione di salvataggio accetta unicamente `pointId` e un token CSRF/check-out
  valido. Un payload con indirizzo, coordinate, `cartId`, `carrierId` o
  `orderId` alterati deve fallire senza scritture.
- Tentare di leggere/salvare il punto del carrello di un secondo cliente e di
  un carrello guest diverso: risposta 403/404 secondo la convenzione del modulo
  e nessuna modifica del mapping.
- Tentare un `pointId` inesistente, di un altro CAP/carrier o rimosso: risposta
  di validazione, nessun mapping aggiornato.
- Verificare in DevTools che richieste e risposte non espongano credenziali e
  che non esistano connessioni a domini InPost; le richieste punti devono
  raggiungere l'endpoint del negozio/Packlink.

## Verifica ordine e spedizione

Per un ordine pagato con il carrier InPost Packlink:

1. controllare che esista un solo mapping per la coppia `cartId` + carrier e
   che lo `pointId` salvato corrisponda al punto validato;
2. creare l'ordine e controllare che il punto scelto sia presente nei dati di
   consegna/indirizzo del punto secondo il comportamento Packlink nativo;
3. controllare in Packlink che l'ID punto sia trasferito alla spedizione e che
   l'etichetta sia creabile;
4. verificare che ordine e back office non mostrino il punto di un vecchio
   indirizzo, carrello o carrier;
5. ripetere con pagamento rifiutato/ordine non creato: non deve comparire una
   spedizione definitiva né una scelta attribuita a un altro ordine.

## Rollout controllato

1. **Staging:** flag spento, eseguire la regressione nativa; attivarlo per gli
   utenti di test e completare tutte le righe della checklist.
2. **Go/no-go:** responsabili QA e logistica confermano servizio InPost
   Packlink attivo, evidenza del test ordine/etichetta, nessuna chiamata diretta
   a InPost e assenza di errori bloccanti.
3. **Produzione:** attivare il flag inizialmente per una finestra controllata;
   verificare un ordine reale o di prova autorizzato e monitorare per almeno un
   ciclo di checkout.
4. **Metriche:** registrare apertura selettore, lista vuota, errore Packlink,
   selezione validata/rifiutata, checkout bloccato e ordine creato. Segmentare
   per carrier/metodo Packlink, mai per dati personali o indirizzi completi.
5. **Rollback:** spegnere `PACKLINK_INPOST_SELECTOR_ENABLED`, svuotare cache
   applicativa/template se necessaria e rieseguire il test di regressione
   nativa. Non eliminare mapping o ordini esistenti durante il rollback.

## Evidenze da allegare a DZ-92

- risultato del controllo automatico Packlink-only;
- ID ambiente, versione modulo, carrier e `methodId` (senza segreti);
- esito di ogni riga della checklist, con link a log sanitizzati;
- prova dell'ordine e della spedizione Packlink;
- timestamp di attivazione/disattivazione del flag e decisione go/no-go.
