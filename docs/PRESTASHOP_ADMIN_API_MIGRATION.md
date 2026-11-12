# PrestaShop 9.2 — analisi migrazione API standard

Data: 5 novembre 2026. Responsabile: Marco, team DolceZampa.

## Perimetro e risultato

Analisi del backend `PsWebService` / repository `Dolce-Zampa/WebService` al commit `3b947e4eabd30b9a1486dca01025eeb3394063cb`, senza modifiche al codice applicativo, e della copia locale `../dolcezampa-main`. Il core dichiara **9.2.0** in `src/Core/Version.php:15`; il modulo **ps_apiresources 0.9.0** in `modules/ps_apiresources/config.xml:5`.

Sono state individuate **18 API standard**: **14 candidate con livelli diversi di copertura**, e **4 prive di un equivalente completo nello snapshot analizzato**. Due ulteriori ticket coprono accesso/OpenAPI e client OAuth2. Una issue per ogni API attuale; conteggio e lista prodotti condividono lo stesso endpoint. Le API senza parità restano nel backlog, senza richiesta di sviluppare nuovi endpoint custom.

La disponibilità nel sorgente non equivale a funzionamento sul negozio. Una GET HTTPS non autenticata a `https://aidyis-prod-backoffice.dolcezampa.com/admin-api/docs.json` ha restituito **HTTP 404, HTML dello storefront**. Il test non identifica la causa né dimostra che tutti gli endpoint Admin siano disattivati. Non sono stati ottenuti token né eseguite operazioni di scrittura sul negozio. Verificare host, routing, kernel, modulo caricato e schema effettivo prima di ogni attivazione.

## Esclusioni confermate dal routing

`../dolcezampa-main/.htaccess:20-37` e `modules/webserviceapi/webserviceapi.php:478` instradano al modulo **webserviceapi** anche nomi che sembrano risorse standard. Sono esclusi dalla migrazione:

- `/api/carts`, `/api/orders`, `/api/cart_rules`;
- `/api/customers`, `/api/account`, `/api/addresses`;
- `/api/login`, `/api/register`, `/api/logout`, `/api/password-reset`, `/api/contact`;
- `/api/catalog`, `/api/catalog/update`, `/api/products/update`, `/api/filters`, `/api/promotions`, `/api/wishlists`, `/api/mailer`;
- `/api/module/welcome-coupon` e altri endpoint di moduli custom.

Restano fuori perimetro accessi SQL, configuratore, bundle, recensioni, profili artigiani, Stripe e logica custom di carrello/ordine. Il cliente Admin deve coesistere con quello attuale: sostituire globalmente la base `/api` romperebbe questa separazione. Gli arricchimenti esistenti dei payload devono continuare a essere alimentati dalle loro sorgenti.

`product_customization_fields`, `stock_availables`, `search` e `content_management_system` sono invece risorse **standard** confermate in `classes/webservice/WebserviceRequest.php`, e sono incluse nell'analisi.

## Principali differenze di contratto

- Basic/X-WS-Key viene affiancato da OAuth2 `client_credentials`, con scope e token a scadenza; credenziali conservate solo lato server.
- Le liste Admin usano `items`, `totalItems`, `limit`, `offset`, `orderBy`, `sortOrder` e `filters`; non gli involucri per risorsa e i parametri `display/filter/sort` del Webservice. Default locali: prodotti 20, categorie e corrieri 50. La prima pagina non rappresenta l'intero catalogo.
- Le liste amministrative restituiscono pochi campi; la scheda completa richiede dettagli/associazioni e una strategia cache che eviti chiamate aggiuntive per ogni elemento ad ogni visita.
- Le risorse singole usano camelCase e traduzioni per locale (es. `names[it-IT]`); la lista è normalmente monolingua. Confermare lingua e shop effettivi. Se multistore è attivo, verificare anche il supporto/feature flag e i parametri di contesto.
- Prezzi da back office, impatti delle varianti e ricerca amministrativa non sono automaticamente equivalenti a prezzi scontati con IVA e ricerca storefront.
- Lo snapshot dichiara una DELETE immagini senza scope esplicito. Verificare schema e autorizzazione effettivi prima della relativa migrazione.
- I metodi immagini di lettura deprecata, upload e delete richiedono anche verifica dei chiamanti: i metodi di scrittura non hanno chiamanti diretti in `src` nello snapshot analizzato.

## Ordine di lavoro

1. Accesso reale alle Admin API, schema installato e verifica della compatibilità (il precedente aggiornamento è tracciato in DZ-138, già Done).
2. Client dedicato, gestione token, adapter e selettore per operazione, test di paginazione/contesto.
3. Letture circoscritte: dettaglio categoria, valori attributi/caratteristiche; immagini se usate, poi corrieri e marchi con verifica dei campi.
4. Varianti, dettaglio/liste prodotti e ricerca solo con parità documentata di prezzi, associazioni, visibilità e prestazioni.
5. Stock, campi di personalizzazione e CMS restano legacy finché una risorsa standard equivalente non è confermata.

## Progetto e prerequisiti su Linear

