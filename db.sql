-- ============================================================
-- MRP Elettronica - Schema Database MySQL (multi-azienda)
--
-- ARCHITETTURA MULTI-TENANT:
-- Una sola installazione (stesso codice, stesso database) può
-- servire più aziende diverse, con dati completamente separati.
-- Ogni tabella "dati" ha una colonna azienda_id; ogni query
-- dell'applicazione filtra sempre per l'azienda dell'utente
-- collegato (vedi includes/functions.php -> azienda_id()).
--
-- Il SUPERADMIN (utenti.ruolo='superadmin', utenti.azienda_id NULL)
-- non appartiene a nessuna azienda: gestisce l'elenco aziende e
-- crea gli utenti admin di ciascuna, ma non vede i dati operativi
-- (componenti, magazzini, ordini...) di nessuna azienda.
--
-- Lo username degli utenti resta univoco a livello globale (non per
-- azienda) per semplicità: due aziende diverse non possono avere un
-- utente con lo stesso username.
-- ============================================================
CREATE DATABASE IF NOT EXISTS mrp_elettronica CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mrp_elettronica;

-- ------------------------------------------------------------
-- AZIENDE
-- ------------------------------------------------------------
CREATE TABLE aziende (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice_azienda VARCHAR(30) NOT NULL UNIQUE,   -- codice usato dagli utenti per accedere (es. ACME01)
    ragione_sociale VARCHAR(150) NOT NULL,
    indirizzo VARCHAR(255) NULL,
    piva VARCHAR(30) NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(150),
    logo VARCHAR(500) NULL,           -- immagine originale caricata (per l'anteprima)
    logo_jpeg VARCHAR(500) NULL,      -- copia convertita in JPEG (per l'incorporazione nei PDF)
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- UTENTI
-- ------------------------------------------------------------
CREATE TABLE utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NULL,                          -- NULL solo per il ruolo 'superadmin'
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    ruolo ENUM('superadmin','admin','user') NOT NULL DEFAULT 'user',
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    usa_2fa TINYINT(1) NOT NULL DEFAULT 0,    -- verifica in due passaggi via email al login
    codice_2fa VARCHAR(10) NULL,
    codice_2fa_scadenza DATETIME NULL,
    ultimo_accesso DATETIME NULL,
    lingua VARCHAR(5) NOT NULL DEFAULT 'it',  -- lingua preferita dell'interfaccia (it, en, ...)
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Permessi per sezione dell'applicazione, per un utente specifico (di ruolo 'user').
-- livello: 'nessuno' (sezione nascosta/bloccata), 'lettura' (solo consultazione),
-- 'scrittura' (accesso completo). L'assenza di una riga per una data sezione
-- significa "scrittura" (accesso completo di default); gli amministratori non
-- sono mai soggetti a queste restrizioni.
CREATE TABLE utenti_permessi_sezioni (
    utente_id INT NOT NULL,
    sezione VARCHAR(50) NOT NULL,
    livello ENUM('nessuno','lettura','scrittura') NOT NULL DEFAULT 'scrittura',
    PRIMARY KEY (utente_id, sezione),
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- UNITA' DI MISURA
-- ------------------------------------------------------------
CREATE TABLE unita_misura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(10) NOT NULL,             -- es. PZ, MT, KG, RL (rotolo), CF (confezione)
    descrizione VARCHAR(100) NOT NULL,
    is_predefinita TINYINT(1) NOT NULL DEFAULT 0,   -- unità proposta di default nei nuovi componenti
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_um_azienda (azienda_id, codice)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- FORNITORI (anche distributori)
-- ------------------------------------------------------------
CREATE TABLE fornitori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    ragione_sociale VARCHAR(150) NOT NULL,
    codice VARCHAR(30),
    -- Dati generali e identificativi
    codice_fiscale VARCHAR(20),
    piva VARCHAR(30),
    indirizzo VARCHAR(255),           -- via e numero civico (sede legale)
    cap VARCHAR(10),
    citta VARCHAR(100),
    provincia VARCHAR(10),
    nazione VARCHAR(100) DEFAULT 'Italia',
    sede_operativa VARCHAR(255),      -- indirizzo operativo, solo se diverso dalla sede legale
    telefono VARCHAR(30),
    email VARCHAR(150),
    pec VARCHAR(150),
    sito_web VARCHAR(255),
    referente VARCHAR(100),
    -- Dati amministrativi e contabili
    iban VARCHAR(34),
    bic_swift VARCHAR(11),
    condizioni_pagamento VARCHAR(100),   -- es. "30 gg fine mese"
    metodo_pagamento VARCHAR(50),        -- es. "Bonifico bancario"
    regime_iva VARCHAR(100),             -- es. "Regime forfettario", "Split payment"
    ritenuta_acconto VARCHAR(50),        -- es. "20%", vuoto se non soggetto
    iscrizione_enti VARCHAR(150),        -- es. "Enasarco", cassa previdenziale
    -- Dati di acquisto e commerciali
    reparto_acquisti VARCHAR(150),       -- reparti/tipologie di beni o servizi forniti
    valuta VARCHAR(5) DEFAULT 'EUR',     -- valuta di riferimento per ordini/fatture
    categoria_merceologica VARCHAR(150), -- settore di appartenenza
    is_distributore TINYINT(1) NOT NULL DEFAULT 0,   -- true se è un grande distributore (Mouser, Digikey...)
    note TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_fornitore_azienda (azienda_id, codice)
) ENGINE=InnoDB;

-- Configurazione API per i distributori (Arrow, Avnet, Digikey, RS, Mouser, Farnell...)
CREATE TABLE distributori_api_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fornitore_id INT NOT NULL,
    nome_distributore VARCHAR(50) NOT NULL,          -- 'mouser','digikey','arrow','avnet','farnell','rs'
    api_base_url VARCHAR(255),
    api_key VARCHAR(255),
    api_secret VARCHAR(255),
    client_id VARCHAR(255),
    store_id VARCHAR(50),                            -- sito/paese di riferimento (es. Farnell: it.farnell.com, uk.farnell.com, newark.com...)
    token_oauth TEXT,
    token_scadenza DATETIME NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (fornitore_id) REFERENCES fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- COMPONENTI / SEMILAVORATI
-- ------------------------------------------------------------
CREATE TABLE categorie_componenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(30) NULL,                  -- codice breve opzionale (es. RES, CAP, CONN...)
    nome VARCHAR(100) NOT NULL,
    categoria_padre_id INT NULL,
    is_predefinita TINYINT(1) NOT NULL DEFAULT 0,   -- categoria proposta di default nei nuovi componenti
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_padre_id) REFERENCES categorie_componenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_categoria_azienda (azienda_id, codice)
) ENGINE=InnoDB;

-- Tipi di componente configurabili da amministrazione (sostituisce il vecchio ENUM fisso).
-- ha_distinta_base indica se un componente di questo tipo può avere una propria BOM
-- (es. Semilavorato, Prodotto finito) oppure è un elemento base non ulteriormente esplodibile
-- (es. Componente, Materiale di consumo, Accessorio...).
CREATE TABLE tipi_componente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(30) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    ha_distinta_base TINYINT(1) NOT NULL DEFAULT 0,
    is_predefinito TINYINT(1) NOT NULL DEFAULT 0,   -- tipo proposto di default nei nuovi componenti
    ordinamento INT DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tipo_azienda (azienda_id, codice)
) ENGINE=InnoDB;

