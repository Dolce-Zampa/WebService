# Margini ordini DolceZampa

Modulo per PrestaShop 8.1/9: nella sezione **Ordini → Margini ordini** mostra gli ordini dal 1° maggio 2026 e consente di esportare un CSV UTF-8 con separatore `;`, apribile con Excel. L'esportazione contiene valori statici, non formule: dopo una modifica riesportare il CSV.

## Installazione

1. Copiare la cartella `dzordini` in `modules/` e installare il modulo dal gestore moduli di PrestaShop. L'account MySQL deve poter creare una tabella e una vista.
2. Il modulo crea la vista `${prefisso}dz_order_margin_view` sugli ordini e la tabella `${prefisso}dz_order_margin` per i soli costi di pagamento inseriti manualmente. Si può disinstallare il modulo: la vista viene eliminata, ma la tabella con i costi manuali viene conservata.
3. Autorizzare alla pagina solo i profili amministrativi interessati tramite le normali autorizzazioni del back office. La pagina rispetta anche i negozi accessibili e il contesto multinegozio.

La tabella `manufacturer_details`, con il campo `commission`, deve essere presente nel database PrestaShop. La visualizzazione delle commissioni di pagamento registrate richiede anche le tabelle `financial_transactions` e `financial_transaction_events` create dalle migrazioni del webservice. Se il registro non è disponibile, i costi restano vuoti e possono essere inseriti manualmente. Un valore manuale prevale sul registro; salvare il campo vuoto per ripristinare il valore del registro. Uno zero esplicito è distinto da un costo mancante.

## Calcoli e limiti

- Il totale dei prodotti è il totale ordine, IVA inclusa, meno spedizione pagata dal cliente e confezione regalo. Ogni riga prodotto viene attribuita al suo `product.id_manufacturer`; la percentuale di commissione DolceZampa è letta da `manufacturer_details.commission` e la quota artigiano è il complemento a 100. Un ordine può contenere più artigiani.
- La relazione prodotto/artigiano e la percentuale sono **quelle attuali**, non una fotografia storica. Possono quindi non descrivere le condizioni in vigore alla data di un ordine. Il calcolo è una stima percentuale e non replica eventuali minimi, arrotondamenti o altre regole del payout Stripe Connect.
- `commission = 0` è un valore predefinito storico ambiguo nel progetto: viene segnalato, non interpretato automaticamente come commissione zero o 20%.
- L'importo del provider è letto dal ledger per ordine e valuta, usando l'ultimo stato del journal; non viene stimato se manca. Gli eventi senza `order_id` non sono attribuiti per tentativi.
- Se righe e totale prodotti non quadrano oltre un centesimo, ad esempio a causa di sconti non ripartiti, le spettanze e il margine rimangono vuoti. Lo stesso vale per ordini non validi e per rimborsi o note di credito rilevati: verificare e riconciliare prima di usare il risultato.
- Il margine parziale è totale ordine meno spettanza artigiano e costo pagamento. Esclude il costo del corriere, imposte, contributi e qualunque altra spesa. Non è un utile o un dato fiscale accertato. Gli importi di valute diverse non vengono sommati.

## Verifica locale

Dalla radice `PsWebService`:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php resources/prestashop-modules/dzordini/Test
find resources/prestashop-modules/dzordini -name '*.php' -exec php -l {} \;
```

I test usano SQLite in memoria per i calcoli e le query di lettura. Prima dell'uso verificare anche installazione, permessi, vista MySQL, filtri negozio, CSV e riconciliazione delle commissioni su un'istanza PrestaShop di prova con dati rappresentativi.
