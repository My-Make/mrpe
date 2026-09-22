# MRP Elettronica — Applicazione Web PHP/MySQL

## Requisiti
- PHP 8.0+ con estensioni: PDO, pdo_mysql, curl, fileinfo
- MySQL 5.7+ / MariaDB 10.3+
- Server web (Apache/Nginx) con supporto `.htaccess` (o configurazione equivalente)

## Installazione (nuovo database)
1. Copia tutta la cartella `mrp_app` sul tuo server (es. `/var/www/html/mrp`).
2. Crea il database importando lo schema:
   ```
   mysql -u root -p < db.sql
   ```
3. Apri `config.php` e imposta host, nome database, utente e password MySQL.
4. Rendi scrivibile la cartella `uploads` dal server web:
   ```
   chmod -R 755 uploads
   ```
5. Apri nel browser `http://tuo-dominio/mrp/install.php` e crea il primo utente amministratore.
6. Accedi da `auth/login.php`.

## Aggiornamento da un'installazione precedente
Se hai già un database creato con una versione precedente di questo schema (senza tipi componente configurabili, senza immagine, ecc.), **non reimportare `db.sql`** (cancellerebbe i dati). Esegui invece lo script di aggiornamento, dopo aver fatto un backup:
```
mysqldump -u utente -p mrp_elettronica > backup_prima_v2.sql
mysql -u utente -p mrp_elettronica < migration_v2.sql
```
Poi sostituisci tutti i file PHP con quelli di questa versione.

## Struttura del progetto
```
config.php              connessione al database
db.sql                  schema completo del database
install.php             creazione utente amministratore iniziale
includes/                header, footer, funzioni comuni
auth/                    login, logout
componenti/              anagrafica componenti/semilavorati (cuore dell'app)
bom/                     distinte base multilivello
magazzini/               magazzini e ubicazioni
lotti/                   tracciabilità lotti
giacenze/                consultazione e movimenti di magazzino
fornitori/               anagrafica fornitori + configurazione API distributori
ordini_acquisto/         ordini di acquisto e ricezione merce
utenti/                  gestione utenti (solo admin)
api/distributori.php     integrazione con le API dei distributori
```

## Copertura dei requisiti richiesti
- **Componenti/semilavorati**: codice, descrizione, fornitore, prezzo, documentazione PDF/JPG allegata (con versioning), quantità con **unità di misura multiple** e fattore di conversione verso l'unità base → `componenti/view.php`, tab "Unità di misura" e "Documentazione".
- **Tracciabilità codice fornitore + lotto**: modulo `lotti/`, ogni lotto è legato a fornitore, codice lotto fornitore e codice lotto interno univoco, con DDT e collegamento agli ordini di acquisto.
- **Interfaccia con grandi distributori** (Arrow, Avnet, DigiKey, RS, Mouser): tabella `distributori_api_config` + modulo `api/distributori.php`. È implementata per intero la chiamata a **Mouser** come esempio funzionante; per gli altri distributori sono predisposte le funzioni stub (`query_digikey`, `query_arrow`, `query_avnet`, `query_rs`) da completare con le rispettive credenziali API una volta ottenuto l'accesso developer — la struttura dati di ritorno è già uniforme e pronta all'uso.
- **Componenti alternativi**: tab dedicata nella scheda componente, relazione bidirezionale con livello di compatibilità (totale/parziale).
- **Gestione magazzini**: magazzini multipli con tipologia (materie prime, semilavorati, prodotti finiti, conto lavoro, quarantena) e ubicazioni interne (scaffale/colonna/ripiano).
- **BOM con livelli di semilavorati**: `bom/editor.php` permette righe che puntano a loro volta a semilavorati con propria distinta base; la funzione `esplodi_bom()` in `includes/functions.php` esegue l'esplosione ricorsiva multilivello calcolando il fabbisogno totale dei soli componenti elementari. È presente un controllo anti-ciclo per evitare riferimenti circolari.
- **Gestione delle revisioni**: sia sui componenti (tab "Revisioni") sia sulle BOM (stato bozza/attiva/obsoleta, duplicazione come nuova revisione mantenendo lo storico).
- **Giacenze e lead time**: giacenze per magazzino/ubicazione/lotto, movimenti storicizzati (carico/scarico/trasferimento/rettifica), lead time per componente e per singolo fornitore, punto di riordino.
- **Utenti admin/user**: ruoli gestiti in `utenti/`, funzioni `require_admin()` / `require_login()` proteggono le pagine sensibili (gestione utenti, configurazione API, cambio stato BOM).