-- Tecnologie di montaggio/costruzione del componente, configurabili da amministrazione
-- (es. SMD, THT, o altre categorie che l'azienda vuole tracciare)
CREATE TABLE tecnologie (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(20) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    is_predefinita TINYINT(1) NOT NULL DEFAULT 0,
    ordinamento INT DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tecnologia_azienda (azienda_id, codice)
) ENGINE=InnoDB;

CREATE TABLE componenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice_interno VARCHAR(50) NOT NULL,
    sigla VARCHAR(50) NULL,                  -- sigla/abbreviazione breve del componente
    descrizione VARCHAR(255) NOT NULL,
    tipo_componente_id INT NOT NULL,
    categoria_id INT NULL,
    tecnologia_id INT NULL,                  -- es. SMD, THT (configurabile da amministrazione)
    um_base_id INT NOT NULL,                 -- unità di misura di riferimento per le giacenze
    immagine VARCHAR(500) NULL,              -- immagine principale del componente
    prezzo_medio DECIMAL(12,4) DEFAULT 0,
    valuta VARCHAR(5) DEFAULT 'EUR',
    scorta_minima DECIMAL(12,3) DEFAULT 0,
    scorta_massima DECIMAL(12,3) DEFAULT 0,
    punto_riordino DECIMAL(12,3) DEFAULT 0,
    lead_time_giorni INT DEFAULT 0,
    revisione_corrente VARCHAR(20) DEFAULT 'A',
    specifiche TEXT,                         -- specifiche tecniche del componente
    case_componente VARCHAR(50) NULL,        -- package/case (es. SOT-23, 0805, DIP-8, TO-220...)
    data_inizio_validita DATE NULL,
    data_fine_validita DATE NULL,
    stato_ciclo_vita ENUM('attivo','nrnd','eol','obsoleto') NOT NULL DEFAULT 'attivo',  -- NRND = Not Recommended for New Designs
    data_annuncio_eol DATE NULL,             -- data in cui il produttore ha annunciato l'EOL
    data_ultimo_ordine DATE NULL,             -- LTB - Last Time Buy: termine ultimo per ordinare
    data_ultima_consegna DATE NULL,           -- Last Shipment Date: ultima data di consegna garantita dal produttore
    componente_sostitutivo_id INT NULL,       -- componente proposto come sostituto una volta a EOL
    note_eol TEXT,
    note TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utente_creazione_id INT,
    utente_modifica_id INT NULL,
    data_modifica DATETIME NULL,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_componente_id) REFERENCES tipi_componente(id),
    FOREIGN KEY (um_base_id) REFERENCES unita_misura(id),
    FOREIGN KEY (categoria_id) REFERENCES categorie_componenti(id) ON DELETE SET NULL,
    FOREIGN KEY (tecnologia_id) REFERENCES tecnologie(id) ON DELETE SET NULL,
    FOREIGN KEY (utente_creazione_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (utente_modifica_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (componente_sostitutivo_id) REFERENCES componenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_componente_azienda (azienda_id, codice_interno)
) ENGINE=InnoDB;

-- Unità di misura multiple per componente (con fattore di conversione verso um_base)
CREATE TABLE componenti_unita_misura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    componente_id INT NOT NULL,
    unita_misura_id INT NOT NULL,
    fattore_conversione DECIMAL(14,6) NOT NULL DEFAULT 1, -- 1 unità_misura = fattore * um_base
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    UNIQUE KEY uniq_comp_um (componente_id, unita_misura_id)
) ENGINE=InnoDB;

