# Spedizioni Packlink dirette

**Stato: integrazione non verificata con l’account.** Le interfacce HTTP sono
ricavate dal codice del modulo e non è accertato che siano pubbliche o supportate.
Prima dell’attivazione leggere [l’analisi delle differenze](packlink-integration-investigation.md).

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
    "user_id": "USER_ID", "client_id": "CLIENT_ID", "platform": "PRO",
    "platform_country": "IT", "source": "module_prestashop",
    "integration_id": "INTEGRATION_ID"
  },
  "package": {"weight": 1, "width": 10, "height": 10, "length": 10},
  "content": "Prodotti", "sync_interval_seconds": 900,
  "states": {
    "AWAITING_COMPLETION": {"order_state_id": 101, "rank": 0, "labels": false},
    "READY_TO_PRINT": {"order_state_id": 103, "rank": 1, "labels": true},
    "DELIVERED": {"order_state_id": 104, "rank": 2, "labels": true}
  }
}
```

Le chiavi `carriers` sono gli ID **riferimento corriere** PrestaShop; i valori
`service_id` sono ID di servizi Packlink, non ID del metodo del modulo.
I servizi a punto di ritiro (`pickup: true`) non sono ancora supportati: non
esiste nel webservice una fonte indipendente per il punto selezionato al
checkout. Il worker lascia questi ordini in attesa con errore
`pickup_unavailable`, senza creare una bozza o interferire con la chiusura
dell'ordine. Non abilitarli finché il checkout non conserva una selezione
utilizzabile senza il modulo Packlink. I servizi a domicilio usano `pickup: false`.

Il mittente viene letto dal profilo dell'unico artigiano associato ai prodotti
dell'ordine (`manufacturer` e `manufacturer_details`), incluso il paese
convertito nel relativo codice ISO. Non si usa un mittente generale di
fallback: se ci sono più artigiani oppure mancano dati obbligatori del profilo,
la bozza resta in attesa (`seller_address_unavailable`). I dati del profilo
sono l'indirizzo di spedizione scelto da questa integrazione: confermarne la
correttezza con gli artigiani prima di attivarla.

Il peso viene letto dalla riga ordine o dal prodotto. Le dimensioni vengono
dal prodotto; per ciascun valore mancante si usano i valori `package`
configurati, in kg e cm. Per più pezzi si sommano i pesi, si prendono larghezza
e lunghezza massime e si sommano le altezze, come se i prodotti fossero
impilati. È una stima del pacco: verificare dimensioni e peso effettivi nel
pannello Packlink prima del pagamento. Le unità PrestaShop riconosciute sono
`kg`, `g`, `oz`, `lbs` e `cm`; altre unità sospendono la preparazione.

Il contenuto viene costruito dai nomi delle categorie predefinite dei prodotti
fisici, nella lingua dell'ordine, senza ripetizioni e limitato a 60 caratteri.
Se non è disponibile alcuna categoria, viene usato il testo `content`
configurato.

Mappare ogni stato effettivamente restituito dall'account. `order_state_id` è
l'ID PrestaShop configurato nel negozio; `rank` stabilisce l'avanzamento e
impedisce di applicare un ritorno a uno stato precedente. Stati con lo stesso
rank devono mappare allo stesso stato ordine. `labels: true` abilita il
recupero delle etichette per quello stato. Stati sconosciuti vengono ritentati
con backoff senza modificare l'ordine. Se lo stato ordine è stato cambiato
manualmente al di fuori della sequenza prevista, l'aggiornamento è sospeso.

## Attivazione

Il modulo Packlink non è installato su PrestaShop 9.2 e non è richiesto dal
nuovo flusso. Il worker usa esclusivamente l'API Packlink diretta e le API del
modulo `webserviceapi`.

1. Distribuire il webservice e applicare le migrazioni Phinx. Distribuire la
   nuova versione del modulo `webserviceapi`, eseguire il suo upgrade a
   `1.1.2` e verificare che il pannello Packlink appaia nella pagina ordine.
2. Scegliere un istante **futuro** dopo il rilascio, preciso al secondo, nel
   fuso usato da `orders.date_add`; impostarlo in `since`. Gli ordini precedenti
   non vengono importati dal nuovo worker. Non anticipare la data dopo l'avvio:
   ordini precedenti potrebbero già avere una spedizione sul provider.
3. Configurare e verificare servizi a domicilio, profili degli artigiani, pacchi, stati e
   credenziali; impostare `PACKLINK_SHIPPING_ENABLED=true` e riavviare lo
   scheduler. Provare la finalizzazione dell'ordine con Packlink non raggiungibile
   e verificare che una bozza venga creata una sola volta.

Il supporto ai punti di ritiro richiede un checkout indipendente dal vecchio
modulo che registri il punto scelto. Fino alla sua realizzazione e verifica,
quegli ordini devono essere gestiti manualmente: non è sufficiente configurare
un corriere pickup nel worker.

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

## Invio dell'etichetta all'artigiano

L'invio è disabilitato finché non viene configurato esplicitamente, dopo una
prova con l'account reale. Aggiungere all'oggetto `PACKLINK_SHIPPING_SETTINGS`:

```json
"label_email": {
  "enabled": true,
  "paid_states": ["STATO_PACKLINK_CON_PAGAMENTO_CONFERMATO"],
  "allowed_hosts": ["host-etichette-verificato.example"]
}
```

Sostituire i segnaposto con stati e host HTTPS effettivamente verificati sul
proprio account. Gli stati devono anche avere `labels: true` nella mappatura
`states`. Non includere bozze, pagamenti in sospeso o stati annullati.
Consentire solo hostname di distribuzione delle etichette controllati e attendibili:
i redirect non vengono seguiti e solo i PDF validi fino a 4 MiB complessivi
vengono allegati. L'account e il server devono poter raggiungere gli host e
PrestaShop deve avere l'invio email attivo e i template del modulo distribuiti.

L'evento `shipment.label.ready` anticipa la sincronizzazione; anche il polling
recupera i webhook persi. Prima dell'invio vengono riletti stato e link freschi
dal provider. L'indirizzo email è quello del produttore PrestaShop associato
all'ordine. Il destinatario viene acquisito alla creazione della spedizione e
ricontrollato subito prima dell'invio: se l'ordine comprende più artigiani, un
prodotto è mancante o la sua associazione cambia, l'invio è sospeso (`held`).
La verifica usa le associazioni prodotto-produttore presenti nel catalogo al
momento della creazione; non ricostruisce una titolarità storica del prodotto.
Gestire manualmente questi ordini usando l'etichetta nel pannello ordine.

`label_mail_status=sent` indica che PrestaShop ha accettato la richiesta di
invio, non garantisce la consegna nella casella. `preparing` indica preparazione
interrotta; `sending` o `unknown` indicano una consegna potenzialmente già
avvenuta. Non si ritenta automaticamente un invio ambiguo. Per ripristinare un
tentativo, fermare tutti i worker, verificare i log del sistema email e
accertare che il messaggio non sia stato inviato. Solo allora un operatore può
riportare manualmente lo stato a `pending` e azzerare `label_mail_due_at` nella
tabella `packlink_shipments`; riavviare poi i worker. Monitorare anche `held`,
`preparing`, `sending` e `unknown`: richiedono intervento umano.

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
