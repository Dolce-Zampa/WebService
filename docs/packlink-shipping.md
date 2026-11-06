# Spedizioni Packlink dirette

Il worker crea una **bozza** Packlink solo per ordini creati dalla data di
attivazione inclusa. Il completamento e il pagamento restano manuali in
Packlink PRO; tracking ed etichette arrivano successivamente. L'ordine viene
finalizzato indipendentemente dal worker e dall'API Packlink. La nuova
integrazione è disabilitata per impostazione predefinita.

## Configurazione

Impostare `PACKLINK_API_URL`, `PACKLINK_API_KEY`, `PS_BASE_URL` e
`WEBSERVICE_KEY` nell'ambiente del webservice. Il database del webservice deve essere lo stesso
di PrestaShop, con `PS_TABLE_PREFIX` corrispondente a `_DB_PREFIX_`, perché la
tabella spedizioni è condivisa con il modulo. Impostare inoltre lo stesso
`PACKLINK_SHOP_SECRET`, casuale e di almeno 32 byte, nell'ambiente PHP sia del
webservice sia di PrestaShop; la nuova API richiede entrambe le credenziali. Non inserire segreti nei log o
nel repository. `PACKLINK_SHIPPING_CRON` regola la frequenza del worker; il
polling di ogni spedizione è regolato da `sync_interval_seconds` (almeno 60).

`PACKLINK_SHIPPING_SETTINGS` è un oggetto JSON su una sola riga. Il seguente
è un esempio strutturale: date, ID, servizio, indirizzo, stati e dimensioni
vanno sostituiti con valori verificati per il negozio e l'account; non sono
valori predefiniti di produzione.

```json
{
  "since": "2030-01-01 00:00:00",
  "eligible_states": [101, 102],
  "carriers": {"123": {"service_id": "SERVICE_ID", "pickup": false}},
  "draft": {
    "user_id": "USER_ID", "client_id": "CLIENT_ID", "platform": "PL",
    "platform_country": "IT", "source": "prestashop",
    "integration_id": "INTEGRATION_ID",
    "from": {"name": "Nome", "surname": "Cognome", "company": "Azienda",
      "street1": "Indirizzo", "street2": "", "zip_code": "00000", "city": "Citta",
      "country": "IT", "phone": "TELEFONO", "email": "mittente@example.test"}
  },
  "package": {"weight": 1, "width": 10, "height": 10, "length": 10},
  "content": "Prodotti", "sync_interval_seconds": 900,
  "states": {
    "PENDING": {"order_state_id": 101, "rank": 0, "labels": false},
    "READY": {"order_state_id": 103, "rank": 1, "labels": true},
    "DELIVERED": {"order_state_id": 104, "rank": 2, "labels": true}
  }
}
```

Le chiavi `carriers` sono gli ID **riferimento corriere** PrestaShop; i valori
`service_id` sono ID di servizi Packlink, non ID del metodo del modulo.
Per i servizi a punto di ritiro impostare `pickup: true`: il worker legge la
selezione `CartCarrierDropOffMapping` mantenuta dal checkout del modulo Packlink.
Una selezione mancante o ambigua viene rinviata, non sostituita. Se quel
checkout non è disponibile su PrestaShop 9.2, occorre un flusso che conservi la
selezione compatibile prima di abilitare quei corrieri.

Le dimensioni predefinite sono in centimetri e il peso in chilogrammi. Il
worker usa peso e dimensioni del prodotto, con fallback ai valori configurati:
come nel modulo Packlink, somma i pesi e usa le dimensioni predefinite per più
pezzi. Le unità PrestaShop riconosciute sono `kg`, `g`, `oz`, `lbs` e `cm`;
altre unità bloccano la preparazione per verifica. Confermare il pacco nel
pannello Packlink prima del pagamento.

Mappare ogni stato effettivamente restituito dall'account. `order_state_id` è
l'ID PrestaShop configurato nel negozio; `rank` stabilisce l'avanzamento e
impedisce di applicare un ritorno a uno stato precedente. Stati con lo stesso
rank devono mappare allo stesso stato ordine. `labels: true` abilita il
recupero delle etichette per quello stato. Stati sconosciuti vengono ritentati
con backoff senza modificare l'ordine. Se lo stato ordine è stato cambiato
manualmente al di fuori della sequenza prevista, l'aggiornamento è sospeso.

## Attivazione e passaggio dal vecchio modulo

1. Distribuire il webservice e applicare le migrazioni Phinx. Distribuire la
   nuova versione del modulo `webserviceapi`, eseguire il suo upgrade a
   `1.1.2` e verificare che il pannello Packlink appaia nella pagina ordine.
