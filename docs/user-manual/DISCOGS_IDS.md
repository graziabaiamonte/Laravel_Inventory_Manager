# Discogs: Release ID e Listing ID — chi è chi

Discogs usa **due identificativi diversi**. Confonderli è la causa più comune degli errori di pubblicazione (record che risultano "in vendita" nel gestionale ma restituiscono 404 su Discogs). Questa pagina è la fonte unica di verità: dove si usa ciascuno, dove viene salvato, cosa deve inserire l'utente e cosa gestisce automaticamente l'app.

---

## I due identificativi

| | **Release ID** | **Listing ID** |
|---|---|---|
| Cosa identifica | La **release** nel catalogo Discogs (il disco/edizione), condivisa da tutti i venditori | Il **singolo annuncio** di vendita sul marketplace (specifico del nostro account) |
| Campo nel database | `records.release_id` | `records.discogs_id` |
| URL Discogs | `discogs.com/release/{release_id}` | `discogs.com/sell/item/{listing_id}` |
| Chi lo fornisce | **L'utente** (cercandolo su Discogs) | **Discogs**, come risposta quando creiamo l'annuncio |
| Si inserisce a mano? | **Sì** | **No, mai** |

> ⚠️ **Attenzione al nome del campo.** La colonna del database `discogs_id` contiene il **Listing ID** (l'ID dell'annuncio), **non** un generico "ID Discogs". Non inserire mai un Release ID in questo campo.

---

## Chi produce cosa

- Il **Release ID** è un **input**: lo forniamo noi. Serve a dire a Discogs *quale disco* stiamo mettendo in vendita.
- Il **Listing ID** (`discogs_id`) è un **output**: lo restituisce Discogs quando l'annuncio viene creato con successo. Da quel momento identifica *il nostro annuncio* e viene usato per aggiornarlo, rimuoverlo e per abbinare gli ordini in arrivo.

Regola pratica: **si inserisce solo il Release ID. Il Listing ID compare da solo quando la pubblicazione è andata a buon fine.**

---

## Flusso di pubblicazione (quando creiamo un annuncio)

Un record viene pubblicato su Discogs quando **tutte** queste condizioni sono vere:

1. `for_sale_on_discogs = 1` ("In vendita")
2. Ha un **Release ID** valido (`release_id`)
3. Ha stock disponibile (> 0)
4. **Non ha già un** `discogs_id` (cioè non è già pubblicato)

Se le condizioni sono soddisfatte, l'app:

1. Invia a Discogs (`POST /marketplace/listings`) un annuncio costruito con il **`release_id`**, prezzo, condizioni, location e stock
2. Riceve in risposta il **`listing_id`**
3. Salva quel valore in **`records.discogs_id`**
4. Solo a questo punto il record risulta effettivamente pubblicato

L'indicatore verde e il messaggio "Listed on Discogs: ID …" nella scheda record derivano dalla presenza di `discogs_id`. Poiché `discogs_id` viene valorizzato **solo** dalla risposta di Discogs, la sua presenza dovrebbe corrispondere a un annuncio realmente esistente.

---

## Flusso ordini (quando riceviamo una vendita)

Alla sincronizzazione automatica degli ordini (ogni 2 ore), ogni articolo dell'ordine Discogs viene abbinato al record tramite il **Listing ID**: `Record::where('discogs_id', listing_id)`. Per questo il `discogs_id` deve corrispondere sempre a un annuncio reale del nostro account. Dettagli in [Sincronizzazione Ordini Discogs](DISCOGS.md).

---

## Ciclo di vita di `discogs_id`

- **Impostato** dall'app solo dopo una creazione annuncio riuscita (mai a mano, mai da import)
- **Usato** per leggere/aggiornare/eliminare l'annuncio (`/marketplace/listings/{discogs_id}`) e per abbinare gli ordini
- **Cancellato** (riportato a vuoto) quando lo stock arriva a zero o l'annuncio viene rimosso
- **Rigenerato** (nuovo valore) quando il record viene ripubblicato dalla prossima area disponibile

---

## Cosa legge ciascun import Excel

I due import **non** hanno le stesse colonne. È importante non confonderli:

| Import | Colonne Excel Discogs | Dove finisce | Note |
|---|---|---|---|
| **Records** (import dischi) | `release_id (discogs)` | `records.release_id` | Si inserisce il Release ID. `for_sale_on_discogs` è derivato automaticamente |
| **Carichi** (wholesale in) | `for_sale_on_discogs`, `release_id (discogs)`, `discogs_id` | `records.for_sale_on_discogs`, `records.release_id` | Il Release ID importato consente di pubblicare subito un record nuovo. La colonna `discogs_id` è solo di convenzione: il suo valore **non viene salvato** (vedi sotto) |

Entrambi gli import usano quindi la stessa colonna **`release_id (discogs)`** per il Release ID, salvato in `records.release_id`. Nei Carichi questa colonna permette di pubblicare un record nuovo direttamente dall'import, senza doverlo prima aprire in modifica.

**Convenzione della colonna `discogs_id` nei Carichi.** Nel template dei Carichi esiste anche una colonna `discogs_id` che, per convenzione, indica il **Listing ID** dell'annuncio. Il valore inserito **non viene importato nel database**: l'app scrive `records.discogs_id` esclusivamente quando crea un annuncio e Discogs conferma il Listing ID. Un eventuale valore in colonna viene quindi **ignorato** (e registrato nei log). Questo garantisce che un record risulti "pubblicato" solo a fronte di un annuncio reale.

> ⚠️ **Perché è così (i vecchi 404).** In passato il valore della colonna `discogs_id` veniva salvato così com'era. Inserendovi un Release ID (pensando fosse "l'ID Discogs" del disco), l'app credeva che l'annuncio esistesse già (`already_has_discogs_id`), **saltava la creazione dell'annuncio** e mostrava comunque il record come pubblicato → il link `sell/item/{valore}` dava **404**. Ora il valore della colonna `discogs_id` non viene più salvato: il Release ID va indicato nella colonna `release_id (discogs)`, da cui l'app crea un annuncio reale.

---

## In sintesi

- **Release ID** = quale disco → lo inserisci tu → salvato in `release_id` → serve a **creare** l'annuncio
- **Listing ID** = quale annuncio → lo assegna Discogs → salvato in `discogs_id` → serve a **gestire** annuncio e ordini
- Non scrivere mai un valore in `discogs_id` a mano o via import

---

## Collegamenti utili

- [**Records**](RECORDS.md) — Pubblicazione manuale di un record su Discogs
- [**Carichi**](CARICHI.md) — Pubblicazione tramite import Excel
- [**Discogs**](DISCOGS.md) — Sincronizzazione automatica degli ordini

---

[← Torna all'indice](README.md)