-- Documentazione allegata (PDF/JPG/link) - datasheet, disegni, ecc. con versioning
CREATE TABLE componenti_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    componente_id INT NOT NULL,
    tipo_allegato ENUM('file','link') NOT NULL DEFAULT 'file',
    nome_file VARCHAR(255) NOT NULL,
    percorso VARCHAR(500) NOT NULL,           -- percorso locale se tipo_allegato='file', URL completo se 'link'
    tipo_file VARCHAR(10) NOT NULL,          -- pdf, jpg, png... oppure 'link' per i collegamenti esterni
    descrizione VARCHAR(255),
    versione VARCHAR(20) DEFAULT '1.0',
    data_upload DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utente_id INT,
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Associazione componente <-> fornitore (codice fornitore, prezzo, lead time, MOQ)
CREATE TABLE componenti_fornitori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    componente_id INT NOT NULL,
    fornitore_id INT NOT NULL,
    codice_fornitore VARCHAR(100) NOT NULL,   -- part number del fornitore/distributore
    produttore VARCHAR(150) NULL,             -- produttore/manufacturer del componente per questo fornitore
    codice_produttore VARCHAR(100) NULL,      -- codice del produttore (MPN), distinto dal codice fornitore/distributore
    prezzo DECIMAL(12,4),
    valuta VARCHAR(5) DEFAULT 'EUR',
    lead_time_giorni INT DEFAULT 0,
    moq DECIMAL(12,3) DEFAULT 1,              -- quantità minima ordinabile
    multiplo_ordine DECIMAL(12,3) DEFAULT 1,
    shipping_cost DECIMAL(10,4) NULL,         -- costo di spedizione stimato/dichiarato per questo fornitore
    disponibilita_real_time DECIMAL(12,3) DEFAULT NULL,  -- valorizzato da API distributore
    url_prodotto VARCHAR(500) NULL,           -- link alla pagina prodotto (dall'API se disponibile, o inserito manualmente)
    ultimo_aggiornamento_api DATETIME NULL,
    preferito TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT,
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (fornitore_id) REFERENCES fornitori(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_comp_fornitore (componente_id, fornitore_id, codice_fornitore)
) ENGINE=InnoDB;

-- Componenti alternativi (sostitutivi/equivalenti)
CREATE TABLE componenti_alternativi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    componente_id INT NOT NULL,
    componente_alternativo_id INT NOT NULL,
    compatibilita ENUM('totale','parziale') NOT NULL DEFAULT 'totale',
    note VARCHAR(255),
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_alternativo_id) REFERENCES componenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_alt (componente_id, componente_alternativo_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- REVISIONI (componenti e BOM)
-- ------------------------------------------------------------
CREATE TABLE revisioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    tipo_oggetto ENUM('componente','bom') NOT NULL,
    oggetto_id INT NOT NULL,                 -- id del componente o della bom
    numero_revisione VARCHAR(20) NOT NULL,
    descrizione_modifica TEXT,
    file_allegato VARCHAR(500) NULL,
    utente_id INT,
    data_revisione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MAGAZZINI E UBICAZIONI
-- ------------------------------------------------------------
CREATE TABLE magazzini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(20) NOT NULL,
    descrizione VARCHAR(150) NOT NULL,
    indirizzo VARCHAR(255),
    tipo ENUM('materie_prime','semilavorati','prodotti_finiti','conto_lavoro','quarantena') DEFAULT 'materie_prime',
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_magazzino_azienda (azienda_id, codice)
) ENGINE=InnoDB;