[PrestaShop 9.2 — migrazione API standard](https://linear.app/dolce-zampa/project/prestashop-92-migrazione-api-standard-ddfe5128b457) — lead e assignee: Marco. Tre milestone: accesso/client, API candidate e gap rinviati.

- [DZ-148](https://linear.app/dolce-zampa/issue/DZ-148/admin-api-verificare-accesso-openapi-e-compatibilita-prestashop-92) — [Admin API] Verificare accesso, OpenAPI e compatibilità PrestaShop 9.2
- [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api) — [Admin API] Introdurre client OAuth2 e selezione del trasporto per API

## Matrice API

| API attuale | Destinazione Admin API | Fattibilità | Issue |
|---|---|---|---|
| `GET /api/categories/{id}?display=full` | `GET /admin-api/categories/{categoryId}` | Candidata: mapping diretto del contratto minimo CategoryEntity | [DZ-150](https://linear.app/dolce-zampa/issue/DZ-150/admin-api-migrare-get-categoriesid) |
| `GET /api/categories?display=full e filter[id]` | `GET /admin-api/categories, con GET /admin-api/categories/{categoryId} per i campi estesi` | Parziale: la lista Admin espone solo categoryId/enabled/name | [DZ-151](https://linear.app/dolce-zampa/issue/DZ-151/admin-api-migrare-get-categories-con-paginazione-e-dati-seo) |
| `GET /api/product_option_values/{id}?display=full` | `GET /admin-api/attributes/attributes/{attributeId}` | Candidata con verifica del campo position | [DZ-152](https://linear.app/dolce-zampa/issue/DZ-152/admin-api-migrare-get-product-option-valuesid) |
| `GET /api/product_feature_values/{featureValueId}?display=full` | `GET /admin-api/features/values/{featureValueId}` | Candidata con adapter di campi e traduzioni | [DZ-153](https://linear.app/dolce-zampa/issue/DZ-153/admin-api-migrare-get-product-feature-valuesid) |
| `GET /api/images/products/{productId}/{imageId}/{type}` | `GET /admin-api/products/images/{imageId}; GET /admin-api/products/{productId}/images per la collezione` | Candidata con verifica delle varianti ImageTail e dell'uso attivo | [DZ-154](https://linear.app/dolce-zampa/issue/DZ-154/admin-api-migrare-get-immagini-prodotto-a-metadati-e-url) |
| `POST /api/images/products/{productId}` | `POST /admin-api/products/{productId}/images (multipart/form-data, campo image)` | Candidata: operazione presente nel sorgente | [DZ-155](https://linear.app/dolce-zampa/issue/DZ-155/admin-api-migrare-post-immagini-prodotto) |
| `DELETE /api/images/products/{productId}/{imageId}` | `DELETE /admin-api/products/images/{imageId}` | Candidata subordinata a verifica scope e appartenenza immagine | [DZ-156](https://linear.app/dolce-zampa/issue/DZ-156/admin-api-migrare-delete-immagini-prodotto) |
| `GET /api/carriers/{carrierId}?display=full` | `GET /admin-api/carriers/{carrierId}` | Parziale: verificare tutti i campi del contratto Carrier | [DZ-157](https://linear.app/dolce-zampa/issue/DZ-157/admin-api-migrare-get-carriersid) |
| `GET /api/carriers?display=full&filter[active]=1&filter[deleted]=0` | `GET /admin-api/carriers` | Parziale: lista sintetica da integrare con il dettaglio | [DZ-158](https://linear.app/dolce-zampa/issue/DZ-158/admin-api-migrare-get-carriers) |
| `GET /api/manufacturers?display=full, filter[id] e filter[active]` | `GET /admin-api/manufacturers e GET /admin-api/manufacturers/{manufacturerId} per il dettaglio` | Parziale: migrazione limitata ai campi core del marchio | [DZ-159](https://linear.app/dolce-zampa/issue/DZ-159/admin-api-migrare-get-manufacturers-per-i-dati-standard) |
| `GET /api/combinations/{id}?display=full&price[...]` | `GET /admin-api/products/combinations/{combinationId}, con GET /admin-api/products/{productId}/combinations per gli attributi` | Parziale: il dettaglio Admin non è il payload variante dello storefront | [DZ-160](https://linear.app/dolce-zampa/issue/DZ-160/admin-api-migrare-get-combinationsid) |
| `GET /api/products/{id}?display=full&price[...]` | `GET /admin-api/products/{productId}` | Parziale: metadati core migrabili, dettaglio storefront completo non equivalente | [DZ-161](https://linear.app/dolce-zampa/issue/DZ-161/admin-api-migrare-get-productsid-per-il-dettaglio-standard) |
| `GET /api/products?display=full oppure display=[id], filtri e sort` | `GET /admin-api/products` | Parziale: la griglia Admin non fornisce una scheda prodotto completa | [DZ-162](https://linear.app/dolce-zampa/issue/DZ-162/admin-api-migrare-get-products-per-liste-e-conteggi) |
| `GET /api/search?query={query}&language=1&display=full` | `GET /admin-api/products/search?phrase=...&resultsLimit=...&isoCode=EUR` | Condizionata: ricerca amministrativa diversa da ricerca storefront | [DZ-163](https://linear.app/dolce-zampa/issue/DZ-163/admin-api-valutare-e-migrare-get-search-solo-con-parita-storefront) |
| `GET /api/stock_availables?filter[id_product]={id}&display=full` | `Nessuna risorsa Admin equivalente trovata in ps_apiresources 0.9.0 locale` | Non migrabile integralmente con le risorse analizzate | [DZ-164](https://linear.app/dolce-zampa/issue/DZ-164/admin-apigap-rinviare-get-stock-availables-fino-a-copertura) |
| `GET /api/product_customization_fields?filter[id_product]={id}&display=full` | `Nessuna risorsa dedicata equivalente trovata nello snapshot analizzato` | Non migrabile integralmente; dati parziali nella ricerca Admin | [DZ-165](https://linear.app/dolce-zampa/issue/DZ-165/admin-apigap-rinviare-get-product-customization-fields) |
| `GET /api/content_management_system?display=[id,meta_title,link_rewrite]` | `Nessuna risorsa CMS Page di lista trovata nello snapshot analizzato` | Non migrabile con le risorse analizzate | [DZ-166](https://linear.app/dolce-zampa/issue/DZ-166/admin-apigap-rinviare-get-content-management-system) |
| `GET /api/content_management_system/{id}` | `Nessuna risorsa CMS Page di dettaglio trovata nello snapshot analizzato` | Non migrabile con le risorse analizzate | [DZ-167](https://linear.app/dolce-zampa/issue/DZ-167/admin-apigap-rinviare-get-content-management-systemid) |

## Fonti

- [Documentazione ufficiale: usare le Admin API](https://devdocs.prestashop-project.org/9/admin-api/how-to-use/).
- [Documentazione ufficiale: server di autorizzazione interno](https://devdocs.prestashop-project.org/9/admin-api/authorization_server/internal_authorization/).
- [API Resources, release v0.9.0](https://github.com/PrestaShop/ps_apiresources/releases/tag/v0.9.0). Il contratto valutato è quello dei file locali, da confrontare con la versione effettivamente distribuita.
- Codice backend: commit indicato sopra; sorgenti Admin API in `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources`.
- Provider/paginazione core: `src/PrestaShopBundle/ApiPlatform/Provider/QueryListProvider.php` e `Pagination/PaginationElements.php` nella copia PrestaShop.

## Dettagli per issue

### DZ-148 — [Admin API] Verificare accesso, OpenAPI e compatibilità PrestaShop 9.2

[DZ-148](https://linear.app/dolce-zampa/issue/DZ-148/admin-api-verificare-accesso-openapi-e-compatibilita-prestashop-92)

## Obiettivo e fattibilità

**Prerequisito operativo.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `Nessuna migrazione di risorsa`

**Destinazione:** `GET /admin-api/docs.json e autenticazione Admin API`

## Evidenze e lavoro richiesto

Lo snapshot locale dichiara PrestaShop 9.2.0 e ps_apiresources 0.9.0. La lettura HTTPS non autenticata di https://aidyis-prod-backoffice.dolcezampa.com/admin-api/docs.json, eseguita il 5 novembre 2026, restituisce HTTP 404 con pagina HTML dello storefront. Questo non dimostra che tutte le Admin API siano disabilitate: occorre verificare host/base path, routing del virtual host e disponibilità del kernel AdminAPI. DZ-138, già Done, documenta un precedente problema di compatibilità dei parametri CQRSDelete: verificarne la risoluzione sull'ambiente effettivo senza riaprire l'aggiornamento.

## Riferimenti al codice

- [config/di/services-di.php:4](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/config/di/services-di.php#L4)
- `../dolcezampa-main/src/Core/Version.php:15`
- `../dolcezampa-main/modules/ps_apiresources/config.xml:5`
- `../dolcezampa-main/admin-api/index.php`
- `../dolcezampa-main/.htaccess`

## Criteri di accettazione

- [ ] Confermare versione core/modulo effettivamente installati e compatibilità delle risorse caricate.
- [ ] Ottenere uno schema OpenAPI dall'ambiente corretto (o esportarlo dal back office/CLI) e conservarne versione e data senza segreti.
- [ ] Verificare con un client dedicato token e almeno una GET di sola lettura; registrare scope e shop/lingua effettivi.
- [ ] Distinguere endpoint trovati nel sorgente da endpoint confermati a runtime; aggiornare la matrice prima delle migrazioni.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

### DZ-149 — [Admin API] Introdurre client OAuth2 e selezione del trasporto per API

[DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api) · Dipende da [DZ-148](https://linear.app/dolce-zampa/issue/DZ-148/admin-api-verificare-accesso-openapi-e-compatibilita-prestashop-92)

## Obiettivo e fattibilità

**Prerequisito tecnico.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `HttpService condiviso con base /api e Basic/X-WS-Key`

**Destinazione:** `Client dedicato /admin-api con OAuth2 client_credentials`

## Evidenze e lavoro richiesto

Il container fornisce oggi un HttpService condiviso e mutabile con base /api, Host fisso e credenziali Webservice. Introdurre un client Admin API distinto e un adapter per risorsa/operazione, senza cambiare globalmente la base URL usata dal modulo custom. Gestire token in cache fino a expires_in con margine; isolare credenziali, scope, contesto shop e lingua. Le liste Admin usano items/totalItems/limit/offset/orderBy/sortOrder/filters, le risorse singole proprietà camelCase e campi localizzati per locale. La cache prodotto usa attualmente la configurazione HTTP: introdurre un contesto stabile che non cambi con il rinnovo del token.

## Riferimenti al codice

- [src/Service/HttpService.php:24](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/HttpService.php#L24)
- [src/Domain/Object/WebserviceConfig.php:8](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Object/WebserviceConfig.php#L8)
- [config/di/services-di.php:4](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/config/di/services-di.php#L4)
- [src/Service/PS/Product.php:42](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L42)

## Criteri di accettazione

- [ ] Configurazione separata di base URL/client ID/secret/scope tramite secret store o ambiente; nessun segreto in repository, log, frontend o chiavi cache.
- [ ] Gestione scadenza e rinnovo concorrente dei token; un solo rinnovo e retry controllato sulle GET dopo 401; 403 distinto da token scaduto.
- [ ] Gestione JSON, errori, timeout e multipart; nessuna duplicazione automatica di POST/DELETE dopo esito incerto.
- [ ] Selettore per singola operazione e rollback al trasporto legacy; test che le chiamate del modulo custom continuino sul trasporto originario.
- [ ] Test del contratto su paginazione oltre la prima pagina, traduzioni e isolamento del contesto/cache.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [Documentazione PrestaShop](https://devdocs.prestashop-project.org/9/admin-api/authorization_server/internal_authorization/)

### DZ-150 — [Admin API] Migrare GET /categories/{id}

[DZ-150](https://linear.app/dolce-zampa/issue/DZ-150/admin-api-migrare-get-categoriesid) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata: mapping diretto del contratto minimo CategoryEntity.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/categories/{id}?display=full`

**Destinazione:** `GET /admin-api/categories/{categoryId}`

**Scope:** `category_read`

## Evidenze e lavoro richiesto

CategoryEntity espone attualmente solo id e name. Mappare categoryId → id e names[it-IT] → name, scegliendo esplicitamente la lingua del contesto. Per i dati estesi necessari alla lista categorie sono disponibili parentCategoryId, enabled, descriptions, additionalDescriptions, linkRewrites e metaTitles/metaDescriptions. Questa issue migra il dettaglio usato nelle associazioni prodotto e costituisce la base per la lista.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:158](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L158)
- [src/Domain/Entities/CategoryEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/CategoryEntity.php)
- [src/Traits/ProductBuilder.php:177](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L177)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Category/Category.php`

## Criteri di accettazione

- [ ] Parità di id/nome e tipo dei valori, compresa categoria senza traduzione nella lingua richiesta.
- [ ] 404 e categorie mancanti gestiti senza creare entità vuote.
- [ ] Cache e associazioni prodotto mantengono il contratto esistente.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Category)

### DZ-151 — [Admin API] Migrare GET /categories con paginazione e dati SEO

[DZ-151](https://linear.app/dolce-zampa/issue/DZ-151/admin-api-migrare-get-categories-con-paginazione-e-dati-seo) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api), [DZ-150](https://linear.app/dolce-zampa/issue/DZ-150/admin-api-migrare-get-categoriesid)

## Obiettivo e fattibilità

**Parziale: la lista Admin espone solo categoryId/enabled/name.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/categories?display=full e filter[id]`

**Destinazione:** `GET /admin-api/categories, con GET /admin-api/categories/{categoryId} per i campi estesi`

**Scope:** `category_read`

## Evidenze e lavoro richiesto

La lista attuale restituisce gerarchia, slug/URL, descrizioni, SEO, posizione e associazioni. La lista Admin è una griglia sintetica: id, enabled e name non bastano. Ricostruire i dati tramite dettaglio e cache, verificando la copertura di meta_keywords, is_root_category, date_add/date_upd e associations, non presenti nel DTO di dettaglio analizzato. Verificare anche l'ambito della griglia categorie: non assumere che la prima pagina rappresenti tutto l'albero. La ricerca per ID nel controller può usare direttamente il dettaglio.

## Riferimenti al codice

- [src/Service/PS/Category.php:10](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Category.php#L10)
- [src/Http/Controller/CategoryController.php:19](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Http/Controller/CategoryController.php#L19)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Category/CategoryList.php`

## Criteri di accettazione

- [ ] Nessun troncamento con oltre 50 categorie e nessuna perdita dei livelli dell'albero.
- [ ] Matrice campo-per-campo del payload pubblico; per i campi mancanti documentare una sorgente esistente o mantenere il percorso legacy.
- [ ] Parità di slug, parent_id, URL e SEO; nessuna richiesta aggiuntiva per categoria ad ogni lettura a cache calda.
- [ ] Confronto di latenza e numero di chiamate prima di abilitare il trasporto.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Category)

### DZ-152 — [Admin API] Migrare GET /product_option_values/{id}

[DZ-152](https://linear.app/dolce-zampa/issue/DZ-152/admin-api-migrare-get-product-option-valuesid) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata con verifica del campo position.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/product_option_values/{id}?display=full`

**Destinazione:** `GET /admin-api/attributes/attributes/{attributeId}`

**Scope:** `attribute_read`

## Evidenze e lavoro richiesto

Il percorso effettivo del modulo è /attributes/attributes/{attributeId}, non /attributes/{id}. Mappare attributeId → id, attributeGroupId → id_attribute_group, names[locale] → name e color. Il DTO singolo non espone position, mentre la lista /attributes/groups/{attributeGroupId}/attributes la espone: stabilire se il consumer la richiede e, se necessario, recuperarla con lista di gruppo paginata e cache. Conservare la classificazione attuale size/color/length/dimensions basata sul gruppo.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:65](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L65)
- [src/Domain/Entities/OptionEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/OptionEntity.php)
- [src/Traits/ProductBuilder.php:81](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L81)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Attribute/Attribute.php`

## Criteri di accettazione

- [ ] Test di colore, taglia e gruppi non predefiniti con traduzioni.
- [ ] Parità degli ID di attributo/gruppo e dell'ordine quando consumato.
- [ ] Nessuna chiamata duplicata per attributi condivisi tra combinazioni.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Attribute)

### DZ-153 — [Admin API] Migrare GET /product_feature_values/{id}

[DZ-153](https://linear.app/dolce-zampa/issue/DZ-153/admin-api-migrare-get-product-feature-valuesid) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata con adapter di campi e traduzioni.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/product_feature_values/{featureValueId}?display=full`

**Destinazione:** `GET /admin-api/features/values/{featureValueId}`

**Scope:** `feature_value_read`

## Evidenze e lavoro richiesto

Mappare featureValueId all'identificatore legacy del valore, featureId al collegamento della caratteristica e values[locale] al valore localizzato. Verificare l'uso effettivo di id/id_feature/id_feature_value nel payload attuale: il parametro id della funzione chiamante identifica la caratteristica, non il valore. Il DTO analizzato espone anche position ma non tutti i campi del Webservice (ad esempio custom).

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:113](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L113)
- [src/Domain/Entities/ProductFeatureEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/ProductFeatureEntity.php)
- [src/Traits/ProductBuilder.php:135](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L135)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Feature/FeatureValue.php`

## Criteri di accettazione

- [ ] Parità dei riferimenti caratteristica/valore, testo e lingua per il payload prodotto.
- [ ] Test con più valori e valore assente; evitare scambi tra featureId e featureValueId.
- [ ] Cache riusabile per valori condivisi; campi legacy richiesti ma assenti identificati prima del passaggio.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Feature)

### DZ-154 — [Admin API] Migrare GET immagini prodotto a metadati e URL

[DZ-154](https://linear.app/dolce-zampa/issue/DZ-154/admin-api-migrare-get-immagini-prodotto-a-metadati-e-url) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata con verifica delle varianti ImageTail e dell'uso attivo.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/images/products/{productId}/{imageId}/{type}`

**Destinazione:** `GET /admin-api/products/images/{imageId}; GET /admin-api/products/{productId}/images per la collezione`

**Scope:** `product_read`

## Evidenze e lavoro richiesto

Il client attuale tenta di interpretare la risposta come JSON url/id, mentre l'API immagini Webservice standard serve contenuti binari. L'Admin API fornisce imageId, imageUrl, thumbnailUrl, cover, position e legends. Mappare questi metadati al contratto ImageEntity e utilizzare la collezione per ridurre chiamate. buildImageLink è deprecato: verificarne i chiamanti effettivi prima di introdurre traffico nuovo. Le due URL Admin non garantiscono tutte le varianti previste da ImageTail.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:45](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L45)
- [src/Traits/ProductBuilder.php:21](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L21)
- [src/Domain/Entities/ImageEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/ImageEntity.php)
- [src/Domain/Enums/ImageTail.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Enums/ImageTail.php)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/ProductImage.php`

## Criteri di accettazione

- [ ] Matrice delle varianti ImageTail effettivamente usate, con URL validi e comportamento documentato per le varianti non esposte.
- [ ] Parità di ordinamento, cover e ID; nessuna decodifica JSON di un'immagine binaria.
- [ ] Un solo caricamento della collezione per prodotto a cache fredda e riuso della cache a caldo.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-155 — [Admin API] Migrare POST immagini prodotto

[DZ-155](https://linear.app/dolce-zampa/issue/DZ-155/admin-api-migrare-post-immagini-prodotto) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata: operazione presente nel sorgente.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `POST /api/images/products/{productId}`

**Destinazione:** `POST /admin-api/products/{productId}/images (multipart/form-data, campo image)`

**Scope:** `product_write`

## Evidenze e lavoro richiesto

Conservare l'interfaccia uploadProductImage(productId, imageUrl) e inviare il file al nuovo endpoint multipart. imageUrl nel DTO è un campo di risposta, non il sostituto del file image in ingresso. Il codice attuale concatena setMultipartData(...)->invoke(...), ma il setter concreto restituisce void e richiede una lista di parti multipart: risolvere il contratto nel client dedicato durante la migrazione. Non impostare manualmente Content-Type JSON sul multipart. Nel codice src non risultano chiamanti diretti di uploadProductImage: confermare il consumer previsto prima dell'attivazione.

## Riferimenti al codice

- [src/Service/PS/Product.php:504](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L504)
- [src/Service/HttpService.php:47](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/HttpService.php#L47)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/NewProductImage.php`

## Criteri di accettazione

- [ ] Upload di JPEG e PNG in ambiente di test, presenza sul prodotto e lettura dei metadati creati.
- [ ] Pulizia dei file temporanei anche in errore; credenziali Admin inviate solo al negozio.
- [ ] Gestione 401/403, prodotto mancante e file rifiutato; nessun retry cieco che possa creare immagini duplicate.
- [ ] Invalidazione cache prodotto dopo successo.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-156 — [Admin API] Migrare DELETE immagini prodotto

[DZ-156](https://linear.app/dolce-zampa/issue/DZ-156/admin-api-migrare-delete-immagini-prodotto) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Candidata subordinata a verifica scope e appartenenza immagine.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `DELETE /api/images/products/{productId}/{imageId}`

**Destinazione:** `DELETE /admin-api/products/images/{imageId}`

**Scope:** `Da confermare sullo schema live: la CQRSDelete locale non dichiara scopes espliciti`

## Evidenze e lavoro richiesto

Il nuovo percorso identifica soltanto l'immagine: prevedere prima della richiesta l'autorizzazione del chiamante sul prodotto e il controllo di appartenenza imageId → productId. Nel codice src non risultano chiamanti diretti di deleteImage: verificare come verrà esposto il metodo prima di attivarlo. Nel file locale ProductImage.php la DELETE non specifica scopes, a differenza delle altre operazioni; CQRSDelete ha scopes=[] come default. Non assumere product_write senza verificare il contratto e il comportamento effettivi della release installata.

## Riferimenti al codice

- [src/Service/PS/Product.php:556](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L556)
- [src/Service/HttpServiceInterface.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/HttpServiceInterface.php)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/ProductImage.php`

## Criteri di accettazione

- [ ] Verifica documentata dello scope effettivo e prove di accesso consentito/negato sul test shop prima dell'abilitazione.
- [ ] L'eventuale esposizione ai seller impedisce cancellazioni su prodotti altrui; relazione immagine/prodotto validata.
- [ ] Gestione risposta senza body, immagine assente e invalidazione cache/cover.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-157 — [Admin API] Migrare GET /carriers/{id}

[DZ-157](https://linear.app/dolce-zampa/issue/DZ-157/admin-api-migrare-get-carriersid) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Parziale: verificare tutti i campi del contratto Carrier.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/carriers/{carrierId}?display=full`

**Destinazione:** `GET /admin-api/carriers/{carrierId}`

**Scope:** `carrier_read`

## Evidenze e lavoro richiesto

Mappare carrierId → id, delays[locale] → delay, trackingUrl → url, enabled → active, free → is_free, additionalHandlingFee → shipping_handling e limiti dimensionali. Il contratto corrente legge anche deleted, shipping_external, range_behavior e shipping_method: alcuni hanno equivalenti, altri non sono nel DTO Admin. Verificare l'identità carrierId rispetto a eventuale id_relative e versioni del corriere. Non inventare flag mancanti né tariffe di spedizione.

## Riferimenti al codice

- [src/Service/PS/Carrier.php:51](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Carrier.php#L51)
- [src/Domain/Entities/CarrierEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/CarrierEntity.php)
- [src/Domain/Object/Carrier.php:22](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Object/Carrier.php#L22)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Carrier/Carrier.php`

## Criteri di accettazione

- [ ] Matrice completa dei campi letti da Domain/Object/Carrier con equivalenti o sorgenti esistenti.
- [ ] Parità del corriere usato dal checkout; 404 conserva il ritorno null.
- [ ] Valori lingua, tipi numerici/booleani e identificatori confrontati su corrieri di negozio e di modulo.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Carrier)

### DZ-158 — [Admin API] Migrare GET /carriers

[DZ-158](https://linear.app/dolce-zampa/issue/DZ-158/admin-api-migrare-get-carriers) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api), [DZ-157](https://linear.app/dolce-zampa/issue/DZ-157/admin-api-migrare-get-carriersid)

## Obiettivo e fattibilità

**Parziale: lista sintetica da integrare con il dettaglio.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/carriers?display=full&filter[active]=1&filter[deleted]=0`

**Destinazione:** `GET /admin-api/carriers`

**Scope:** `carrier_read`

## Evidenze e lavoro richiesto

La lista Admin espone carrierId/name/delay/enabled/free/position/moduleName/logoUrl; non basta per costruire Domain/Object/Carrier. Usare dettaglio e cache per gli altri dati. CarrierQueryBuilder locale esclude già deleted=0 e supporta i filtri della griglia: tradurre active in filters[enabled] e non trasferire ciecamente filter[id_zone]. getAvailableCarriers riceve idCart ma oggi non lo usa: /carriers/search-compatible-carriers (minVersion 9.2) richiede un contratto diverso e non va introdotto come sostituto implicito.

## Riferimenti al codice

- [src/Service/PS/Carrier.php:17](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Carrier.php#L17)
- [src/Service/PS/Carrier.php:79](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Carrier.php#L79)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Carrier/CarrierList.php`

## Criteri di accettazione

- [ ] Nessun troncamento oltre 50 corrieri, ordine per posizione e soli attivi/non eliminati.
- [ ] Definire come trattare il filtro zona richiesto dai chiamanti prima di rimuovere il legacy.
- [ ] Parità del payload pubblico e prova del checkout con i corrieri configurati; latenza/cache misurate.
- [ ] Eventuale ricerca di compatibilità carrello rimane una decisione distinta da questa migrazione.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Carrier)

### DZ-159 — [Admin API] Migrare GET /manufacturers per i dati standard

[DZ-159](https://linear.app/dolce-zampa/issue/DZ-159/admin-api-migrare-get-manufacturers-per-i-dati-standard) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Parziale: migrazione limitata ai campi core del marchio.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/manufacturers?display=full, filter[id] e filter[active]`

**Destinazione:** `GET /admin-api/manufacturers e GET /admin-api/manufacturers/{manufacturerId} per il dettaglio`

**Scope:** `manufacturer_read`

## Evidenze e lavoro richiesto

Mappare manufacturerId → id, enabled → active e campi localizzati di descrizione/SEO. La lista di griglia non espone tutto il dettaglio; prevedere idratazione con cache e paginazione. ManufactureEntity legge first_name, last_name, premium, link_rewrite, paese e avatar da dati estesi/database: non sono automaticamente coperti dalle Admin API. Questa issue migra solo il nucleo standard e preserva l'arricchimento esistente, senza migrare il modulo custom. Verificare anche i filtri active/enabled reali: ManufacturerList mappa esplicitamente solo manufacturerId.

## Riferimenti al codice

- [src/Service/PS/Brand.php:15](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Brand.php#L15)
- [src/Domain/Entities/ManufactureEntity.php:48](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/ManufactureEntity.php#L48)
- [src/Http/Controller/BrandController.php:19](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Http/Controller/BrandController.php#L19)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Manufacturer/Manufacturer.php`

## Criteri di accettazione

- [ ] Lista completa e filtro per ID/attivi corretti; nessun cambiamento a profili/artigiani e relativo arricchimento.
- [ ] Matrice campi standard/estesi e parità del payload esposto a BrandController.
- [ ] Slug, immagini e SEO mantenuti tramite sorgenti verificate; nessun campo mancante sostituito tacitamente da valori vuoti.
- [ ] Verifica runtime del DTO ManufacturerList, incluso il mapping productCount/productsCount, senza dipendere da campi non restituiti.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Manufacturer)

### DZ-160 — [Admin API] Migrare GET /combinations/{id}

[DZ-160](https://linear.app/dolce-zampa/issue/DZ-160/admin-api-migrare-get-combinationsid) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api), [DZ-152](https://linear.app/dolce-zampa/issue/DZ-152/admin-api-migrare-get-product-option-valuesid)

## Obiettivo e fattibilità

**Parziale: il dettaglio Admin non è il payload variante dello storefront.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/combinations/{id}?display=full&price[...]`

**Destinazione:** `GET /admin-api/products/combinations/{combinationId}, con GET /admin-api/products/{productId}/combinations per gli attributi`

**Scope:** `product_read`

## Evidenze e lavoro richiesto

Il dettaglio restituisce combinationId/productId, impatti sul prezzo, quantity, imageIds e reference. Il DTO non include tutte le associazioni product_option_values né minimal_quantity: la lista per prodotto espone attributes con attributeId/attributeGroupId. Ricostruire i riferimenti prima di OptionEntity. impactOnPriceTaxIncluded è un incremento, non il prezzo finale originale/scontato atteso dall'attuale codice. Occorre verificare prezzi specifici, IVA, quantità minima e combinazione predefinita; il solo nome della variante non sostituisce gli attributi strutturati.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:84](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L84)
- [src/Domain/Entities/CombinationEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/CombinationEntity.php)
- [src/Traits/ProductBuilder.php:104](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L104)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/Combination.php`

## Criteri di accettazione

- [ ] Parità di ID, attributi, default, reference, immagini e quantità minima usata dal consumer.
- [ ] Prove di prezzi per combinazione con impatto zero/positivo, sconto e IVA diversa; nessuna somma interpretata come prezzo finale senza verifica.
- [ ] Combinazioni oltre la prima pagina e attributi condivisi gestiti con cache/batching.
- [ ] Fallback legacy esplicito per i dati senza equivalente, fino a parità dimostrata.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-161 — [Admin API] Migrare GET /products/{id} per il dettaglio standard

[DZ-161](https://linear.app/dolce-zampa/issue/DZ-161/admin-api-migrare-get-productsid-per-il-dettaglio-standard) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api)

## Obiettivo e fattibilità

**Parziale: metadati core migrabili, dettaglio storefront completo non equivalente.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/products/{id}?display=full&price[...]`

**Destinazione:** `GET /admin-api/products/{productId}`

**Scope:** `product_read`

## Evidenze e lavoro richiesto

Mappare productId, names, descriptions/shortDescriptions, manufacturerId, categories/defaultCategoryId, linkRewrites/SEO e flag al contratto ProductEntity. Il payload corrente dipende da url aggiunta dall'override Product, original_price con riduzioni e regular_price senza riduzioni. priceTaxIncluded/Excluded Admin non dimostrano la stessa semantica. Il DTO analizzato non espone direttamente tutte le associazioni caratteristiche/accessori/personalizzazioni/varianti del Webservice. withFeatures aggiunge inoltre stock, bundle, configuratore e recensioni: conservarne le sorgenti esistenti. Questa issue riguarda la lettura standard (inclusi i prodotti accessori già identificati), non /catalog, /catalog/update o altre API custom.

## Riferimenti al codice

- [src/Service/PS/Product.php:375](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L375)
- [src/Service/PS/Product.php:156](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L156)
- [src/Service/PS/PrestashopService.php:135](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L135)
- [src/Domain/Entities/ProductEntity.php:126](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/ProductEntity.php#L126)
- [src/Traits/ProductManipulation.php:7](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductManipulation.php#L7)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/Product.php`

## Criteri di accettazione

- [ ] Matrice campo-per-campo e migrazione del sottoinsieme core con composizione/fallback espliciti per il resto.
- [ ] Parità di URL, prezzi regolare/scontato con IVA, prodotti semplici/varianti/bundle e disponibilità; nessuna IVA fissa.
- [ ] Snapshot/cache invalidati coerentemente e nessuna perdita silenziosa delle associazioni.
- [ ] Attivazione soltanto dopo confronto di payload e latenza su ambiente di test; dettaglio completo resta legacy dove manca parità.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-162 — [Admin API] Migrare GET /products per liste e conteggi

[DZ-162](https://linear.app/dolce-zampa/issue/DZ-162/admin-api-migrare-get-products-per-liste-e-conteggi) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api), [DZ-161](https://linear.app/dolce-zampa/issue/DZ-161/admin-api-migrare-get-productsid-per-il-dettaglio-standard)

## Obiettivo e fattibilità

**Parziale: la griglia Admin non fornisce una scheda prodotto completa.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/products?display=full oppure display=[id], filtri e sort`

**Destinazione:** `GET /admin-api/products`

**Scope:** `product_read`

## Evidenze e lavoro richiesto

Il DTO lista contiene solo productId/type/enabled/name/quantity/priceTaxExcluded/priceTaxIncluded/category. Lo storefront richiede anche URL, immagini, associazioni, produttore e prezzi regolari/scontati. Ricostruire tramite il dettaglio soltanto dopo averne risolto la parità e misurato il costo; non introdurre una GET per prodotto ad ogni richiesta utente. countProducts può usare totalItems solo per filtri equivalenti. La griglia predefinisce limit=20: tradurre esplicitamente paginazione/ordinamento/filtri, verificando in particolare id_category/id_manufacturer/date_add e i filtri locali sulle varianti.

## Riferimenti al codice

- [src/Service/PS/Product.php:230](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L230)
- [src/Service/PS/Product.php:243](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L243)
- [src/Service/PS/Product.php:322](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L322)
- [src/Domain/Object/Filter.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Object/Filter.php)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/ProductList.php`

## Criteri di accettazione

- [ ] Liste oltre 20 prodotti, conteggi corretti e ordinamento deterministico senza duplicati tra pagine.
- [ ] Matrice dei filtri effettivamente supportati; nessuna equivalenza implicita dei parametri Webservice.
- [ ] Parità di immagini, URL, produttore e prezzi/promozioni nel payload pubblico.
- [ ] Cache, numero di chiamate e latenza confrontati; mantenere il percorso legacy per le liste senza parità.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-163 — [Admin API] Valutare e migrare GET /search solo con parità storefront

[DZ-163](https://linear.app/dolce-zampa/issue/DZ-163/admin-api-valutare-e-migrare-get-search-solo-con-parita-storefront) · Dipende da [DZ-149](https://linear.app/dolce-zampa/issue/DZ-149/admin-api-introdurre-client-oauth2-e-selezione-del-trasporto-per-api), [DZ-161](https://linear.app/dolce-zampa/issue/DZ-161/admin-api-migrare-get-productsid-per-il-dettaglio-standard)

## Obiettivo e fattibilità

**Condizionata: ricerca amministrativa diversa da ricerca storefront.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/search?query={query}&language=1&display=full`

**Destinazione:** `GET /admin-api/products/search?phrase=...&resultsLimit=...&isoCode=EUR`

**Scope:** `product_read`

## Evidenze e lavoro richiesto

La ricerca Webservice usa Search::find e può restituire prodotti e categorie; il client attuale legge il contratto products. FoundProduct Admin espone productId/name/prezzi/stock/combinations/customizationFields con parametri phrase, resultsLimit e isoCode, più orderId facoltativo. Verificare algoritmi, lingua, prodotti disattivati/non visibili e regole di prezzo: la ricerca per creare ordini in back office non è automaticamente una ricerca pubblica. Le risorse SearchAlias e SearchEngine non sono sostituti di questa API.

## Riferimenti al codice

- [src/Service/PS/Product.php:448](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Product.php#L448)
- `../dolcezampa-main/classes/webservice/WebserviceSpecificManagementSearch.php:75`
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/FoundProduct.php`

## Criteri di accettazione

- [ ] Confronto di risultati/rilevanza su nomi, SKU, accenti e query senza risultati.
- [ ] Nessuna esposizione pubblica di prodotti disattivati/non visibili o campi amministrativi.
- [ ] Adapter al payload prodotto esistente e verifica valuta/IVA/riduzioni.
- [ ] Se la parità semantica non è dimostrata, mantenere /api/search e documentare la migrazione come rinviata; nessuna migrazione del modulo custom.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-164 — [Admin API][Gap] Rinviare GET /stock_availables fino a copertura equivalente

[DZ-164](https://linear.app/dolce-zampa/issue/DZ-164/admin-apigap-rinviare-get-stock-availables-fino-a-copertura)

## Obiettivo e fattibilità

**Non migrabile integralmente con le risorse analizzate.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/stock_availables?filter[id_product]={id}&display=full`

**Destinazione:** `Nessuna risorsa Admin equivalente trovata in ps_apiresources 0.9.0 locale`

## Evidenze e lavoro richiesto

Prodotto e combinazione Admin espongono quantity, e Product espone outOfStockType, ma non sostituiscono la collezione stock_availables con identificatori, relazioni prodotto/variante, shop/shop group e altri campi. Non confondere disponibilità numerica con l'intero contratto. Tenere il Webservice standard per questa lettura e registrare il gap; nessuna creazione di endpoint custom in questo progetto.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:179](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L179)
- [src/Domain/Entities/StockAvailableEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/StockAvailableEntity.php)
- [src/Traits/ProductBuilder.php:210](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Traits/ProductBuilder.php#L210)
- `../dolcezampa-main/modules/ps_apiresources/src/ApiPlatform/Resources/Product/Product.php`

## Criteri di accettazione

- [ ] Verificare nello schema runtime l'eventuale disponibilità di nuove risorse rispetto allo snapshot locale.
- [ ] Prima di riprendere la migrazione, dimostrare parità dei campi effettivamente consumati, stock per variante e contesto shop.
- [ ] Il dettaglio prodotto continua a ricevere le informazioni stock dal percorso esistente fino a sostituzione verificata.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

## Fonti ufficiali

- [API Resources v0.9.0 — riferimento upstream (verificare differenze con copia locale)](https://github.com/PrestaShop/ps_apiresources/tree/v0.9.0/src/ApiPlatform/Resources/Product)

### DZ-165 — [Admin API][Gap] Rinviare GET /product_customization_fields

[DZ-165](https://linear.app/dolce-zampa/issue/DZ-165/admin-apigap-rinviare-get-product-customization-fields)

## Obiettivo e fattibilità

**Non migrabile integralmente; dati parziali nella ricerca Admin.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/product_customization_fields?filter[id_product]={id}&display=full`

**Destinazione:** `Nessuna risorsa dedicata equivalente trovata nello snapshot analizzato`

## Evidenze e lavoro richiesto

product_customization_fields è una risorsa standard del Webservice, non una route del modulo webserviceapi. Non è stata trovata una risorsa Admin dedicata con lo stesso filtro e contratto. FoundProduct contiene customizationFields nei risultati della ricerca, ma non dimostra una lettura completa e deterministica per productId. Il consumer esclude inoltre alcune note is_module del configuratore: preservare questa regola senza migrare il configuratore custom.

## Riferimenti al codice

- [src/Service/PS/PrestashopService.php:204](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/PrestashopService.php#L204)
- [src/Domain/Entities/CustomizationEntity.php](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Domain/Entities/CustomizationEntity.php)
- `../dolcezampa-main/classes/webservice/WebserviceRequest.php:319`

## Criteri di accettazione

- [ ] Documentare campi/tipi/nomi localizzati effettivamente richiesti e disponibilità nello schema installato.
- [ ] Mantenere la lettura Webservice finché non esiste una risorsa standard equivalente confermata.
- [ ] Riprendere solo con test su campi testo/file/obbligatori e regola esistente per note del configuratore; nessun nuovo endpoint custom.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

### DZ-166 — [Admin API][Gap] Rinviare GET /content_management_system

[DZ-166](https://linear.app/dolce-zampa/issue/DZ-166/admin-apigap-rinviare-get-content-management-system)

## Obiettivo e fattibilità

**Non migrabile con le risorse analizzate.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/content_management_system?display=[id,meta_title,link_rewrite]`

**Destinazione:** `Nessuna risorsa CMS Page di lista trovata nello snapshot analizzato`

## Evidenze e lavoro richiesto

Nel modulo API Resources e nelle risorse core analizzate non è presente una lista CMS Page. Il dominio CQRS CmsPage nel core non implica che esista un endpoint Admin API. SearchEngine/SearchAlias non sostituiscono le pagine CMS. Conservare l'API legacy per le pagine informative; Cms::toPrestashop('/password-reset') è custom ed escluso.

## Riferimenti al codice

- [src/Service/PS/Cms.php:8](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Cms.php#L8)
- [src/Http/Controller/CmsController.php:19](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Http/Controller/CmsController.php#L19)
- `../dolcezampa-main/classes/webservice/WebserviceRequest.php:304`

## Criteri di accettazione

- [ ] Confermare il gap sullo schema runtime e registrare versione/data della verifica.
- [ ] Prima della migrazione futura verificare id, meta_title, link_rewrite, lingua e visibilità.
- [ ] Nessuna modifica al flusso password reset o creazione di API custom in questo progetto.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

### DZ-167 — [Admin API][Gap] Rinviare GET /content_management_system/{id}

[DZ-167](https://linear.app/dolce-zampa/issue/DZ-167/admin-apigap-rinviare-get-content-management-systemid)

## Obiettivo e fattibilità

**Non migrabile con le risorse analizzate.** Analisi statica del 5 novembre 2026: backend al commit `3b947e4eabd3`, copia locale del negozio PrestaShop 9.2.0 e ps_apiresources 0.9.0. Schema pubblico provato con esito HTTP 404; nessuna parità runtime dichiarata.

**API attuale:** `GET /api/content_management_system/{id}`

**Destinazione:** `Nessuna risorsa CMS Page di dettaglio trovata nello snapshot analizzato`

## Evidenze e lavoro richiesto

Non è stata trovata un'operazione Admin API per leggere una pagina CMS per ID in ps_apiresources 0.9.0 o nelle risorse core locali. Mantenere il Webservice standard; il presente ticket traccia il prerequisito di copertura per una migrazione futura, non lo sviluppo di un endpoint di modulo.

## Riferimenti al codice

- [src/Service/PS/Cms.php:15](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Service/PS/Cms.php#L15)
- [src/Http/Controller/CmsController.php:27](https://github.com/Dolce-Zampa/WebService/blob/3b947e4eabd30b9a1486dca01025eeb3394063cb/src/Http/Controller/CmsController.php#L27)
- `../dolcezampa-main/classes/webservice/WebserviceRequest.php:304`

## Criteri di accettazione

- [ ] Confermare nello schema runtime l'assenza/presenza del dettaglio CMS prima di programmare l'implementazione.
- [ ] Quando disponibile, verificare contenuto HTML, SEO, lingua, pagina disattivata e 404 rispetto al contratto attuale.
- [ ] Il consumer delle pagine informative continua a funzionare tramite l'integrazione esistente.

## Confini

Esclusi gli endpoint del modulo webserviceapi e degli altri moduli custom; nessuna sostituzione globale di /api. Gli accessi diretti al database non rientrano in questa migrazione. Per i ticket con copertura parziale, l'abilitazione richiede parità dimostrata dei campi consumati e rollback per singola operazione.

