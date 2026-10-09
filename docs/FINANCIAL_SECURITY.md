# Sicurezza Financial Tracking

Il ledger finanziario non espone al momento endpoint HTTP di lettura o scrittura: viene alimentato esclusivamente dal webhook Stripe firmato. Le future route di report, payout o dettaglio movimento devono essere protette da `AuthenticationMiddleware` seguito da `FinancialAuthorizationMiddleware`; non devono mai restituire direttamente oggetti del repository o colonne provider.

## Matrice ruoli e risorse

| Risorsa / azione | Artigiano autenticato | `financial-admin` | Webhook Stripe firmato |
| --- | --- | --- | --- |
| Propri movimenti, report e payout | Consentiti solo se il proprietario viene risolto server-side dal `sub` | Consentiti | Negati |
| Movimenti/report/payout di un altro artigiano | Negati con 404 | Consentiti | Negati |
| Report di marketplace e operazioni trasversali | Negati | Consentiti | Negati |
| Scrittura del ledger | Negata | Solo da un caso d'uso interno autorizzato | Solo eventi Stripe verificati e idempotenti |

Il ruolo marketplace è **default deny**: è riconosciuto solo dal gruppo Cognito `financial-admin` oppure da un subject esplicitamente configurato in `FINANCIAL_ADMIN_SUBS`. Non viene dedotto da parametri di input o dal fatto che l'utente sia un seller.

## Regole di implementazione

- La risorsa di un artigiano deve essere caricata lato server e il suo `sub` passato al resolver di `FinancialAuthorizationMiddleware`. Gli ID in route, query e body servono solo a cercare la risorsa: non stabiliscono mai la proprietà.
- In caso di proprietà assente o non autorizzata si risponde 404, evitando di confermare l'esistenza di record finanziari di terzi.
- `FinancialMovement` e gli eventi del ledger accettano solo piccoli valori scalari di riconciliazione. Chiavi che indicano dati carta, coordinate bancarie, segreti, token, PII o payload completi del provider vengono rifiutate; array e oggetti non sono ammessi.
- Il webhook Stripe verifica sempre la firma sul body originale, inclusa una tolleranza del timestamp limitata a 300 secondi (`STRIPE_WEBHOOK_TOLERANCE_SECONDS`, valori non validi riportano al default). L'identificativo dell'evento è usato come chiave di idempotenza nel journal, rendendo innocuo un retry o replay dello stesso evento. Non registrare mai il payload grezzo, né includerlo nelle risposte o nei log.
