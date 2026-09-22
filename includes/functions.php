<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/version.php';

// ------------------------------------------------------------
// AUTENTICAZIONE / AUTORIZZAZIONE
// ------------------------------------------------------------
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'nome' => $_SESSION['nome'],
        'cognome' => $_SESSION['cognome'],
        'ruolo' => $_SESSION['ruolo'],
        'azienda_id' => $_SESSION['azienda_id'] ?? null,
        'azienda_nome' => $_SESSION['azienda_nome'] ?? null,
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . base_url('auth/login.php'));
        exit;
    }
}

// Riservato agli amministratori DI AZIENDA (non al superadmin, che ha il suo require_superadmin()
// e non deve mai navigare le pagine operative dell'app, dato che non appartiene a nessuna azienda).
function require_admin(): void {
    require_login();
    if ($_SESSION['ruolo'] !== 'admin') {
        http_response_code(403);
        die('Accesso negato: funzione riservata agli amministratori.');
    }
}

function is_admin(): bool {
    return is_logged_in() && $_SESSION['ruolo'] === 'admin';
}

// ------------------------------------------------------------
// SUPERADMIN e CONTESTO AZIENDA (multi-tenant)
// ------------------------------------------------------------
function is_superadmin(): bool {
    return is_logged_in() && $_SESSION['ruolo'] === 'superadmin';
}

function require_superadmin(): void {
    require_login();
    if ($_SESSION['ruolo'] !== 'superadmin') {
        http_response_code(403);
        die('Accesso negato: funzione riservata al Super Admin.');
    }
}

// ID dell'azienda dell'utente collegato. NULL per il superadmin (che non appartiene
// a nessuna azienda): le pagine operative dell'app non vanno mai raggiunte da lui.
function azienda_id(): ?int {
    return $_SESSION['azienda_id'] ?? null;
}

// Blocca l'esecuzione se manca un contesto azienda valido in sessione. Va richiamata
// (tramite include di header.php, che lo fa già automaticamente) da ogni pagina
// operativa dell'app, per non eseguire mai una query senza filtro azienda_id.
function require_azienda(): void {
    require_login();
    if (is_superadmin() || azienda_id() === null) {
        http_response_code(403);
        die('Questa pagina appartiene a un\'azienda specifica: il Super Admin non vi accede direttamente. Usa il pannello Super Admin.');
    }
}

// ------------------------------------------------------------
// PERMESSI GRANULARI (solo per utenti di ruolo 'user' - gli admin hanno sempre accesso completo)
// ------------------------------------------------------------

// Elenco delle sezioni dell'applicazione per cui è possibile configurare un livello
// di accesso per utente. La chiave corrisponde al nome della cartella del modulo.
const SEZIONI_APP = [
    'componenti' => 'Componenti',
    'bom' => 'Distinte Base',
    'eco' => 'Ordini di Modifica (ECO)',
    'giacenze' => 'Giacenze',
    'lotti' => 'Lotti',
    'magazzini' => 'Magazzini',
    'fornitori' => 'Fornitori',
    'ordini_acquisto' => 'Ordini di Acquisto',
];

// Livello di accesso dell'utente corrente per la sezione indicata: 'nessuno', 'lettura' o 'scrittura'.
// Gli amministratori hanno sempre 'scrittura'. In assenza di un permesso esplicito in sessione
// il default è 'scrittura' (accesso completo), per compatibilità con gli utenti non ancora configurati.
function utente_livello_sezione(string $sezione): string {
    if (is_admin()) return 'scrittura';
    return $_SESSION['permessi_sezioni'][$sezione] ?? 'scrittura';
}

// Rileva automaticamente la "sezione" corrente dal nome della cartella dello script in
// esecuzione (es. .../mrp_app/componenti/list.php -> 'componenti'), usata dalle funzioni
// sottostanti per applicare il controllo senza dover passare esplicitamente la sezione
// da ogni singolo file.
function sezione_corrente(): string {
    return basename(dirname($_SERVER['SCRIPT_FILENAME']));
}

