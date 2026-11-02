# Configurazione checkout InPost tramite Packlink

Il checkout InPost di questo modulo usa **solo Packlink**. Non inserire chiavi API, token OAuth o credenziali InPost: non sono necessari e non vengono letti dal modulo.

## Attivare un servizio

1. Nel back office aprire **Moduli > Packlink PRO Shipping > Configura**.
2. Nella sezione **Packlink × InPost — configurazione checkout**, individuare il servizio InPost nella tabella dei servizi Packlink disponibili per il ritiro.
3. Copiare il relativo **riferimento corriere PrestaShop** e l'**ID metodo Packlink**, quindi salvarli nel modulo.

Il modulo accetta la coppia solo quando il riferimento corriere esiste, il metodo è esattamente quello mappato da Packlink e il servizio è attivo per la consegna presso punto di ritiro. La configurazione è salvata dal modulo con la chiave `PACKLINK_INPOST_CARRIER_METHODS` come oggetto JSON `{ "riferimentoCorriere": "metodoPacklink" }`.

## Disattivare

Usare **Disabilita** accanto all'associazione. Quando non resta alcuna associazione, l'endpoint interno del checkout non considera nessun carrier come InPost e non chiama Packlink per i punti di ritiro.

## Controllo operativo

Dopo il salvataggio, simulare un checkout con quel corriere: il selettore deve restituire solo i punti esposti da Packlink. Se il servizio non appare nella tabella, completare o riattivare prima la configurazione del metodo di spedizione Packlink.
