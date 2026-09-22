<?php
require_once __DIR__ . '/includes/functions.php';

$msg = '';
$stmt = $pdo->query("SELECT COUNT(*) c FROM utenti WHERE ruolo = 'superadmin'");
$superadminEsiste = $stmt->fetch()['c'] > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$superadminEsiste) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (strlen($password) < 8) {
        $msg = 'La password deve avere almeno 8 caratteri.';
    } elseif ($username === '' || $nome === '' || $cognome === '') {
        $msg = 'Compila tutti i campi obbligatori.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO utenti (azienda_id, username, password_hash, nome, cognome, email, ruolo) VALUES (NULL,?,?,?,?,?, 'superadmin')");
            $stmt->execute([$username, $hash, $nome, $cognome, $email]);
            $superadminEsiste = true;
            $msg = 'Super Admin creato correttamente. Ora puoi accedere (lascia vuoto il Codice Azienda nel login) e creare la prima azienda.';
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Username già esistente.' : 'Errore: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Installazione MRP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:480px; margin-top:80px;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-3">Installazione MRP Elettronica</h4>
      <?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>

      <?php if ($superadminEsiste): ?>
        <p>L'utente Super Admin esiste già.</p>
        <a href="auth/login.php" class="btn btn-primary">Vai al login</a>
      <?php else: ?>
        <p class="text-muted">
          Crea il primo utente <strong>Super Admin</strong>. Assicurati di aver già importato <code>db.sql</code> nel database.
          Il Super Admin non gestisce direttamente i dati operativi (componenti, magazzini...): dal suo pannello dedicato crea le
          <strong>aziende</strong> che useranno l'applicazione e il relativo utente amministratore di ciascuna.
        </p>
        <form method="post">
          <div class="mb-2"><label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Password (min 8 caratteri)</label>
            <input type="password" name="password" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Nome</label>
            <input type="text" name="nome" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Cognome</label>
            <input type="text" name="cognome" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"></div>
          <button class="btn btn-primary w-100">Crea Super Admin</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