// True se l'utente corrente può accedere alla sezione indicata (livello 'lettura' o 'scrittura').
// Gli amministratori hanno sempre accesso a tutte le sezioni.
function utente_ha_accesso_sezione(string $sezione): bool {
    if (!isset(SEZIONI_APP[$sezione])) return true; // sezione non gestita da questo sistema di permessi
    return utente_livello_sezione($sezione) !== 'nessuno';
}

// Blocca l'esecuzione con un errore 403 se l'utente corrente non ha accesso alla sezione indicata.
function require_sezione(string $sezione): void {
    if (!utente_ha_accesso_sezione($sezione)) {
        http_response_code(403);
        die('Accesso negato: non hai i permessi per accedere a questa sezione. Contatta un amministratore.');
    }
}

// True se l'utente corrente è in sola lettura per la sezione indicata (o, se omessa, per quella
// rilevata automaticamente dalla cartella dello script in esecuzione). Gli amministratori non sono mai in sola lettura.
function is_sola_lettura(?string $sezione = null): bool {
    if (is_admin()) return false;
    $sezione = $sezione ?? sezione_corrente();
    if (!isset(SEZIONI_APP[$sezione])) return false; // sezioni non gestite (es. utenti, admin) non sono mai limitate da qui
    return utente_livello_sezione($sezione) === 'lettura';
}

// Blocca l'esecuzione con un errore 403 se l'utente corrente è in sola lettura per la sezione
// indicata (o quella corrente). Va richiamata da ogni punto dell'app che scrive sul database
// (viene già chiamata automaticamente da csrf_verify(), che copre la stragrande maggioranza dei casi;
// i file che scrivono dati pur non risiedendo nella cartella della propria sezione, come
// api/distributori.php, devono passare esplicitamente il nome della sezione).
function require_scrittura(?string $sezione = null): void {
    if (is_sola_lettura($sezione)) {
        http_response_code(403);
        die('Il tuo account è in sola lettura per questa sezione: non puoi modificare i dati. Contatta un amministratore se ritieni sia un errore.');
    }
}