2. Se il vecchio modulo Packlink rimane attivo, distribuire anche i suoi file
   modificati in questa PR. Arrestare e svuotare la sua coda di creazione prima
   del passaggio. La guardia impedisce nuove bozze legacy per ordini dalla data
   di attivazione e lascia quelli precedenti al flusso preesistente.
3. Scegliere un istante **futuro** dopo il rilascio, preciso al secondo, nel
   fuso usato da `orders.date_add`. Impostare lo stesso valore in `since` e
   nella configurazione PrestaShop `PACKLINK_EXTERNAL_SHIPMENTS_SINCE`. Ad
   esempio, dalla radice PrestaShop, dopo aver sostituito la data:

   ```sh
   php -r 'require "config/config.inc.php"; Configuration::updateValue("PACKLINK_EXTERNAL_SHIPMENTS_SINCE", "2030-01-01 00:00:00");'
   ```

   Svuotare la cache PrestaShop se necessario. Il controller rifiuta la
   scansione se le due date non coincidono o se il modulo legacy attivo non
   espone la guardia. Non anticipare la data dopo l'avvio: un ordine già
   inviato dal vecchio modulo potrebbe altrimenti essere duplicato.
4. Configurare e verificare servizi, mittente, pacchi, stati e credenziali;
   impostare `PACKLINK_SHIPPING_ENABLED=true` e riavviare lo scheduler. Gli
   ordini precedenti al cutover non vengono importati o inviati dal nuovo
   worker. Verificare con un ordine di prova che l'ordine si concluda anche
   quando Packlink non è raggiungibile, e che la bozza compaia una sola volta.

Per tornare indietro, disabilitare il nuovo worker ma **mantenere la guardia**:
riattivare la creazione legacy sugli stessi ordini può duplicare spedizioni.
Riconciliare manualmente i riferimenti già creati prima di modificare il
cutover o riabilitare la vecchia creazione.

## Webhook e riconciliazione

Il polling funziona anche senza webhook. Se l'account supporta la registrazione
integrazione con header segreto, configurare `PACKLINK_WEBHOOK_URL` come URL
HTTPS pubblico di `/api/webhooks/packlink`, un `PACKLINK_WEBHOOK_SECRET`
casuale di almeno 32 byte, e tipo, GUID e nome dell'integrazione previsti dal
proprio account. Eseguire esplicitamente:

```sh
php bin/console app:packlink:register-integration --confirm-registration
```

Salvare l'ID restituito in `draft.integration_id`. Prima di ritentare una
registrazione con risposta persa, controllare l'account Packlink: l'esito può
essere ambiguo. Non usare il callback legacy privo di header come webhook
autenticato. Il webhook verifica `X-Packlink-Webhook-Secret`, valida l'evento
e anticipa la rilettura dello stato corrente; i dati dell'evento non vengono
applicati direttamente. Polling e webhook possono essere usati insieme.

## Diagnosi e recupero

La tabella `packlink_shipments` contiene ID ordine, riferimento, checkpoint,
contatori, prossimi tentativi e codici di errore senza payload cliente.
`pending` ritenta la preparazione con backoff. `unknown` significa che il POST
può essere arrivato al provider; `creating` indica un processo interrotto o
ancora in corso. Nessuno dei due viene ritentato automaticamente. Un processo
interrotto durante la riconciliazione resta con `sync_state=running`.

Fermare **tutti** i worker di spedizione, verificare l'ordine nel pannello
Packlink, poi scegliere una sola azione:

```sh
# Se esiste una bozza con quel riferimento, verificarla contro Packlink:
php bin/console app:packlink:recover 123 --worker-stopped --reference=RIFERIMENTO
# Solo dopo aver verificato che nessuna bozza esista:
php bin/console app:packlink:recover 123 --worker-stopped --confirm-no-draft
# Per anticipare un retry dopo aver corretto configurazione o dati:
php bin/console app:packlink:recover 123 --worker-stopped --retry-pending
php bin/console app:packlink:recover 123 --worker-stopped --retry-sync
# Se si è interrotta solo la sincronizzazione:
php bin/console app:packlink:recover 123 --worker-stopped --reset-sync
```

Riavviare i worker dopo il recupero. Un errore `shop_update_failed` può anche
indicare una modifica manuale dello stato ordine: verificare il conflitto prima
di intervenire. Non reinviare una creazione ambigua senza accertare l'assenza
di una bozza sul provider.
