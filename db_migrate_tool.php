<?php
/**
 * ============================================================
 * STRUMENTO DI MIGRAZIONE DATABASE - USO UNA TANTUM
 * ============================================================
 * Pagina standalone (non collegata al menu dell'applicazione) che
 * permette di eseguire i file .sql del progetto (db.sql, migration_v2.sql, ecc.)
 * direttamente dal browser, senza dover aprire phpMyAdmin o un client SQL.
 *
 * Gestisce correttamente anche gli script con "DELIMITER $$" (stored
 * procedure), che con un semplice "copia e incolla" in molti strumenti
 * andrebbero in errore: DELIMITER è una convenzione del client
 * (mysql CLI / phpMyAdmin), non fa parte del protocollo SQL vero e
 * proprio, quindi questo script la rimuove e ripristina il ";" come
 * terminatore prima di inviare il tutto al database in un'unica
 * chiamata multi-statement.
 *
 * *** SICUREZZA - LEGGI PRIMA DI CARICARE QUESTO FILE SUL SERVER ***
 * 1) Cambia subito la password qui sotto (ACCESS_PASSWORD).
 * 2) Carica questo file SOLO per il tempo necessario a eseguire la
 *    migrazione, poi CANCELLALO dal server. Chiunque conosca la
 *    password (o la trovi/bucherella) può eseguire codice SQL sul
 *    tuo database tramite questa pagina.
 * 3) Funziona solo sui file .sql già presenti nella stessa cartella
 *    di questo script (non accetta SQL arbitrario incollato o
 *    caricato), per limitare i rischi.
 * ============================================================
 */

// ------------------------------------------------------------
// CONFIGURAZIONE - CAMBIA QUESTA PASSWORD PRIMA DI CARICARE IL FILE
// ------------------------------------------------------------
define('ACCESS_PASSWORD', 'CAMBIAMI_SUBITO_123');

// Credenziali database: riusa quelle già presenti in config.php se disponibile,
// altrimenti impostale qui manualmente.
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    // Includiamo config.php solo per leggere le costanti DB_*; ignoriamo eventuali
    // effetti collaterali (apertura sessione, connessione PDO) che non ci servono qui.
    require_once $configPath;
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'nome_database');
    define('DB_USER', 'utente');
    define('DB_PASS', 'password');
}

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

$autenticato = !empty($_SESSION['migrazione_autenticato']);
$messaggio = '';
$messaggioTipo = 'info';
$log = [];

// ------------------------------------------------------------
// LOGIN
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$autenticato) {
    if (hash_equals(ACCESS_PASSWORD, $_POST['password'])) {
        $_SESSION['migrazione_autenticato'] = true;
        $autenticato = true;
    } else {
        $messaggio = 'Password errata.';
        $messaggioTipo = 'danger';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['migrazione_autenticato']);
    header('Location: ' . basename(__FILE__));
    exit;
}