// Ricarica in sessione i permessi per sezione dell'utente, da richiamare al login.
// Se un amministratore modifica i permessi di un utente che ha già una sessione attiva,
// le modifiche si applicano dal suo prossimo login, non in tempo reale.
function carica_permessi_in_sessione(PDO $pdo, int $utenteId): void {
    $stmt = $pdo->prepare("SELECT sezione, livello FROM utenti_permessi_sezioni WHERE utente_id = ?");
    $stmt->execute([$utenteId]);
    $_SESSION['permessi_sezioni'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['componenti' => 'lettura', ...]
}

// Calcola l'URL assoluto (rispetto al dominio) di una risorsa dell'app,
// tenendo conto dell'eventuale sottocartella di installazione (es. /mrp_app)
// ------------------------------------------------------------
// MULTILINGUA
// ------------------------------------------------------------
const LINGUE_DISPONIBILI = ['it' => 'Italiano', 'en' => 'English'];

// Determina la lingua corrente (sessione utente loggato > cookie > default 'it') e carica
// il relativo file di traduzioni una sola volta per richiesta.
function carica_lingua(): void {
    if (isset($GLOBALS['LANG'])) { return; } // già caricata in questa richiesta

    $lingua = $_SESSION['lingua'] ?? $_COOKIE['lingua'] ?? 'it';
    if (!isset(LINGUE_DISPONIBILI[$lingua])) { $lingua = 'it'; }

    $percorso = __DIR__ . '/../lang/' . $lingua . '.php';
    $GLOBALS['LANG'] = is_file($percorso) ? require $percorso : [];
    $GLOBALS['LINGUA_CORRENTE'] = $lingua;
}

// Restituisce il testo tradotto per una chiave nella lingua corrente. Se la chiave non
// esiste nel file di lingua attivo, mostra la chiave stessa: mai un errore, e un testo
// mancante si nota subito in pagina invece di apparire come stringa vuota.
function t(string $chiave, array $sostituzioni = []): string {
    carica_lingua();
    $testo = $GLOBALS['LANG'][$chiave] ?? $chiave;
    foreach ($sostituzioni as $k => $v) { $testo = str_replace('{' . $k . '}', (string) $v, $testo); }
    return $testo;
}

function lingua_corrente(): string {
    carica_lingua();
    return $GLOBALS['LINGUA_CORRENTE'];
}

function base_url(string $path = ''): string {
    $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
    return $base . '/' . ltrim($path, '/');
}

// ------------------------------------------------------------
// PAGINAZIONE (per elenchi che possono contenere molti record)
// ------------------------------------------------------------
// $altriParametri: gli altri parametri GET da preservare cliccando le pagine (es. ricerca, filtri)
function paginazione_html(int $pagina, int $totaleRecord, int $perPagina, array $altriParametri = []): string {
    $totalePagine = (int) ceil($totaleRecord / $perPagina);
    if ($totalePagine <= 1) { return ''; }

    $costruisciUrl = function (int $p) use ($altriParametri) {
        return '?' . http_build_query(array_merge($altriParametri, ['pagina' => $p]));
    };

    $html = '<nav class="d-flex justify-content-between align-items-center mt-3">';
    $html .= '<span class="small text-muted">' . number_format($totaleRecord, 0, ',', '.') . ' risultati - pagina ' . $pagina . ' di ' . $totalePagine . '</span>';
    $html .= '<ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($pagina <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . h($costruisciUrl(max(1, $pagina - 1))) . '">&laquo; Precedente</a></li>';

    // mostra al massimo 7 numeri di pagina, centrati sulla pagina corrente
    $inizio = max(1, $pagina - 3);
    $fine = min($totalePagine, $inizio + 6);
    $inizio = max(1, $fine - 6);
    for ($p = $inizio; $p <= $fine; $p++) {
        $html .= '<li class="page-item' . ($p === $pagina ? ' active' : '') . '"><a class="page-link" href="' . h($costruisciUrl($p)) . '">' . $p . '</a></li>';
    }

    $html .= '<li class="page-item' . ($pagina >= $totalePagine ? ' disabled' : '') . '"><a class="page-link" href="' . h($costruisciUrl(min($totalePagine, $pagina + 1))) . '">Successiva &raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Token di sicurezza non valido. Ricarica la pagina e riprova.');
    }
    require_scrittura();
}

// Variante di csrf_verify() per azioni sensibili raggiunte via link GET (non un form POST),
// come il download del backup: verifica solo il token, senza il blocco sola_lettura
// (che riguarda le operazioni di scrittura sui dati, non un'esportazione in sola lettura).
function csrf_verify_get(): void {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Token di sicurezza non valido. Ricarica la pagina e riprova.');
    }
}

