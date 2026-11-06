# DZ-147 — Analisi del modulo Packlink PrestaShop

Questa analisi riguarda i file del modulo presenti nel repository e la versione
**v3.7.0** di `packlink/integration-core`, fissata in `composer.lock` al commit
`fc2a9012e503fa4a1402eb377e7984c71c973d23`. Il modulo dichiara versione 3.4.0.
Il codice della libreria non è incluso nel repository: i riferimenti sottostanti
puntano alla versione bloccata, non al suo ramo più recente. L'analisi del
codice non dimostra che le interfacce siano pubbliche, documentate, supportate
o attualmente accessibili dall'account Dolce Zampa.

## Creazione di una bozza

1. Il modulo ascolta la validazione dell'ordine e il cambio di stato. Se non
   esiste già un'associazione alla spedizione, il corriere è mappato e lo stato
   è pagamento accettato (ID 2) o preparazione (ID 3), accoda una creazione.
   Gli ID sono quelli del modulo, non valori da assumere per ogni negozio.
2. Il task recupera indirizzo del cliente, articoli fisici, categoria predefinita,
   quantità, peso e dimensioni; usa valori predefiniti se mancano misure.
   Per il punto di ritiro legge una selezione salvata al checkout dal modulo.
3. Il mittente viene dal magazzino predefinito Packlink. Per Dolce Zampa è
   stato richiesto di usare invece il profilo dell'artigiano.
4. La libreria cerca i servizi disponibili in base a origine, destinazione e
   pacco; all'interno del metodo di spedizione configurato sceglie un servizio
   idoneo, normalmente il più economico. Se la ricerca non restituisce servizi,
   tenta un fallback basato sui servizi salvati nel metodo.
5. Invia la bozza con `POST /v1/shipments`, legge `reference` dalla risposta,
   lo salva e rilegge la spedizione. Il task non paga la spedizione.

Fonti: [hook e condizioni](../resources/prestashop-modules/packlink/packlink.php),
[dati ordine](../resources/prestashop-modules/packlink/classes/BusinessLogicServices/ShopOrderService.php),
[task di creazione](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/Tasks/SendDraftTask.php),
[preparazione bozza](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/Order/OrderService.php)
e [scelta servizio](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/ShippingMethod/ShippingCostCalculator.php).

## Interfacce HTTP osservate

La libreria usa `https://api.packlink.com/v1/` e invia la chiave nell'header
`Authorization`, insieme a `Accept`, `Content-Type`, `X-Module-Version`,
`X-Ecommerce-Name` e `X-Ecommerce-Version`. I valori del modulo locale sono
`module_prestashop` come `source` delle bozze e `prestashop_2` come nome
nell'header ecommerce. La configurazione Packlink della libreria usa `PRO` per
la ricerca dei servizi; questo non dimostra quale valore sia richiesto in ogni
campo della bozza per l'account attuale.

| Operazione | Chiamata osservata |
| --- | --- |
| Dati account | `GET /v1/clients` |
| Ricerca servizi su tratta e pacco | `GET /v1/services` con `from`, `to`, `packages` e `source` |
| Dettaglio servizio già noto | `GET /v1/services/available/{id}/details` |
| Punti di ritiro | `GET /v1/dropoffs/{serviceId}/{country}/{postalCode}` |
| Creazione bozza | `POST /v1/shipments` |
| Stato e tracking | `GET /v1/shipments/{reference}` e `/track` |
| Etichette | `GET /v1/shipments/{reference}/labels` |
| Registrazione callback storico | `POST /v1/shipments/callback`, con URL nel corpo |

Fonte: [proxy della versione bloccata](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/Http/Proxy.php).
La ricerca richiede CAP effettivi e parametri URL correttamente codificati. I
CAP generici `00100` e `20100` usati in un precedente esempio non identificano
una zona di consegna e potrebbero spiegare il 400; non è stato verificato con
l'account. Il modulo tratta alcuni errori 400 relativi alla località ritentando
con il CAP originale se aveva applicato una trasformazione.

## Stati, webhook e etichette

La [mappatura della versione bloccata](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/ShippingMethod/Utility/ShipmentStatus.php)
riconosce, tra gli altri, `AWAITING_COMPLETION`, `READY_TO_PURCHASE`,
`PURCHASE_SUCCESS`, `READY_TO_PRINT`, `READY_FOR_COLLECTION`, `IN_TRANSIT`,
`OUT_FOR_DELIVERY`, `DELIVERED`, `CANCELED` e stati di errore. Questi sono
stati osservati nel codice, non una garanzia di quanto ritornerà l'account.
Il modulo li raggruppa e mappa ai propri stati PrestaShop; salva il primo
tracking disponibile nell'ordine.

Il webhook storico riconosce `shipment.label.ready`, eventi corriere, tracking
e consegna, poi rilegge la spedizione dal provider. Il controllo di autenticazione
nel codice storico verifica solo che sia presente una chiave configurata nel
negozio: **non verifica una firma o un segreto nella richiesta in ingresso**.
La registrazione storica passa solo l'URL. Non utilizzare quel comportamento
come prova di autenticità. Il codice più recente contiene una registrazione
con header segreto, ma la sua disponibilità sul nostro account non è verificata.

Fonte: [gestore webhook della versione bloccata](https://github.com/packlink-dev/ecommerce_module_core/blob/fc2a9012e503fa4a1402eb377e7984c71c973d23/src/BusinessLogic/WebHook/WebHookEventHandler.php).

## Differenze e verifiche necessarie per la PR

- La PR usa un `service_id` fisso per ogni corriere PrestaShop; il modulo può
  scegliere dinamicamente tra più servizi in base a tratta e pacco. La parità
  non è quindi dimostrata e l'ID fisso potrebbe non essere valido per tutte le
  spedizioni.
- La PR richiede `user_id`, `client_id` e `platform`; la costruzione della
  bozza nella versione esaminata non assegna esplicitamente questi tre campi.
  Non è dimostrato che siano obbligatori o come vadano ottenuti per l'account.
- La PR invia meno campi della bozza del modulo, che include fra gli altri
  nome servizio, corriere, dettagli articoli e magazzino. Il modulo usa
  `module_prestashop` come source e invia header aggiuntivi. La compatibilità
  del payload e la necessità degli header sono da verificare, non presumere.
- Il modulo include i titoli dei prodotti nel contenuto e conserva le categorie
  nei dettagli articolo; su richiesta Dolce Zampa la PR usa le categorie come
  contenuto e i profili artigiano come mittenti. Il modulo applica anche una
  pulizia al testo del contenuto: verificare i caratteri ammessi dall'API.
- Gli esempi `PENDING` e `READY` precedentemente forniti erano segnaposto, non
  gli stati grezzi del modulo. Configurare solo stati effettivamente verificati.
- La PR richiede header segreto per il webhook: verificare che l'account possa
  registrarlo e che venga inviato. Il polling è il recupero previsto.
- I punti di ritiro dipendono da una selezione checkout indipendente, non ancora
  implementata nella PR. Le spedizioni pickup rimangono in attesa.
- I retry di creazione non devono duplicare bozze in caso di risposta persa;
  il codice esaminato non documenta una chiave di idempotenza.

Non attivare l'integrazione sulla base dei soli esempi. Occorre una prova
controllata con chiave valida e non esposta, un account di test e un ordine di
prova; fino ad allora la compatibilità con le interfacce Packlink resta incerta.