// ------------------------------------------------------------
// ESECUZIONE DI UN FILE .SQL
// ------------------------------------------------------------
function esegui_file_sql(string $percorso): array {
    $log = [];
    $contenuto = file_get_contents($percorso);
    if ($contenuto === false) {
        return [['ok' => false, 'msg' => 'Impossibile leggere il file.']];
    }

    // Normalizza gli script che usano "DELIMITER $$ ... DELIMITER ;":
    // rimuove le righe DELIMITER e riporta "$$" a ";" come terminatore,
    // così l'intero script può essere inviato come singola query multi-statement.
    $normalizzato = preg_replace('/^\s*DELIMITER\s+.*$/mi', '', $contenuto);
    $normalizzato = str_replace('$$', ';', $normalizzato);

    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        return [['ok' => false, 'msg' => 'Connessione al database fallita: ' . $mysqli->connect_error]];
    }
    $mysqli->set_charset('utf8mb4');

    if (!$mysqli->multi_query($normalizzato)) {
        $log[] = ['ok' => false, 'msg' => 'Errore SQL: ' . $mysqli->error];
        $mysqli->close();
        return $log;
    }

    $numeroIstruzione = 0;
    do {
        $numeroIstruzione++;
        if ($risultato = $mysqli->store_result()) {
            $log[] = ['ok' => true, 'msg' => "Istruzione $numeroIstruzione: " . $risultato->num_rows . ' righe restituite.'];
            $risultato->free();
        } elseif ($mysqli->errno) {
            $log[] = ['ok' => false, 'msg' => "Istruzione $numeroIstruzione: errore - " . $mysqli->error];
        } else {
            $log[] = ['ok' => true, 'msg' => "Istruzione $numeroIstruzione: eseguita (" . $mysqli->affected_rows . ' righe interessate).'];
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    $mysqli->close();
    return $log;
}

if ($autenticato && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'])) {
    $fileRichiesto = basename($_POST['file']); // basename() previene path traversal
    $percorsoCompleto = __DIR__ . '/' . $fileRichiesto;
    if (!preg_match('/\.sql$/i', $fileRichiesto) || !is_file($percorsoCompleto)) {
        $messaggio = 'File non valido.';
        $messaggioTipo = 'danger';
    } else {
        $log = esegui_file_sql($percorsoCompleto);
        $falliti = count(array_filter($log, fn($r) => !$r['ok']));
        $messaggio = $falliti === 0
            ? 'Script eseguito senza errori.'
            : "Script eseguito con $falliti errore/i (vedi dettaglio sotto).";
        $messaggioTipo = $falliti === 0 ? 'success' : 'danger';
    }
}

// elenco dei file .sql disponibili nella stessa cartella
$fileSql = glob(__DIR__ . '/*.sql') ?: [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Strumento migrazione database</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:720px; margin-top:50px; margin-bottom:50px;">

  <div class="alert alert-warning">
    <strong>Strumento a uso interno.</strong> Ricordati di cancellare questo file dal server una volta terminate le migrazioni.
  </div>

  <?php if (!$autenticato): ?>
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h5 class="mb-3">Accesso strumento di migrazione</h5>
        <?php if ($messaggio): ?><div class="alert alert-<?= htmlspecialchars($messaggioTipo) ?>"><?= htmlspecialchars($messaggio) ?></div><?php endif; ?>
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required autofocus>
          </div>
          <button class="btn btn-primary">Accedi</button>
        </form>
      </div>
    </div>

  <?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Strumento di migrazione database</h5>
      <a href="?logout=1" class="btn btn-sm btn-outline-secondary">Esci</a>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body p-3">
        <div class="small text-muted">Database di destinazione</div>
        <div><code><?= htmlspecialchars(DB_USER) ?>@<?= htmlspecialchars(DB_HOST) ?> / <?= htmlspecialchars(DB_NAME) ?></code></div>
      </div>
    </div>

    <?php if ($messaggio): ?><div class="alert alert-<?= htmlspecialchars($messaggioTipo) ?>"><?= htmlspecialchars($messaggio) ?></div><?php endif; ?>

    <?php if ($log): ?>
      <div class="card mb-3">
        <div class="card-body p-3">
          <h6>Dettaglio esecuzione</h6>
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($log as $riga): ?>
              <li class="<?= $riga['ok'] ? 'text-success' : 'text-danger fw-bold' ?>">
                <?= $riga['ok'] ? '✔' : '✘' ?> <?= htmlspecialchars($riga['msg']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h6>File .sql disponibili in questa cartella</h6>
        <?php if (!$fileSql): ?>
          <p class="text-muted small">Nessun file .sql trovato accanto a questo script. Carica <code>migration_v2.sql</code> (o <code>db.sql</code>) nella stessa cartella.</p>
        <?php else: ?>
          <?php foreach ($fileSql as $percorso): $nome = basename($percorso); ?>
            <form method="post" class="d-flex justify-content-between align-items-center border rounded p-3 mb-2"
                  onsubmit="return confirm('Eseguire il file <?= htmlspecialchars($nome, ENT_QUOTES) ?> sul database <?= htmlspecialchars(DB_NAME, ENT_QUOTES) ?>? Assicurati di avere un backup.');">
              <div>
                <strong><?= htmlspecialchars($nome) ?></strong>
                <div class="small text-muted"><?= number_format(filesize($percorso) / 1024, 1) ?> KB</div>
              </div>
              <div>
                <input type="hidden" name="file" value="<?= htmlspecialchars($nome) ?>">
                <button class="btn btn-primary btn-sm">Esegui</button>
              </div>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <p class="text-muted small mt-3">
      Nota: gli script di migrazione di questo progetto sono scritti per essere <strong>idempotenti</strong> (rieseguibili più volte senza danni),
      ma è comunque buona norma fare un backup del database prima di lanciare qualunque script.
    </p>
  <?php endif; ?>

</div>
</body>
</html>