// ------------------------------------------------------------
// LOG ATTIVITA'
// ------------------------------------------------------------
function log_attivita(PDO $pdo, string $azione, string $entita = null, int $entitaId = null, string $dettagli = null): void {
    $stmt = $pdo->prepare("INSERT INTO log_attivita (azienda_id, utente_id, azione, entita, entita_id, dettagli, ip_address)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        azienda_id(),
        $_SESSION['user_id'] ?? null,
        $azione,
        $entita,
        $entitaId,
        $dettagli,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

// ------------------------------------------------------------
// UPLOAD FILE
// ------------------------------------------------------------
function gestisci_upload(array $file, ?string $uploadDir = null, ?string $uploadUrl = null, ?array $allowedExt = null): array {
    // ritorna ['ok' => bool, 'nome_file' => ..., 'percorso' => ..., 'tipo' => ..., 'errore' => ...]
    $uploadDir = $uploadDir ?? UPLOAD_DIR;
    $uploadUrl = $uploadUrl ?? UPLOAD_URL;
    $allowedExt = $allowedExt ?? ALLOWED_EXT;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errore' => 'Errore durante il caricamento del file.'];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['ok' => false, 'errore' => 'File troppo grande (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB).'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'errore' => 'Tipo di file non consentito. Ammessi: ' . implode(', ', $allowedExt)];
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $nomeSalvato = uniqid('f_', true) . '.' . $ext;
    $destinazione = $uploadDir . $nomeSalvato;
    if (!move_uploaded_file($file['tmp_name'], $destinazione)) {
        return ['ok' => false, 'errore' => 'Impossibile salvare il file sul server.'];
    }
    return [
        'ok' => true,
        'nome_file' => $file['name'],
        'percorso' => $uploadUrl . $nomeSalvato,
        'tipo' => $ext
    ];
}

// Genera una copia JPEG di un'immagine già caricata (usata per l'incorporazione nei PDF,
// che in questo generatore minimale supporta solo JPEG). Usa l'estensione GD di PHP, molto
// comune sugli hosting condivisi ma non garantita: ritorna null se non disponibile o se la
// conversione fallisce, senza bloccare il resto del salvataggio.
function genera_logo_jpeg(string $percorsoRelativo): ?string {
    if (!function_exists('imagecreatefromstring')) { return null; } // estensione GD assente

    $percorsoAssoluto = __DIR__ . '/../' . $percorsoRelativo;
    $dati = @file_get_contents($percorsoAssoluto);
    if ($dati === false) { return null; }

    $immagine = @imagecreatefromstring($dati);
    if (!$immagine) { return null; }

    // appoggia su sfondo bianco: i loghi PNG con trasparenza diventerebbero altrimenti neri in JPEG
    $larghezza = imagesx($immagine); $altezza = imagesy($immagine);
    $sfondo = imagecreatetruecolor($larghezza, $altezza);
    imagefill($sfondo, 0, 0, imagecolorallocate($sfondo, 255, 255, 255));
    imagealphablending($sfondo, true);
    imagecopy($sfondo, $immagine, 0, 0, 0, 0, $larghezza, $altezza);
    imagedestroy($immagine);

    if (!is_dir(LOGO_UPLOAD_DIR)) { mkdir(LOGO_UPLOAD_DIR, 0755, true); }
    $nomeFile = uniqid('logo_', true) . '.jpg';
    $destinazione = LOGO_UPLOAD_DIR . $nomeFile;
    $ok = imagejpeg($sfondo, $destinazione, 90);
    imagedestroy($sfondo);

    return $ok ? (LOGO_UPLOAD_URL . $nomeFile) : null;
}

// ------------------------------------------------------------
// GENERAZIONE CODICI
// ------------------------------------------------------------
function genera_codice_lotto_interno(PDO $pdo): string {
    $anno = date('y');
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM lotti WHERE azienda_id = ? AND codice_lotto_interno LIKE ?");
    $stmt->execute([azienda_id(), "L{$anno}%"]);
    $n = $stmt->fetch()['c'] + 1;
    return sprintf('L%s%05d', $anno, $n);
}

function genera_numero_ordine(PDO $pdo): string {
    $anno = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM ordini_acquisto WHERE azienda_id = ? AND numero_ordine LIKE ?");
    $stmt->execute([azienda_id(), "OA{$anno}%"]);
    $n = $stmt->fetch()['c'] + 1;
    return sprintf('OA%s-%04d', $anno, $n);
}

function genera_numero_eco(PDO $pdo): string {
    $anno = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM eco WHERE azienda_id = ? AND numero_eco LIKE ?");
    $stmt->execute([azienda_id(), "ECO-{$anno}-%"]);
    $n = $stmt->fetch()['c'] + 1;
    return sprintf('ECO-%s-%04d', $anno, $n);
}

// ------------------------------------------------------------
// GIACENZE / MOVIMENTI (tutto espresso in um_base del componente)
// ------------------------------------------------------------
function giacenza_totale(PDO $pdo, int $componenteId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantita),0) tot FROM giacenze WHERE componente_id = ? AND azienda_id = ?");
    $stmt->execute([$componenteId, azienda_id()]);
    return (float) $stmt->fetch()['tot'];
}

