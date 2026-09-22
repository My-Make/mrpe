<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    header('Location: ../index.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codiceAzienda = trim($_POST['codice_azienda'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($codiceAzienda === '') {
        // ------------------------------------------------------------
        // Accesso SUPER ADMIN (nessuna azienda associata)
        // ------------------------------------------------------------
        $stmt = $pdo->prepare("SELECT * FROM utenti WHERE username = ? AND ruolo = 'superadmin' AND attivo = 1");
        $stmt->execute([$username]);
        $utente = $stmt->fetch();

        if ($utente && password_verify($password, $utente['password_hash'])) {
            if ($utente['usa_2fa']) {
                $_SESSION['pending_2fa_user_id'] = $utente['id'];
                $_SESSION['pending_2fa_ruolo'] = 'superadmin';
                $_SESSION['pending_2fa_azienda_id'] = null;
                $_SESSION['pending_2fa_azienda_nome'] = null;
                $inviata = invia_codice_2fa($pdo, $utente);
                header('Location: verifica_2fa.php' . ($inviata ? '' : '?invio_falito=1'));
                exit;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $utente['id'];
            $_SESSION['username'] = $utente['username'];
            $_SESSION['nome'] = $utente['nome'];
            $_SESSION['cognome'] = $utente['cognome'];
            $_SESSION['ruolo'] = 'superadmin';
            $_SESSION['azienda_id'] = null;
            $_SESSION['azienda_nome'] = null;
            $_SESSION['permessi_sezioni'] = [];
            $_SESSION['lingua'] = $utente['lingua'] ?? 'it';

            $pdo->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")->execute([$utente['id']]);
            log_attivita($pdo, 'login_superadmin', 'utenti', $utente['id']);

            header('Location: ../superadmin/index.php');
            exit;
        }
        $errore = t('login.errore_superadmin');
    } else {
        // ------------------------------------------------------------
        // Accesso utente di un'azienda
        // ------------------------------------------------------------
        $stmt = $pdo->prepare("SELECT * FROM aziende WHERE codice_azienda = ? AND attivo = 1");
        $stmt->execute([$codiceAzienda]);
        $azienda = $stmt->fetch();

        if (!$azienda) {
            $errore = t('login.errore_azienda_non_valido');
        } else {
            $stmt = $pdo->prepare("SELECT * FROM utenti WHERE username = ? AND azienda_id = ? AND ruolo IN ('admin','user') AND attivo = 1");
            $stmt->execute([$username, $azienda['id']]);
            $utente = $stmt->fetch();

            if ($utente && password_verify($password, $utente['password_hash'])) {
                if ($utente['usa_2fa']) {
                    $_SESSION['pending_2fa_user_id'] = $utente['id'];
                    $_SESSION['pending_2fa_ruolo'] = $utente['ruolo'];
                    $_SESSION['pending_2fa_azienda_id'] = $azienda['id'];
                    $_SESSION['pending_2fa_azienda_nome'] = $azienda['ragione_sociale'];
                    $inviata = invia_codice_2fa($pdo, $utente);
                    header('Location: verifica_2fa.php' . ($inviata ? '' : '?invio_falito=1'));
                    exit;
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = $utente['id'];
                $_SESSION['username'] = $utente['username'];
                $_SESSION['nome'] = $utente['nome'];
                $_SESSION['cognome'] = $utente['cognome'];
                $_SESSION['ruolo'] = $utente['ruolo'];
                $_SESSION['azienda_id'] = $azienda['id'];
                $_SESSION['azienda_nome'] = $azienda['ragione_sociale'];
                $_SESSION['lingua'] = $utente['lingua'] ?? 'it';

                $pdo->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")->execute([$utente['id']]);
                carica_permessi_in_sessione($pdo, $utente['id']);
                log_attivita($pdo, 'login', 'utenti', $utente['id']);

                header('Location: ../index.php');
                exit;
            }
            $errore = t('login.errore_azienda');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h(lingua_corrente()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('login.titolo')) ?> - MRP Elettronica</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width:400px;">
  <div class="text-end mb-2">
    <?php foreach (LINGUE_DISPONIBILI as $codice => $nomeLingua): ?>
      <a href="../includes/cambia_lingua.php?lingua=<?= $codice ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="small <?= $codice === lingua_corrente() ? 'fw-bold text-dark' : 'text-muted' ?> text-decoration-none me-2"><?= h($nomeLingua) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-1 text-center">MRP Elettronica</h4>
      <p class="text-muted text-center mb-4"><?= h(t('login.sottotitolo_azienda')) ?></p>
      <?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label"><?= h(t('login.codice_azienda')) ?></label>
          <input type="text" name="codice_azienda" id="codiceAzienda" class="form-control" autofocus>
          <div class="form-text"><?= h(t('login.accedi_superadmin')) ?></div>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= h(t('login.username')) ?></label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= h(t('login.password')) ?></label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= h(t('login.accedi')) ?></button>
      </form>
      <p class="text-center text-muted mt-3 small">Prima volta? <a href="../install.php">Configura il Super Admin</a></p>
    </div>
  </div>
</div>
</body>
</html>
