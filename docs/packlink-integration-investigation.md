# DZ-147 — Analisi sostituzione del modulo Packlink

La destinazione concordata è l'API Packlink diretta. Questa analisi descrive
il comportamento esaminato prima della sostituzione; per il rilascio vedere
[packlink-shipping.md](packlink-shipping.md).

## Comportamento attuale

Nel modulo incluso nel repository, gli hook di validazione e cambio stato
accodano una bozza quando non esiste già un'associazione ordine–spedizione, il
corriere ha una mappatura Packlink e lo stato è pagamento accettato o
preparazione in corso (`packlink.php`, `createOrderDraft` e
`shouldCreateDraft`). Il controller amministrativo può richiedere manualmente
una bozza. I dati includono l'indirizzo, i prodotti fisici, le dimensioni e il
peso dei prodotti o del pacco predefinito. Il punto di ritiro proviene da una
mappatura del modulo Packlink associata al carrello e al corriere.

Il modulo salva il primo codice di tracking con `setWsShippingNumber`; applica
una mappatura configurata degli stati. L'handler webhook del modulo inoltra
l'evento alla libreria Packlink e l'aggiornamento recupera la spedizione dal
provider. Il modulo usa riferimenti ed etichette Packlink per le funzioni di
back office. L'endpoint ordini di `webserviceapi` attualmente accetta GET e
POST e non espone un'operazione per aggiornare tracking e cronologia delle
spedizioni. Il worker scheduler del webservice è registrato in
`AppScheduleProvider`.

## Contratto verificato nel codice pubblico Packlink

Il [proxy ufficiale](https://github.com/packlink-dev/ecommerce_module_core/blob/master/src/BusinessLogic/Http/Proxy.php)
invia `POST /v1/shipments` per creare **una bozza**, non garantisce che sia
acquistata o affidata al corriere, e legge il campo `reference` della risposta.
Il [supporto Packlink](https://support-pro.packlink.com/hc/it/articles/214125845-Come-posso-completare-una-spedizione)
descrive completamento e pagamento tramite Packlink PRO prima della conferma.
Il passaggio manuale è stato confermato per questa issue.
Lo stesso proxy espone `GET /v1/shipments/{reference}`, `/labels` e `/track`.
Il [DTO della bozza](https://github.com/packlink-dev/ecommerce_module_core/blob/master/src/BusinessLogic/Http/DTO/Draft.php)
contiene, tra gli altri, indirizzi, pacchi, servizio, eventuale punto di
ritiro e riferimento personalizzato. Il
[DTO della spedizione](https://github.com/packlink-dev/ecommerce_module_core/blob/master/src/BusinessLogic/Http/DTO/Shipment.php)
legge stato, tracking e URL. Queste osservazioni derivano dal codice pubblico,
non da una verifica con l'account di produzione o una sandbox.

Il [gestore webhook ufficiale](https://github.com/packlink-dev/ecommerce_module_core/blob/master/src/BusinessLogic/WebHook/WebHookEventHandler.php)
riconosce eventi su corriere, etichetta, tracking e consegna e rilegge il
riferimento dal provider. Il codice pubblico più recente di registrazione
integrazione prevede un nome e valore di header per il webhook: disponibilità,
attivazione e compatibilità con l'account devono essere verificate prima di
abilitarlo. Il callback legacy registra soltanto un URL: non assumere che sia
autenticato. La riconciliazione tramite polling resta necessaria.

Il codice esaminato non documenta una chiave di idempotenza per la creazione.
Un timeout, un errore remoto o una risposta senza riferimento possono quindi
essere ambigui. Il nuovo worker invia la creazione
una sola volta per ordine e lasciano in attesa di verifica manuale sia gli
esiti ambigui sia un processo interrotto dopo la presa in carico. Non ripetere
un POST finché non si è verificato l'esito nel pannello Packlink.

## Perimetro concordato

Il nuovo flusso riguarda esclusivamente gli ordini creati dal cutover in poi;
non è previsto l'import delle associazioni storiche. L'operatore completa e
paga manualmente la bozza in Packlink PRO. Per configurazione, passaggio e
recupero vedere [packlink-shipping.md](packlink-shipping.md).