function registra_movimento(PDO $pdo, array $dati): void {
    // $dati: componente_id, lotto_id, magazzino_id, ubicazione_id, tipo_movimento, quantita, causale, riferimento_documento
    $aziendaId = azienda_id();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO movimenti_magazzino
            (azienda_id, componente_id, lotto_id, magazzino_id, magazzino_destinazione_id, ubicazione_id, tipo_movimento, quantita, causale, riferimento_documento, utente_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $aziendaId, $dati['componente_id'], $dati['lotto_id'] ?? null, $dati['magazzino_id'],
            $dati['magazzino_destinazione_id'] ?? null, $dati['ubicazione_id'] ?? null,
            $dati['tipo_movimento'], $dati['quantita'], $dati['causale'] ?? null,
            $dati['riferimento_documento'] ?? null, $_SESSION['user_id'] ?? null
        ]);

        // Determina la quantità con segno da applicare alla giacenza:
        // - scarico: sempre negativa (indipendentemente dal segno inserito)
        // - carico/trasferimento: sempre positiva (quantità in ingresso)
        // - rettifica: mantiene il segno inserito dall'utente (+ per aumentare, - per diminuire)
        if ($dati['tipo_movimento'] === 'scarico') {
            $qta = -abs($dati['quantita']);
        } elseif ($dati['tipo_movimento'] === 'rettifica') {
            $qta = (float) $dati['quantita'];
        } else {
            $qta = abs($dati['quantita']);
        }

        // aggiorna/crea riga di giacenza
        $stmt = $pdo->prepare("SELECT id, quantita FROM giacenze WHERE azienda_id=? AND componente_id=? AND magazzino_id=? AND
            (ubicazione_id <=> ?) AND (lotto_id <=> ?)");
        $stmt->execute([$aziendaId, $dati['componente_id'], $dati['magazzino_id'], $dati['ubicazione_id'] ?? null, $dati['lotto_id'] ?? null]);
        $riga = $stmt->fetch();

        if ($riga) {
            $pdo->prepare("UPDATE giacenze SET quantita = quantita + ? WHERE id = ?")
                ->execute([$qta, $riga['id']]);
        } else {
            $pdo->prepare("INSERT INTO giacenze (azienda_id, componente_id, magazzino_id, ubicazione_id, lotto_id, quantita) VALUES (?,?,?,?,?,?)")
                ->execute([$aziendaId, $dati['componente_id'], $dati['magazzino_id'], $dati['ubicazione_id'] ?? null, $dati['lotto_id'] ?? null, $qta]);
        }

        // se c'è un lotto, aggiorna la quantità residua del lotto
        if (!empty($dati['lotto_id'])) {
            $pdo->prepare("UPDATE lotti SET quantita_residua = quantita_residua + ? WHERE id = ? AND azienda_id = ?")
                ->execute([$qta, $dati['lotto_id'], $aziendaId]);
        }

        // trasferimento: carica anche nel magazzino di destinazione
        if ($dati['tipo_movimento'] === 'trasferimento' && !empty($dati['magazzino_destinazione_id'])) {
            $stmt = $pdo->prepare("SELECT id FROM giacenze WHERE azienda_id=? AND componente_id=? AND magazzino_id=? AND (lotto_id <=> ?)");
            $stmt->execute([$aziendaId, $dati['componente_id'], $dati['magazzino_destinazione_id'], $dati['lotto_id'] ?? null]);
            $rigaDest = $stmt->fetch();
            if ($rigaDest) {
                $pdo->prepare("UPDATE giacenze SET quantita = quantita + ? WHERE id = ?")->execute([abs($dati['quantita']), $rigaDest['id']]);
            } else {
                $pdo->prepare("INSERT INTO giacenze (azienda_id, componente_id, magazzino_id, lotto_id, quantita) VALUES (?,?,?,?,?)")
                    ->execute([$aziendaId, $dati['componente_id'], $dati['magazzino_destinazione_id'], $dati['lotto_id'] ?? null, abs($dati['quantita'])]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ------------------------------------------------------------
// BOM: esplosione multilivello -> calcolo fabbisogno totale componenti base
// ------------------------------------------------------------
function esplodi_bom(PDO $pdo, int $componentePadreId, float $quantitaRichiesta = 1, array &$risultato = [], int $livello = 0): array {
    if ($livello > 20) return $risultato; // protezione loop infiniti

    $stmt = $pdo->prepare("SELECT id FROM bom WHERE componente_padre_id = ? AND azienda_id = ? AND stato = 'attiva' AND attivo = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$componentePadreId, azienda_id()]);
    $bom = $stmt->fetch();
    if (!$bom) return $risultato; // componente base, senza distinta

    $stmt = $pdo->prepare("SELECT br.*, t.ha_distinta_base, c.codice_interno, c.descrizione
                            FROM bom_righe br
                            JOIN componenti c ON c.id = br.componente_id
                            JOIN tipi_componente t ON t.id = c.tipo_componente_id
                            WHERE br.bom_id = ? ORDER BY br.ordinamento, br.id");
    $stmt->execute([$bom['id']]);
    foreach ($stmt->fetchAll() as $riga) {
        $qtaNecessaria = $riga['quantita'] * $quantitaRichiesta;
        if (!$riga['ha_distinta_base']) {
            if (!isset($risultato[$riga['componente_id']])) {
                $risultato[$riga['componente_id']] = [
                    'codice' => $riga['codice_interno'],
                    'descrizione' => $riga['descrizione'],
                    'quantita' => 0
                ];
            }
            $risultato[$riga['componente_id']]['quantita'] += $qtaNecessaria;
        } else {
            // semilavorato/prodotto con propria distinta base: esplodi ricorsivamente
            esplodi_bom($pdo, $riga['componente_id'], $qtaNecessaria, $risultato, $livello + 1);
        }
    }
    return $risultato;
}

// Costruisce l'ALBERO multilivello della BOM di un componente (a differenza di esplodi_bom,
// che appiattisce i totali per componente base, questa mantiene la struttura gerarchica
// livello per livello, utile per la visualizzazione grafica dell'esplosione).
/**
 * Verifica se aggiungere $figlioId come figlio di $padreId creerebbe un ciclo
 * (cioè se $padreId è già, direttamente o indirettamente, un discendente di $figlioId).
 * Condivisa tra bom/save.php (inserimento manuale riga) ed eco/save.php (implementazione
 * automatica delle modifiche approvate).
 */
function verifica_ciclo(PDO $pdo, int $padreId, int $figlioId, int $profondita = 0): bool {
    if ($padreId === $figlioId) return true;
    if ($profondita > 20) return true; // sicurezza

    $stmt = $pdo->prepare("SELECT br.componente_id FROM bom b JOIN bom_righe br ON br.bom_id = b.id
                            WHERE b.componente_padre_id = ?");
    $stmt->execute([$figlioId]);
    foreach ($stmt->fetchAll() as $riga) {
        if (verifica_ciclo($pdo, $padreId, $riga['componente_id'], $profondita + 1)) return true;
    }
    return false;
}

function albero_bom(PDO $pdo, int $componentePadreId, float $quantitaRichiesta = 1, int $livello = 0): array {
    if ($livello > 20) { return []; } // protezione loop infiniti

    $stmt = $pdo->prepare("SELECT id, revisione FROM bom WHERE componente_padre_id = ? AND azienda_id = ? AND stato = 'attiva' AND attivo = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$componentePadreId, azienda_id()]);
    $bom = $stmt->fetch();
    if (!$bom) { return []; } // componente base, senza distinta: nessun figlio

    $stmt = $pdo->prepare("SELECT br.*, t.ha_distinta_base, c.codice_interno, c.descrizione, um.codice um_codice
                            FROM bom_righe br
                            JOIN componenti c ON c.id = br.componente_id
                            JOIN tipi_componente t ON t.id = c.tipo_componente_id
                            JOIN unita_misura um ON um.id = br.unita_misura_id
                            WHERE br.bom_id = ? ORDER BY br.ordinamento, br.id");
    $stmt->execute([$bom['id']]);

    $nodi = [];
    foreach ($stmt->fetchAll() as $riga) {
        $qtaNecessaria = $riga['quantita'] * $quantitaRichiesta;
        $nodi[] = [
            'componente_id' => $riga['componente_id'],
            'codice_interno' => $riga['codice_interno'],
            'descrizione' => $riga['descrizione'],
            'quantita_riga' => $riga['quantita'],
            'quantita_totale' => $qtaNecessaria,
            'um' => $riga['um_codice'],
            'designatore' => $riga['designatore'],
            'ha_distinta_base' => (bool) $riga['ha_distinta_base'],
            'figli' => $riga['ha_distinta_base']
                ? albero_bom($pdo, $riga['componente_id'], $qtaNecessaria, $livello + 1)
                : [],
        ];
    }
    return $nodi;
}

// ------------------------------------------------------------
// NUOVA AZIENDA: dati iniziali (usata dal pannello Super Admin alla creazione di un'azienda)
// ------------------------------------------------------------
function crea_dati_iniziali_azienda(PDO $pdo, int $aziendaId): void {
    $stmt = $pdo->prepare("INSERT INTO unita_misura (azienda_id, codice, descrizione, is_predefinita) VALUES
        (?,'PZ','Pezzi',1), (?,'MT','Metri',0), (?,'KG','Chilogrammi',0), (?,'GR','Grammi',0),
        (?,'RL','Rotolo',0), (?,'CF','Confezione',0), (?,'LT','Litri',0), (?,'CT','Cento pezzi (reel/strip)',0)");
    $stmt->execute(array_fill(0, 8, $aziendaId));

    $stmt = $pdo->prepare("INSERT INTO tipi_componente (azienda_id, codice, descrizione, ha_distinta_base, is_predefinito, ordinamento) VALUES
        (?,'componente','Componente',0,1,1),
        (?,'semilavorato','Semilavorato',1,0,2),
        (?,'prodotto_finito','Prodotto finito',1,0,3)");
    $stmt->execute(array_fill(0, 3, $aziendaId));

    $stmt = $pdo->prepare("INSERT INTO tecnologie (azienda_id, codice, descrizione, ordinamento) VALUES
        (?,'SMD','SMD - Surface Mount Device',1),
        (?,'THT','THT - Through Hole Technology',2)");
    $stmt->execute(array_fill(0, 2, $aziendaId));

    $stmt = $pdo->prepare("INSERT INTO causali_movimento (azienda_id, codice, descrizione, tipo_movimento, ordinamento) VALUES
        (?,'ricezione_merce','Ricezione merce da fornitore','carico',1),
        (?,'reso_cliente','Reso da cliente','carico',2),
        (?,'rientro_produzione','Rientro da produzione','carico',3),
        (?,'consumo_produzione','Consumo per produzione','scarico',4),
        (?,'reso_fornitore','Reso a fornitore','scarico',5),
        (?,'scarto','Scarto/rottamazione','scarico',6),
        (?,'prelievo_manuale','Prelievo manuale','scarico',7),
        (?,'spostamento_reparto','Spostamento tra magazzini','trasferimento',8),
        (?,'rettifica_inventario','Rettifica da inventario fisico','rettifica',9),
        (?,'correzione_errore','Correzione errore di registrazione','rettifica',10)");
    $stmt->execute(array_fill(0, 10, $aziendaId));

    $stmt = $pdo->prepare("INSERT INTO magazzini (azienda_id, codice, descrizione, tipo) VALUES
        (?,'MP01','Magazzino Materie Prime','materie_prime'),
        (?,'SL01','Magazzino Semilavorati','semilavorati'),
        (?,'PF01','Magazzino Prodotti Finiti','prodotti_finiti')");
    $stmt->execute(array_fill(0, 3, $aziendaId));
}

// Elimina il file immagine dal disco solo se nessun ALTRO componente (stessa azienda) lo sta
// ancora usando: necessario perché ora un'immagine già caricata può essere riutilizzata da più
// componenti, quindi non si può più cancellarla alla cieca ogni volta che uno smette di usarla.
// ------------------------------------------------------------
// 2FA VIA EMAIL
// ------------------------------------------------------------

// Genera un codice a 6 cifre, lo salva sull'utente con scadenza (10 minuti) e lo invia via email.
// Ritorna true se l'invio è riuscito (o almeno non ha generato un errore lato server).
function invia_codice_2fa(PDO $pdo, array $utente): bool {
    $codice = (string) random_int(100000, 999999);
    $pdo->prepare("UPDATE utenti SET codice_2fa = ?, codice_2fa_scadenza = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?")
        ->execute([$codice, $utente['id']]);

    if (empty($utente['email'])) return false;

    $oggetto = 'Codice di accesso MRP Elettronica';
    $corpo = "Ciao {$utente['nome']},\n\nIl tuo codice di verifica per accedere è:\n\n$codice\n\n"
           . "Il codice è valido per 10 minuti. Se non hai richiesto tu l'accesso, ignora questa email.\n";
    $headers = "From: MRP Elettronica <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($utente['email'], $oggetto, $corpo, $headers);
}

function verifica_codice_2fa(PDO $pdo, int $utenteId, string $codiceInserito): bool {
    $stmt = $pdo->prepare("SELECT codice_2fa, codice_2fa_scadenza FROM utenti WHERE id = ?");
    $stmt->execute([$utenteId]);
    $riga = $stmt->fetch();
    if (!$riga || !$riga['codice_2fa'] || !$riga['codice_2fa_scadenza']) return false;
    if (strtotime($riga['codice_2fa_scadenza']) < time()) return false;
    $valido = hash_equals($riga['codice_2fa'], trim($codiceInserito));
    if ($valido) {
        // il codice è utilizzabile una sola volta
        $pdo->prepare("UPDATE utenti SET codice_2fa = NULL, codice_2fa_scadenza = NULL WHERE id = ?")->execute([$utenteId]);
    }
    return $valido;
}

function elimina_immagine_se_non_condivisa(PDO $pdo, string $percorso, int $aziendaId, int $escludiComponenteId): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE immagine = ? AND azienda_id = ? AND id != ?");
    $stmt->execute([$percorso, $aziendaId, $escludiComponenteId]);
    if ($stmt->fetch()['c'] == 0) {
        $fisico = __DIR__ . '/../' . $percorso;
        if (is_file($fisico)) { @unlink($fisico); }
    }
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Converte in modo sicuro un valore numerico proveniente da un form (accetta la virgola
// come separatore decimale). Se il campo è vuoto o non numerico ritorna $default
// (di solito null, per lasciare NULL/DEFAULT alla colonna) invece di una stringa vuota,
// che MySQL in modalità strict rifiuta per le colonne numeriche.
function num_or(mixed $valore, $default = null): mixed {
    $valore = trim((string) ($valore ?? ''));
    if ($valore === '') return $default;
    $valore = str_replace(',', '.', $valore);
    return is_numeric($valore) ? (float) $valore : $default;
}
