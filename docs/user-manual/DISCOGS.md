# Sincronizzazione Ordini Discogs

Il sistema è integrato con il marketplace Discogs e sincronizza automaticamente gli ordini ricevuti, creando vendite nel gestionale e aggiornando lo stock.

---

## Come funziona la sincronizzazione automatica

Il sistema esegue automaticamente la sincronizzazione degli ordini da Discogs **ogni 2 ore**.

**Processo automatico:**

1. **Scaricamento ordini**: Il sistema si connette a Discogs e scarica i nuovi ordini dal marketplace
2. **Identificazione record**: Per ogni ordine, identifica il record nel sistema tramite il **Listing ID** Discogs (campo `discogs_id`)
3. **Verifica stock**: Controlla la disponibilità dello stock nell'area specificata nell'annuncio
4. **Creazione vendita**: Crea automaticamente una vendita nel sistema con:
    - Tipo: Discogs
    - Cliente: Informazioni dell'acquirente da Discogs
    - Record venduto con quantità e prezzo dall'ordine
    - Area: Location da cui viene scalato lo stock
5. **Scalamento stock**: Decrementa lo stock dall'area specificata (o dalla prima disponibile)
6. **Rimozione annuncio**: Rimuove l'annuncio da Discogs (campo `discogs_id` viene cancellato)

---

## Gestione dello stock dopo un ordine

Dopo aver processato un ordine da Discogs, il sistema gestisce automaticamente lo stock e gli annunci:

**Se lo stock si esaurisce completamente:**

- L'annuncio viene rimosso da Discogs
- Il record non è più visibile sul marketplace
- Il campo `discogs_id` del record viene cancellato

**Se rimane stock in altre aree:**

- Il record viene **automaticamente ripubblicato** su Discogs
- Viene utilizzata la **prossima area disponibile** secondo l'ordine delle aree (order_column)
- L'annuncio viene creato con i dati aggiornati della nuova location
- Viene assegnato un nuovo `discogs_id`

**Esempio pratico:**

Record pubblicato su Discogs con stock:

- Area Magazzino: 1 pezzo (pubblicata su Discogs, Listing ID: 123456)
- Area Deposito: 5 pezzi

Viene ricevuto un ordine per 1 pezzo:

1. Sistema crea la vendita automaticamente
2. Stock Area Magazzino: 0 pezzi
3. Annuncio 123456 viene rimosso da Discogs
4. Record viene ripubblicato automaticamente dall'Area Deposito
5. Nuovo annuncio creato con Listing ID: 789012

---

## Visualizzare le vendite da Discogs

Le vendite generate dagli ordini Discogs sono visibili nella sezione **[Vendite](VENDITE.md)** del sistema.

**Caratteristiche delle vendite Discogs:**

- **Tipo**: Vendita contrassegnata come proveniente da Discogs
- **Cliente**: Nome e dati dell'acquirente ricevuti da Discogs
- **Record**: Include il Listing ID Discogs originale
- **Data**: Data e ora di ricezione dell'ordine

Per visualizzare queste vendite:

1. Andare su **Vendite** dal menu laterale
2. Utilizzare i filtri per cercare vendite di tipo "Discogs"
3. Visualizzare i dettagli cliccando sulla vendita

---

## Frequenza di sincronizzazione

- **Automatica**: La sincronizzazione avviene ogni 2 ore senza intervento manuale
- **Timestamp tracking**: Il sistema tiene traccia dell'ultima sincronizzazione per evitare di processare ordini duplicati
- **Ordini nuovi**: Vengono sincronizzati solo gli ordini ricevuti dopo l'ultimo controllo

---

## Gestione errori e notifiche

In caso di problemi durante la sincronizzazione, il sistema implementa le seguenti protezioni:

**Errori che bloccano il processamento di un ordine:**

- **Record non trovato**: Se il Listing ID non corrisponde a nessun record nel sistema
- **Stock non disponibile**: Se non c'è stock sufficiente per evadere l'ordine
- **Dati mancanti**: Se l'ordine ha informazioni incomplete

**Notifiche amministratori:**

- Quando si verifica un errore, viene inviata automaticamente una **email agli amministratori** con:
    - Dettagli dell'ordine che ha generato l'errore
    - Tipo di errore riscontrato
    - Informazioni per la risoluzione manuale

**Risoluzione manuale:**
Gli ordini che non possono essere processati automaticamente richiedono intervento manuale:

1. Verificare l'email di notifica ricevuta
2. Controllare il record nel sistema (stock, dati, ecc.)
3. Creare manualmente la vendita se necessario
4. Aggiornare lo stock di conseguenza

---

## Note importanti

- La sincronizzazione è completamente **automatica** e non richiede intervento dell'utente
- Gli ordini vengono processati **solo se il record ha stock disponibile**
- Il sistema previene la duplicazione degli ordini tramite il tracking dei timestamp
- La **priority delle aree** (order_column) determina da quale area viene ripubblicato il record dopo la vendita
- Per pubblicare manualmente un record su Discogs, consultare la sezione [Pubblicazione su Discogs in Records](RECORDS.md#pubblicazione-su-discogs)

---

## Collegamenti utili

- [**Discogs: Release ID e Listing ID**](DISCOGS_IDS.md) - Differenza tra i due identificativi e dove vengono salvati
- [**Records**](RECORDS.md) - Come pubblicare manualmente record su Discogs
- [**Vendite**](VENDITE.md) - Visualizzare e gestire le vendite (incluse quelle da Discogs)
- [**Carichi**](CARICHI.md) - Pubblicazione automatica tramite import Excel
- [**Locations**](LOCATIONS.md) - Differenza tra location Warehouse e Retail

---

[← Torna all'indice](README.md)