CREATE TABLE ubicazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    magazzino_id INT NOT NULL,
    codice VARCHAR(30) NOT NULL,              -- es. A-01-03 (scaffale-colonna-ripiano)
    descrizione VARCHAR(150),
    FOREIGN KEY (magazzino_id) REFERENCES magazzini(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ubic (magazzino_id, codice)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- LOTTI (tracciabilità per fornitore + lotto)
-- ------------------------------------------------------------
CREATE TABLE lotti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    componente_id INT NOT NULL,
    fornitore_id INT NOT NULL,
    codice_lotto_fornitore VARCHAR(100) NOT NULL,
    codice_lotto_interno VARCHAR(100) NOT NULL,   -- generato automaticamente
    ddt_riferimento VARCHAR(100),
    ordine_acquisto_id INT NULL,
    data_ricezione DATE NOT NULL,
    data_scadenza DATE NULL,
    quantita_iniziale DECIMAL(14,3) NOT NULL,
    quantita_residua DECIMAL(14,3) NOT NULL,
    stato ENUM('disponibile','quarantena','esaurito','bloccato') DEFAULT 'disponibile',
    note TEXT,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id),
    FOREIGN KEY (fornitore_id) REFERENCES fornitori(id),
    UNIQUE KEY uniq_lotto_azienda (azienda_id, codice_lotto_interno)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- GIACENZE (stock per magazzino/ubicazione/lotto)
-- ------------------------------------------------------------
CREATE TABLE giacenze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    componente_id INT NOT NULL,
    magazzino_id INT NOT NULL,
    ubicazione_id INT NULL,
    lotto_id INT NULL,
    quantita DECIMAL(14,3) NOT NULL DEFAULT 0,   -- espressa in um_base del componente
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id),
    FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    FOREIGN KEY (ubicazione_id) REFERENCES ubicazioni(id) ON DELETE SET NULL,
    FOREIGN KEY (lotto_id) REFERENCES lotti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Causali configurabili per i movimenti di magazzino (es. "Rettifica inventario",
-- "Reso a fornitore", "Consumo produzione"...). tipo_movimento = 'tutti' significa
-- che la causale è proponibile per qualunque tipo di movimento.
CREATE TABLE causali_movimento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    codice VARCHAR(30) NOT NULL,
    descrizione VARCHAR(150) NOT NULL,
    tipo_movimento ENUM('carico','scarico','trasferimento','rettifica','tutti') NOT NULL DEFAULT 'tutti',
    ordinamento INT DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_causale_azienda (azienda_id, codice)
) ENGINE=InnoDB;

-- Movimenti di magazzino (storico carichi/scarichi/trasferimenti)
CREATE TABLE movimenti_magazzino (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    componente_id INT NOT NULL,
    lotto_id INT NULL,
    magazzino_id INT NOT NULL,
    magazzino_destinazione_id INT NULL,       -- per trasferimenti
    ubicazione_id INT NULL,
    tipo_movimento ENUM('carico','scarico','trasferimento','rettifica') NOT NULL,
    quantita DECIMAL(14,3) NOT NULL,
    causale VARCHAR(255),
    riferimento_documento VARCHAR(100),
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utente_id INT,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id),
    FOREIGN KEY (lotto_id) REFERENCES lotti(id) ON DELETE SET NULL,
    FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    FOREIGN KEY (magazzino_destinazione_id) REFERENCES magazzini(id) ON DELETE SET NULL,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DISTINTA BASE (BOM) multilivello
