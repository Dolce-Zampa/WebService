# Release notes — Staging regression fixes

## Modifiche

- Corretto il mantenimento del telefono mobile proveniente dall'indirizzo cliente. Il telefono specifico dell'indirizzo ha precedenza; in sua assenza viene usato il telefono del cliente o dell'indirizzo alternativo.
- Allineati i test del checkout ai totali di spedizione del carrello usati per il pagamento e per il recupero dei carrelli abbandonati.
- Aggiornate le fixture guest con il paese richiesto dalla validazione degli indirizzi.

## Variabili d'ambiente

Questa release non introduce nuove variabili d'ambiente obbligatorie.

La configurazione già supportata `STRIPE_SHIPPING_RATE_IDS` associa gli ID dei carrier PrestaShop agli ID delle tariffe Stripe. Va impostata come oggetto JSON quando si usa una shipping rate Stripe, per esempio:

```dotenv
STRIPE_SHIPPING_RATE_IDS={"2":"shr_...","3":"shr_..."}
```

Gli ID devono corrispondere ai carrier configurati nello shop e alle tariffe presenti nell'account Stripe dell'ambiente. La variabile è già elencata in `.env.example`.

## Operazione di rilascio e compatibilità cache

La validazione ora richiede che ogni indirizzo di consegna includa `id_country` oppure `country`. Una checkout session in cache creata da una versione precedente potrebbe contenere un indirizzo senza paese e non essere più utilizzabile correttamente dal webhook di conferma. Durante il rilascio, svuotare la cache delle checkout session preesistenti (o attendere che scadano prima di distribuire la versione) per evitare che eventi Stripe ancora pendenti incontrino dati incompatibili.

Verificare inoltre nel deployment che `APP_DISABLE_CACHE` non sia impostata a `true` se si desidera usare le cache; il valore disabilita la cache applicativa e il warmup dei prodotti. Non è una nuova variabile richiesta da questa release.

## Verifica

Suite PHPUnit eseguita localmente su `staging` con cache abilitata: 96 test, 408 assertion, tutti superati.
