<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    header('Location: ../index.php');
    exit;
}
if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: login.php');
    exit;
}

$utenteId = $_SESSION['pending_2fa_user_id'];
$stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ? AND attivo = 1");
$stmt->execute([$utenteId]);
$utente = $stmt->fetch();
if (!$utente) {
    unset($_SESSION['pending_2fa_user_id']);
    header('Location: login.php');
    exit;
}

$errore = '';
$messaggio = isset($_GET['invio_falito']) ? 'Non è stato possibile inviare l\'email con il codice. Verifica la configurazione email del server, oppure riprova.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reinvia'])) {
        $inviata = invia_codice_2fa($pdo, $utente);
        $messaggio = $inviata ? 'Nuovo codice inviato.' : 'Invio email non riuscito. Contatta un amministratore.';
    } else {
        $codice = trim($_POST['codice'] ?? '');
        if (verifica_codice_2fa($pdo, $utenteId, $codice)) {
            // completa il login con i dati messi "in sospeso" al passo precedente
            session_regenerate_id(true);
            $_SESSION['user_id'] = $utente['id'];
            $_SESSION['username'] = $utente['username'];
            $_SESSION['nome'] = $utente['nome'];
            $_SESSION['cognome'] = $utente['cognome'];
            $_SESSION['ruolo'] = $_SESSION['pending_2fa_ruolo'];
            $_SESSION['azienda_id'] = $_SESSION['pending_2fa_azienda_id'];
            $_SESSION['azienda_nome'] = $_SESSION['pending_2fa_azienda_nome'];
            $_SESSION['lingua'] = $utente['lingua'] ?? 'it';
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_ruolo'], $_SESSION['pending_2fa_azienda_id'], $_SESSION['pending_2fa_azienda_nome']);

            $pdo->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")->execute([$utente['id']]);
            if ($_SESSION['ruolo'] !== 'superadmin') {
                carica_permessi_in_sessione($pdo, $utente['id']);
                log_attivita($pdo, 'login', 'utenti', $utente['id']);
                header('Location: ../index.php');
            } else {
                log_attivita($pdo, 'login_superadmin', 'utenti', $utente['id']);
                header('Location: ../superadmin/index.php');
            }
            exit;
        }
        $errore = 'Codice non valido o scaduto. Puoi richiederne uno nuovo.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifica accesso - MRP Elettronica</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width:400px;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-1 text-center">Verifica in due passaggi</h4>
      <p class="text-muted text-center mb-4">Abbiamo inviato un codice a<br><strong><?= h($utente['email']) ?></strong></p>
      <?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>
      <?php if ($messaggio): ?><div class="alert alert-info"><?= h($messaggio) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Codice di verifica</label>
          <input type="text" name="codice" class="form-control text-center" style="font-size:1.5rem; letter-spacing:0.3rem;" maxlength="6" pattern="[0-9]{6}" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Verifica e accedi</button>
      </form>
      <form method="post" class="mt-2">
        <input type="hidden" name="reinvia" value="1">
        <button type="submit" class="btn btn-link w-100 small">Non hai ricevuto il codice? Invialo di nuovo</button>
      </form>
      <p class="text-center mt-2"><a href="login.php" class="small text-muted">Torna al login</a></p>
    </div>
  </div>
</div>
</body>
</html>