## Funzionalità aggiuntive che ho incluso
Oltre a quanto richiesto, ho aggiunto alcune funzioni che in un MRP reale per un'azienda elettronica risultano quasi sempre necessarie:

1. **Ordini di acquisto** (`ordini_acquisto/`) — creazione ordine multi-riga verso un fornitore, stato ordine (bozza → inviato → confermato → parziale/ricevuto), e **ricezione merce guidata**: ricevere una riga d'ordine genera automaticamente un nuovo lotto tracciato e il relativo carico di magazzino, così l'operatore non deve inserire due volte gli stessi dati.
2. **Dashboard con KPI** (`index.php`) — valore totale di magazzino, numero di componenti sotto il punto di riordino, ordini di acquisto aperti, ultimi movimenti, elenco componenti critici.
3. **Log attività / audit trail** (tabella `log_attivita`) — registra chi ha fatto cosa e quando (login, creazione/modifica componenti, movimenti, cambi di stato BOM), utile per rintracciare errori o modifiche non autorizzate.
4. **Scorta minima/massima e punto di riordino** per componente, usati dalla dashboard per segnalare automaticamente cosa riordinare.
5. **CSRF protection** su tutti i form che modificano dati, e password hashate con `password_hash()`/`password_verify()`.
6. **"Dove è usato" (where-used)** nella scheda componente: mostra in quali distinte base è impiegato un dato componente — indispensabile prima di modificarlo o dismetterlo.
7. **Controllo anti-ciclo nelle BOM**: impedisce di creare distinte base che si richiamano circolarmente (es. A contiene B che contiene A), un errore molto comune e difficile da individuare manualmente.

## Idee per estensioni future (non implementate, ma lo schema è già pronto)
- **Ordini di produzione / Work Order**: consumo automatico dei componenti da BOM con scarico multi-livello e carico del semilavorato/prodotto finito risultante.
- **Calcolo MRP vero e proprio** (net requirement planning): confronto fabbisogno da ordini cliente/produzione pianificata vs. giacenze e ordini di acquisto aperti, con generazione automatica di proposte d'ordine.
- **Notifiche via email** per componenti sotto scorta o ordini in ritardo rispetto al lead time.
- **Etichette con QR code/barcode** per lotti e ubicazioni, per la tracciabilità fisica con lettore barcode in magazzino.
- **Export CSV/Excel** di componenti, giacenze e BOM.
- **API REST** dell'applicazione stessa, per integrarla con un ERP o un MES esterno.

## Note di sicurezza prima di andare in produzione
- Cambia subito la password dell'amministratore creato con `install.php` se il server è condiviso con altri.
- Imposta `FORCE_HTTPS_COOKIE` a `true` in `config.php` quando il sito gira sotto HTTPS.
- Le API key dei distributori inserite in `fornitori/api_config.php` sono salvate in chiaro nel database in questa versione: per un ambiente di produzione valuta di cifrarle (es. con `sodium_crypto_secretbox`) prima di salvarle.
- Il modulo `api/distributori.php` chiama Mouser via `curl`: assicurati che il server abbia accesso in uscita a internet e che l'estensione `curl` di PHP sia attiva.

## Funzionalità aggiunte più di recente
- **Immagine componente**: nella scheda componente puoi caricare una foto/immagine principale (JPG, PNG, WEBP), visibile nell'elenco, nella scheda e sostituibile o rimovibile dal form di modifica.
- **Allegati estesi**: la sezione "Documentazione" ora accetta, oltre a PDF/JPG/PNG, anche DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, ZIP e file CAD (DWG, STEP/STP) — utile per allegare non solo datasheet ma anche disegni, fogli di calcolo, capitolati, ecc.
- **Sezione di Amministrazione** (`admin/`, solo per utenti admin): permette di configurare senza toccare il database:
  - **Tipi componente** — non più un elenco fisso, ma configurabile: puoi creare tipi personalizzati (es. "Materiale di consumo", "Accessorio") oltre a Componente/Semilavorato/Prodotto finito, indicando per ciascuno se può avere una propria distinta base (flag `ha_distinta_base`, usato dal motore di esplosione BOM) e quale sia il tipo proposto di default per i nuovi componenti.
  - **Categorie** — creazione/modifica/eliminazione delle categorie merceologiche, con gerarchia opzionale (categoria padre) e categoria predefinita.
  - **Unità di misura** — creazione/modifica/eliminazione delle unità disponibili e scelta di quella predefinita, proposta automaticamente nei nuovi componenti.

  In tutti e tre i casi l'eliminazione è bloccata se il valore è già usato da qualche componente, per evitare di rompere i dati esistenti (si può comunque disattivare un tipo componente senza eliminarlo).