-- ------------------------------------------------------------
CREATE TABLE bom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    componente_padre_id INT NOT NULL,          -- semilavorato/prodotto finito a cui appartiene la distinta
    revisione VARCHAR(20) NOT NULL DEFAULT 'A',
    stato ENUM('bozza','attiva','obsoleta') NOT NULL DEFAULT 'bozza',
    attivo TINYINT(1) NOT NULL DEFAULT 1,       -- disattivazione "leggera": nasconde la BOM senza eliminarla
    data_inizio_validita DATE NULL,
    data_fine_validita DATE NULL,
    descrizione VARCHAR(255),
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utente_id INT,
    utente_modifica_id INT NULL,
    data_modifica DATETIME NULL,
    note TEXT,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_padre_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (utente_modifica_id) REFERENCES utenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_bom_rev (componente_padre_id, revisione)
) ENGINE=InnoDB;

CREATE TABLE bom_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bom_id INT NOT NULL,
    componente_id INT NOT NULL,                -- il componente/semilavorato figlio (può a sua volta avere una BOM propria -> multilivello)
    quantita DECIMAL(14,4) NOT NULL,
    unita_misura_id INT NOT NULL,
    designatore VARCHAR(100),                  -- riferimento (es. R1,R2 / posizione)
    note VARCHAR(255),
    ordinamento INT DEFAULT 0,
    FOREIGN KEY (bom_id) REFERENCES bom(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id),
    FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ORDINI DI ACQUISTO (funzione aggiuntiva)
-- ------------------------------------------------------------
CREATE TABLE ordini_acquisto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    numero_ordine VARCHAR(30) NOT NULL,
    fornitore_id INT NOT NULL,
    stato ENUM('bozza','inviato','confermato','parziale','ricevuto','annullato') DEFAULT 'bozza',
    data_ordine DATE NOT NULL,
    data_consegna_prevista DATE NULL,
    note TEXT,
    utente_id INT,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (fornitore_id) REFERENCES fornitori(id),
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_ordine_azienda (azienda_id, numero_ordine)
) ENGINE=InnoDB;

CREATE TABLE ordini_acquisto_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordine_id INT NOT NULL,
    componente_id INT NOT NULL,
    codice_fornitore VARCHAR(100) NULL,       -- part number del fornitore, recuperato da componenti_fornitori
    quantita_ordinata DECIMAL(14,3) NOT NULL,
    quantita_ricevuta DECIMAL(14,3) DEFAULT 0,
    prezzo_unitario DECIMAL(12,4),
    unita_misura_id INT NOT NULL,
    data_consegna_prevista DATE NULL,
    FOREIGN KEY (ordine_id) REFERENCES ordini_acquisto(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id),
    FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ECO - Engineering Change Order (ordini di modifica formali)
