# 📋 Registro delle Azioni di Default (Standard Operating Procedures)

Questo documento definisce le **azioni di default** e i comportamenti standard da adottare all'interno del progetto. L'obiettivo è automatizzare le decisioni ripetitive e garantire la coerenza del codice e dei flussi di lavoro.

---

## 🛠️ 1. Flusso di Lavoro Git (Git Workflow)

Quando si avvia una nuova attività, l'azione di default prevede la creazione di un branch dedicato partendo da `main`.

*   **Naming Convention dei Branch:**
    *   ✨ Funzionalità: `feature/nome-funzionalita`
    *   🐛 Correzione Bug: `bugfix/descrizione-bug`
    *   🧹 Pulizia Codice: `chore/nome-attivita`
*   **Azione di Rilascio:** Ogni Pull Request (PR) deve richiedere l'approvazione di almeno **1 peer reviewer** e il superamento di tutti i test CI/CD prima del merge.

---

## 🧪 2. Gestione dei Test e Qualità del Codice

La qualità del software viene garantita tramite controlli automatici ad ogni commit o push.

*   **Test Automatici:** Eseguire `npm test` (o il comando equivalente del framework) prima di aprire una Pull Request.
*   **Copertura Minima (Coverage):** Il livello di copertura del codice deve rimanere pari o superiore all'**80%**.
*   **Linter & Formattazione:** L'azione di default pre-commit (tramite Husky/Prettier) formatta automaticamente il codice per evitare conflitti di stile.

---

## 🚨 3. Gestione degli Errori e dei Log

In caso di eccezioni o comportamenti imprevisti del sistema:

*   **Ambiente di Sviluppo (Dev):** Mostrare l'errore completo in console con lo stack trace per facilitare il debug.
*   **Ambiente di Produzione (Prod):** 
    *   Mostrare un messaggio generico all'utente ("*Si è verificato un errore, riprova più tardi*").
    *   Tracciare l'errore effettivo in modo silenzioso su **Sentry / Log Rocket** con livello di gravità `ERROR`.

---

## 💾 4. Politiche di Backup e Sicurezza

*   **Frequenza dei Backup:** I database di produzione eseguono un backup snapshot automatico ogni **24 ore** alle 02:00 UTC.
*   **Conservazione (Retention):** I backup giornalieri vengono conservati per un periodo di **30 giorni** prima dell'eliminazione definitiva.
*   **Credenziali:** Non inserire mai password o chiavi API nel codice. Utilizzare esclusivamente il file `.env` (da includere nel `.gitignore`).

---

## 📝 5. Convenzione dei Commit (Conventional Commits)

I messaggi di commit devono seguire lo standard internazionale per permettere la generazione automatica del Changelog:

*   `feat: aggiunta autenticazione a due fattori`
*   `fix: risolto crash al login su dispositivi mobile`
*   `docs: aggiornato il file README con le istruzioni di installazione`

---

## 🔄 Aggiornamento di questo documento
Se un'azione di default non è più efficiente, chiunque nel team può proporre una modifica aprendo una Pull Request su questo file.
