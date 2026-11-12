# Catalogo punti InPost

Il web service mantiene un catalogo locale per la ricerca di locker e InPost Point italiani. Non modifica né usa il modulo Packlink. La fonte è il servizio pubblico italiano descritto nella [guida InPost](https://inpost.it/sites/default/files/2023-01/InpostIntegrationGuide.pdf). L'accesso tecnico non dimostra il diritto di riuso: verificare autorizzazione, conservazione, frequenza e limiti prima dell'uso continuativo in produzione.

## Attivazione

1. Applicare la migrazione ordinaria del progetto (`composer db:migrate`) nel normale processo di rilascio.
2. Dopo la verifica delle condizioni della fonte, eseguire l'importazione iniziale con `php bin/console app:inpost:sync`.
3. Configurare `INPOST_CARRIER_IDS` con gli ID carrier PrestaShop attuali, separati da virgole. Verificarli a ogni ricreazione del carrier.
4. Impostare `INPOST_SYNC_ENABLED=true` e riavviare lo scheduler. Il job parte alle 02:30 Europe/Rome. La sincronizzazione automatica è disabilitata per default.
5. Configurare `INPOST_CATALOG_MAX_AGE_HOURS` (default 72); oltre questa età l'API restituisce 503. Monitorare i log dello scheduler, in particolare `InPost catalog synchronization failed`.

L'aggiornamento serializza le esecuzioni tramite un lock transazionale sulla riga dei metadati, verifica paginazione, identificativi e stato, controlla di nuovo la prima pagina e pubblica il nuovo insieme in un'unica transazione. Un'importazione incompleta, un catalogo vuoto o una riduzione superiore al 50% mantengono il catalogo precedente. Anche con questi controlli, una fonte paginata che cambia durante la lettura non garantisce uno snapshot perfetto. Errori temporanei di rete e 5xx sono riprovati una volta; gli errori definitivi e i 429 sono registrati e il job successivo riprova. Il comando manuale restituisce un codice di uscita diverso da zero in caso di errore.

## Ricerca

`GET /api/inpost/points?postal_code=20100&page=1&limit=20` oppure `GET /api/inpost/points?city=Milano`. È richiesto almeno un filtro; CAP e città sono corrispondenze esatte. La risposta segue il formato standard del web service e include `items`, `page`, `limit`, `total` e `last_sync_at` UTC. `limit` va da 1 a 100. Il punto include `point_id`, nome, tipo (`locker` o `point`), indirizzo, città, provincia, CAP, coordinate e orari quando disponibili. Risultati vuoti restituiscono 200; parametri non validi 422; catalogo assente o troppo vecchio 503.

`GET /api/inpost/config` espone gli ID carrier configurati. Per un carrier configurato il checkout invia `inpost_point_id`: il backend verifica che il catalogo sia fresco e che il punto sia presente, quindi sostituisce l'indirizzo di consegna con quello verificato e ne salva l'identificativo nello snapshot cifrato. L'indirizzo di fatturazione resta invariato. Il punto è trasferito all'ordine come indirizzo di consegna, con l'identificativo in `address2`, e il webhook non lo sostituisce con un eventuale indirizzo Stripe. Le spedizioni restano manuali.