-- ------------------------------------------------------------
CREATE TABLE eco (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    numero_eco VARCHAR(30) NOT NULL,
    titolo VARCHAR(255) NOT NULL,
    descrizione TEXT,                          -- dettaglio della modifica proposta
    motivazione TEXT,                          -- perché si richiede la modifica
    tipo_oggetto ENUM('componente','bom') NOT NULL,
    oggetto_id INT NOT NULL,                   -- id del componente o della bom interessata
    stato ENUM('bozza','in_revisione','approvato','rifiutato','implementato','annullato') NOT NULL DEFAULT 'bozza',
    priorita ENUM('bassa','media','alta','urgente') NOT NULL DEFAULT 'media',
    impatto_costi TEXT,
    impatto_disponibilita TEXT,
    richiedente_id INT,
    approvatore_id INT NULL,
    data_richiesta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_decisione DATETIME NULL,
    data_implementazione DATETIME NULL,
    note_decisione TEXT,
    bom_risultante_id INT NULL,                -- valorizzato automaticamente se l'implementazione ha creato una nuova revisione BOM
    revisione_proposta VARCHAR(20) NULL,       -- codice proposto per la nuova revisione BOM (solo per ECO di tipo "bom"), modificabile prima dell'approvazione
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (richiedente_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (approvatore_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (bom_risultante_id) REFERENCES bom(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_eco_azienda (azienda_id, numero_eco)
) ENGINE=InnoDB;

-- Modifiche puntuali richieste da un ECO di tipo "bom" (aggiungi/rimuovi/sostituisci
-- componente, modifica quantità). Applicate automaticamente all'approvazione.
CREATE TABLE eco_modifiche_bom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eco_id INT NOT NULL,
    tipo_modifica ENUM('aggiungi','rimuovi','sostituisci','modifica_quantita') NOT NULL,
    componente_id INT NULL,                    -- componente esistente coinvolto (rimuovi/sostituisci/modifica_quantita)
    componente_nuovo_id INT NULL,              -- componente da aggiungere, o sostituto (aggiungi/sostituisci)
    quantita DECIMAL(14,4) NULL,                -- nuova riga (aggiungi) o nuova quantità (modifica_quantita)
    unita_misura_id INT NULL,
    designatore VARCHAR(100) NULL,
    note VARCHAR(255) NULL,
    ordinamento INT DEFAULT 0,
    FOREIGN KEY (eco_id) REFERENCES eco(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE SET NULL,
    FOREIGN KEY (componente_nuovo_id) REFERENCES componenti(id) ON DELETE SET NULL,
    FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Storico dei cambi di stato dell'ECO (traccia il workflow di approvazione)
CREATE TABLE eco_storico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eco_id INT NOT NULL,
    stato_precedente VARCHAR(20),
    stato_nuovo VARCHAR(20) NOT NULL,
    utente_id INT,
    nota TEXT,
    data_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (eco_id) REFERENCES eco(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- OMOLOGAZIONE COMPONENTI (codice + produttore, con stato e workflow di approvazione)
-- ------------------------------------------------------------
CREATE TABLE omologazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NOT NULL,
    componente_id INT NOT NULL,
    produttore VARCHAR(150) NOT NULL,
    codice_produttore VARCHAR(100) NOT NULL,
    stato ENUM('non_omologato','omologato','accettato_in_deroga') NOT NULL DEFAULT 'non_omologato',
    note TEXT,
    utente_creazione_id INT,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES componenti(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_creazione_id) REFERENCES utenti(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_omologazione (componente_id, produttore, codice_produttore)
) ENGINE=InnoDB;

-- Richieste di cambio stato: ogni cambiamento (verso omologato, accettato in deroga, o
-- indietro a non omologato) passa da qui e richiede l'approvazione di un amministratore
-- prima che lo stato sulla riga "omologazioni" venga effettivamente aggiornato.
CREATE TABLE omologazioni_richieste (
    id INT AUTO_INCREMENT PRIMARY KEY,
    omologazione_id INT NOT NULL,
    stato_precedente VARCHAR(30) NOT NULL,
    stato_richiesto VARCHAR(30) NOT NULL,
    motivazione TEXT,
    stato_richiesta ENUM('in_attesa','approvata','rifiutata') NOT NULL DEFAULT 'in_attesa',
    richiedente_id INT,
    approvatore_id INT NULL,
    data_richiesta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_decisione DATETIME NULL,
    note_decisione TEXT,
    FOREIGN KEY (omologazione_id) REFERENCES omologazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (richiedente_id) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (approvatore_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Collegamento tra un'omologazione e uno o più documenti già caricati per lo stesso componente
CREATE TABLE omologazioni_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    omologazione_id INT NOT NULL,
    documento_id INT NOT NULL,
    FOREIGN KEY (omologazione_id) REFERENCES omologazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (documento_id) REFERENCES componenti_documenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_omologazione_documento (omologazione_id, documento_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- LOG ATTIVITA' (audit trail - funzione aggiuntiva)
-- ------------------------------------------------------------
CREATE TABLE log_attivita (
    id INT AUTO_INCREMENT PRIMARY KEY,
    azienda_id INT NULL,                      -- NULL per le azioni del superadmin
    utente_id INT NULL,
    azione VARCHAR(100) NOT NULL,
    entita VARCHAR(50),
    entita_id INT,
    dettagli TEXT,
    data_azione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (azienda_id) REFERENCES aziende(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DATI INIZIALI
-- ------------------------------------------------------------
-- Nessun dato applicativo viene creato qui: dopo l'importazione dello
-- schema, apri install.php dal browser per creare il primo utente
-- SUPERADMIN (password scelta da te, hashata correttamente). Da lì
-- potrai creare la prima azienda e il suo utente amministratore: sarà
-- lui, al primo accesso con il codice azienda, a trovare precaricate
-- le unità di misura, i tipi componente, le tecnologie, le causali di
-- movimento e i magazzini di base (vedi includes/functions.php ->
-- crea_dati_iniziali_azienda(), richiamata automaticamente alla
-- creazione di ogni nuova azienda).