## Migrazione (importante se aggiorni da una versione precedente)
Il campo `tipo` dei componenti (prima un ENUM fisso) è stato sostituito da `tipo_componente_id`, collegato alla nuova tabella configurabile `tipi_componente`. Usa `migration_v2.sql` per aggiornare un database esistente senza perdere dati (vedi sezione "Aggiornamento" sopra). Il codice PHP che si occupa dell'esplosione multilivello della BOM (`esplodi_bom()` in `includes/functions.php`) ora si basa sul flag `ha_distinta_base` del tipo, non più sul nome del tipo, quindi continua a funzionare anche con tipi personalizzati creati da amministrazione.

## Ultimo aggiornamento
- **Eliminazione componente**: nella scheda componente (solo admin) è ora possibile disattivare un componente (nascosto dagli elenchi, dati storici intatti) oppure eliminarlo definitivamente. L'eliminazione è consentita solo se la giacenza totale è a zero; se il componente è comunque referenziato altrove (distinta base, lotti, movimenti, ordini) l'eliminazione viene bloccata a protezione dello storico, suggerendo la disattivazione.
- **Causali di movimento configurabili**: nuova sezione "Amministrazione → Causali movimento". Nel form di registrazione movimento (`giacenze/movimento.php`) il campo causale è ora una select filtrata automaticamente in base al tipo di movimento scelto, con un'opzione "Altro" per inserire testo libero quando serve.
- **Campo Tecnologia**: nuovo attributo anagrafico del componente (es. SMD, THT), configurabile in "Amministrazione → Tecnologie" con relativo valore di default. Seminato di default con SMD e THT.

