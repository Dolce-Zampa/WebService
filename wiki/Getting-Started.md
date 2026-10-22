# Getting Started

## Prerequisiti

- Docker
- [Task](https://taskfile.dev/#/installation)

## Avvio

Clonare il repository e avviare l'ambiente di sviluppo:

```sh
git clone https://github.com/Dolce-Zampa/WebService.git
cd WebService
task build:dev
```

Il README del progetto documenta anche i servizi di test FTP e MailHog, i comandi di build e l'esecuzione dei test PHP: [README](https://github.com/Dolce-Zampa/WebService/blob/master/README.md).

## Comandi Task

- `task build:dev`: installa e prepara l'applicazione per lo sviluppo.
- `task build`: installa e prepara l'applicazione base.

> I nomi dei container e le istruzioni per l'ambiente possono dipendere dalla configurazione locale. Consultare il README del repository per i dettagli aggiornati.