Ricorda di eseguire `migration_v2.sql` (è idempotente, puoi rilanciarlo anche se l'hai già eseguito prima) per aggiornare un database esistente con queste ultime modifiche.

## Permessi granulari per utente (ultimo aggiornamento)
Dalla scheda utente (Utenti → Modifica, solo per utenti di ruolo "User" — gli amministratori hanno sempre accesso completo) è possibile configurare, **per ciascuna sezione** dell'app (Componenti, Distinte Base, Giacenze, Lotti, Magazzini, Fornitori, Ordini di Acquisto), uno di questi 3 livelli:
- **Nessuno**: la sezione è completamente nascosta (voce di menu assente, pagine bloccate anche via URL diretto).
- **Sola lettura**: l'utente può consultare la sezione ma non creare/modificare/eliminare nulla al suo interno — i pulsanti di modifica spariscono dall'interfaccia e ogni tentativo di scrittura è comunque bloccato lato server (centralizzato nella funzione `csrf_verify()`, richiamata da tutti i form dell'app).
- **Scrittura** (default se non configurato diversamente): accesso completo alla sezione.

La sola lettura NON è più un interruttore globale sull'utente: può essere impostata in modo diverso sezione per sezione (es. un utente può avere scrittura su Giacenze ma sola lettura su Componenti).

Nota: se modifichi i permessi di un utente mentre questo ha già una sessione attiva, le modifiche si applicano dal suo prossimo login, non in tempo reale sulla sessione già aperta.

La dashboard (`index.php`) resta visibile a tutti gli utenti loggati indipendentemente dai permessi per sezione, essendo una pagina di riepilogo generale.

Esegui `migration_v2.sql` (idempotente, rilanciabile) per applicare le nuove tabelle/colonne al tuo database — se avevi già eseguito la versione precedente di questo script (con la vecchia sola lettura globale), i dati esistenti vengono migrati automaticamente al nuovo sistema a 3 livelli.

## Gestione BOM: eliminazione e disattivazione (ultimo aggiornamento)
Nella scheda di una distinta base (`bom/editor.php`), sezione "Gestione distinta base" (solo admin):
- **Disattiva/Riattiva BOM**: nasconde la distinta base dall'uso operativo (esclusa dall'esplosione multilivello anche se il suo stato fosse ancora "attiva") senza eliminarla — dati e storico restano intatti.
- **Elimina definitivamente**: consentito solo se la distinta base non contiene più righe (BOM vuota); altrimenti il pulsante resta disabilitato con l'indicazione del motivo.

## Documentazione: link esterni e visualizzazione inline (ultimo aggiornamento)
Nella tab "Documentazione" della scheda componente:
- Oltre al caricamento di file, ora puoi aggiungere **uno o più link esterni** (es. pagina prodotto del fornitore, datasheet online) tramite l'apposito form "Aggiungi un link". Ogni link è mostrato con icona dedicata e si apre in una nuova scheda.
- Per i file **visualizzabili nel browser** (PDF, JPG, PNG, WEBP, TXT) è comparso un pulsante **"Visualizza"** che apre il file inline senza scaricarlo, tramite il nuovo script `componenti/visualizza_documento.php` (imposta esplicitamente `Content-Disposition: inline`, così funziona indipendentemente dalla configurazione del server). Il pulsante di download (icona 🡇) resta disponibile separatamente per chi vuole comunque salvare il file.

## Backup database (ultimo aggiornamento)
Nuova sezione **Amministrazione → Backup database**, che esporta l'intero database (struttura + dati) in un file `.sql` standard scaricabile dal browser, senza bisogno di accedere a phpMyAdmin o di avere `mysqldump` disponibile da riga di comando (spesso non accessibile su hosting condiviso). Il file generato è compatibile con qualunque client MySQL/phpMyAdmin per un eventuale ripristino.

Attenzione: il backup contiene tutti i dati, incluse le chiavi API dei distributori configurate in Fornitori — vanno conservati con cura.

## Multi-azienda (multi-tenant) — ultimo aggiornamento

L'applicazione supporta ora **più aziende separate sulla stessa installazione e sullo stesso database**:

- Nuovo ruolo **Super Admin** (`utenti.ruolo='superadmin'`, senza azienda): crea e gestisce le aziende, e il primo amministratore di ciascuna. Pannello dedicato in `superadmin/`, login separato (lascia vuoto il Codice Azienda nel form di login).
- Ogni azienda ha un **Codice Azienda** univoco: gli utenti (`admin`/`user`) accedono con Codice Azienda + Username + Password.
- **Tutte le tabelle dati** (componenti, magazzini, giacenze, movimenti, lotti, BOM, ordini di acquisto, fornitori, categorie, tipi componente, tecnologie, causali movimento, log attività, utenti) sono ora scoperte per azienda: ogni query dell'app filtra per l'azienda dell'utente collegato, e i vincoli di unicità dei codici sono passati da "univoci nel database" a "univoci per azienda".
- Il **backup completo del database** (prima in Amministrazione) è stato spostato nel pannello Super Admin, perché include necessariamente i dati di tutte le aziende insieme.

### Installazione da zero
1. Importa `db.sql`
2. Apri `install.php` e crea il primo **Super Admin**
3. Accedi (Codice Azienda vuoto) e vai in **Aziende** per creare la prima azienda con il suo amministratore
4. L'amministratore dell'azienda accede da quel momento con Codice Azienda + username + password

### Aggiornamento da un'installazione esistente (mono-azienda)
Esegui `migration_v3_aziende.sql` (idempotente). Crea automaticamente un'azienda "MMK001" e le assegna tutti i dati e gli utenti già presenti — **non perdi nulla**, ma dovrai:
1. Creare un Super Admin con `install.php`
2. Accedere come Super Admin e rinominare l'azienda "MMK001" con il nome reale (il codice azienda resta MMK001, salvo che tu lo cambi via database)
3. Comunicare ai tuoi utenti il Codice Azienda MMK001 da usare per accedere

### Limitazioni note
- I file allegati ai componenti (documenti caricati) condividono la stessa cartella `uploads/documenti/` per tutte le aziende. I nomi dei file sono generati in modo non predicibile (`uniqid()`), ma **non c'è un controllo di accesso a livello di file statico**: chi conosce/indovina l'URL esatto di un file può scaricarlo direttamente, bypassando il controllo per azienda applicato invece al visualizzatore inline (`componenti/visualizza_documento.php`, corretto in questo aggiornamento). Per un isolamento completo anche a livello di filesystem servirebbe instradare tutti i download attraverso uno script PHP con verifica azienda, non solo la visualizzazione inline.
- Alcuni riferimenti "secondari" tra entità (es. l'unità di misura scelta in una riga BOM o in un ordine di acquisto) non verificano esplicitamente che l'unità di misura appartenga alla stessa azienda: nella peggiore ipotesi porterebbe a mostrare il codice di un'unità di misura di un'altra azienda, non un accesso a dati sensibili.

## Aggiornamenti: case componente, ubicazione nei movimenti, validità (ultimo aggiornamento)
- **Campo "Case"** sul componente (es. SOT-23, 0805, DIP-8, TO-220...), nel form anagrafica e nella tab Anagrafica della scheda.
- **Locazione di magazzino nei movimenti**: il form di registrazione movimento (`giacenze/movimento.php`) ha ora una select "Ubicazione", popolata dinamicamente in base al magazzino scelto.
- **Categorie ordinate per codice** anche nell'elenco di Amministrazione → Categorie (oltre ai dropdown, già sistemati in precedenza).
- **Codice fornitore modificabile in linea** nella tab Fornitori della scheda componente: il campo è editabile direttamente nella tabella, con un pulsante di salvataggio accanto.
- **Le operazioni nelle tab riportano alla stessa tab dopo il salvataggio** (scheda componente): niente più ritorno automatico alla tab Anagrafica dopo ogni azione.
- **Date di validità (dal / al)** aggiunte sia al componente (form anagrafica, tab Anagrafica) sia alla distinta base (creazione BOM, e un form dedicato per modificarle successivamente nella sezione "Stato revisione").

Esegui `migration_v4.sql` (idempotente) per applicare le nuove colonne al database.

## Correzioni e aggiunte (ultimo aggiornamento)

- **Ritorno alla tab corretta — ora funziona davvero**: il meccanismo precedente si basava su un hash URL gestito da JavaScript (soggetto a problemi di ordine di caricamento degli script). È stato sostituito con un approccio interamente lato server: la tab attiva viene passata come parametro `?tab=...` e la pagina decide direttamente in PHP quali classi `active`/`show` applicare, senza alcuna dipendenza da JavaScript.
- **MOQ recuperato dalle API distributore**: oltre a prezzo, disponibilità e lead time, ora viene salvato anche il MOQ (quantità minima ordinabile) quando il distributore lo restituisce (Mouser, DigiKey, Farnell/element14, Arrow).
- **Riordino campi form componente**: "Case" spostato subito dopo "Tecnologia"; "Immagine componente" spostata in fondo al form, dopo Specifiche e Note.
- **Log di modifica**: componenti e distinte base ora tracciano anche "modificato da / il" (oltre a "creato da / il" già presente), aggiornato automaticamente ad ogni operazione riuscita sulla relativa scheda.

Esegui `migration_v5.sql` (idempotente) per applicare le nuove colonne al database.

## Link pagina prodotto e Prezzo x MOQ (ultimo aggiornamento)

Nella tab Fornitori della scheda componente:
- **Link alla pagina prodotto**: per Mouser e DigiKey viene salvato il link diretto restituito dalla loro stessa API (`ProductDetailUrl` per Mouser, `ProductUrl` per DigiKey) quando aggiorni i dati con il pulsante ⟳ — il più affidabile possibile, perché non è costruito a mano. Per Farnell/element14 e Arrow (che non restituiscono questo campo nella risposta) viene invece generato un link di ricerca sul sito del distributore col codice fornitore. Il link compare come icona 🔗 accanto al pulsante di aggiornamento API.
- **Colonna "Prezzo × MOQ"**: calcola il costo del quantitativo minimo ordinabile, utile per un confronto rapido tra fornitori.

Anche il MOQ di Farnell (dall'aggiornamento precedente) è stato corretto: il campo reale nella risposta API si chiama `translatedMinimumOrderQuality` (con "Quality", non "Quantity" — è così nell'API stessa, verificato).

Esegui `migration_v5.sql` (idempotente, la stessa dell'aggiornamento precedente — rilanciabile senza problemi) per applicare la nuova colonna `url_prodotto`.
