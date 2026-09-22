<?php
$titolo_pagina = 'Utenti azienda';
require_once __DIR__ . '/../includes/header_superadmin.php';

$aziendaId = (int) ($_GET['azienda_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
$stmt->execute([$aziendaId]);
$azienda = $stmt->fetch();
if (!$azienda) { die('Azienda non trovata.'); }

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'crea_utente') {
            $username = trim($_POST['username']);
            $password = $_POST['password'] ?? '';
            $nome = trim($_POST['nome']);
            $cognome = trim($_POST['cognome']);
            $email = trim($_POST['email']);
            $ruolo = $_POST['ruolo'] === 'admin' ? 'admin' : 'user';
            $usa2fa = isset($_POST['usa_2fa']) ? 1 : 0;

            if ($username === '' || $nome === '' || $cognome === '') {
                $errore = 'Compila tutti i campi obbligatori.';
            } elseif ($usa2fa && $email === '') {
                $errore = 'Per abilitare la verifica in due passaggi (2FA) serve un indirizzo email.';
            } elseif (strlen($password) < 8) {
                $errore = 'La password deve avere almeno 8 caratteri.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO utenti (azienda_id, username, password_hash, nome, cognome, email, ruolo, usa_2fa) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$aziendaId, $username, password_hash($password, PASSWORD_DEFAULT), $nome, $cognome, $email, $ruolo, $usa2fa]);
                log_attivita($pdo, 'crea_utente_da_superadmin', 'utenti', (int)$pdo->lastInsertId(), "azienda {$azienda['codice_azienda']}");
                $_SESSION['flash_msg'] = 'Utente creato.'; $_SESSION['flash_type'] = 'success';
                header('Location: utenti.php?azienda_id=' . $aziendaId); exit;
            }
        } elseif ($azione === 'toggle_2fa') {
            $utenteId = (int) $_POST['utente_id'];
            $stmt = $pdo->prepare("SELECT email, usa_2fa FROM utenti WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$utenteId, $aziendaId]);
            $target = $stmt->fetch();
            if ($target && !$target['usa_2fa'] && empty($target['email'])) {
                $_SESSION['flash_msg'] = 'Impossibile abilitare il 2FA: questo utente non ha un indirizzo email.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $pdo->prepare("UPDATE utenti SET usa_2fa = 1 - usa_2fa WHERE id = ? AND azienda_id = ?")->execute([$utenteId, $aziendaId]);
            }
            header('Location: utenti.php?azienda_id=' . $aziendaId); exit;
        } elseif ($azione === 'reset_password') {
            $utenteId = (int) $_POST['utente_id'];
            $nuovaPassword = $_POST['nuova_password'] ?? '';
            if (strlen($nuovaPassword) < 8) {
                $errore = 'La nuova password deve avere almeno 8 caratteri.';
            } else {
                $pdo->prepare("UPDATE utenti SET password_hash = ? WHERE id = ? AND azienda_id = ?")
                    ->execute([password_hash($nuovaPassword, PASSWORD_DEFAULT), $utenteId, $aziendaId]);
                log_attivita($pdo, 'reset_password_da_superadmin', 'utenti', $utenteId);
                $_SESSION['flash_msg'] = 'Password aggiornata.'; $_SESSION['flash_type'] = 'success';
                header('Location: utenti.php?azienda_id=' . $aziendaId); exit;
            }
        } elseif ($azione === 'attiva_disattiva') {
            $utenteId = (int) $_POST['utente_id'];
            $pdo->prepare("UPDATE utenti SET attivo = 1 - attivo WHERE id = ? AND azienda_id = ?")->execute([$utenteId, $aziendaId]);
            header('Location: utenti.php?azienda_id=' . $aziendaId); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Username già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$utenti = $pdo->prepare("SELECT * FROM utenti WHERE azienda_id = ? ORDER BY ruolo DESC, cognome, nome");
$utenti->execute([$aziendaId]);
$utenti = $utenti->fetchAll();
?>
<h4><i class="bi bi-people"></i> Utenti - <?= h($azienda['ragione_sociale']) ?> <span class="badge bg-secondary"><?= h($azienda['codice_azienda']) ?></span></h4>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Username</th><th>Nome</th><th>Ruolo</th><th>Stato</th><th>2FA</th><th>Ultimo accesso</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($utenti as $u): ?>
    <tr>
      <td><?= h($u['username']) ?></td>
      <td><?= h($u['nome'] . ' ' . $u['cognome']) ?></td>
      <td><span class="badge bg-<?= $u['ruolo']==='admin'?'danger':'secondary' ?>"><?= h($u['ruolo']) ?></span></td>
      <td><?= $u['attivo'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Disattivo</span>' ?></td>
      <td>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="toggle_2fa">
          <input type="hidden" name="utente_id" value="<?= $u['id'] ?>">
          <button class="btn btn-sm <?= $u['usa_2fa'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" title="<?= $u['usa_2fa'] ? 'Disattiva 2FA' : 'Attiva 2FA' ?>"><i class="bi bi-shield-lock"></i> <?= $u['usa_2fa'] ? 'Attivo' : 'Disattivo' ?></button>
        </form>
      </td>
      <td class="small"><?= $u['ultimo_accesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_accesso'])) : '-' ?></td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalReset<?= $u['id'] ?>"><i class="bi bi-key"></i></button>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="attiva_disattiva">
          <input type="hidden" name="utente_id" value="<?= $u['id'] ?>">
          <button class="btn btn-sm <?= $u['attivo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"><i class="bi bi-power"></i></button>
        </form>

        <div class="modal fade" id="modalReset<?= $u['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="azione" value="reset_password">
                <input type="hidden" name="utente_id" value="<?= $u['id'] ?>">
                <div class="modal-header"><h6 class="modal-title">Reset password - <?= h($u['username']) ?></h6></div>
                <div class="modal-body">
                  <label class="form-label small">Nuova password (min 8 caratteri)</label>
                  <input type="password" name="nuova_password" class="form-control" required>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                  <button class="btn btn-primary">Aggiorna password</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$utenti): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessun utente per questa azienda.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><i class="bi bi-plus-lg"></i> Nuovo utente per questa azienda</h6>
  <form method="post" class="row g-3">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="crea_utente">
    <div class="col-md-3"><label class="form-label small">Username *</label><input type="text" name="username" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label small">Password (min 8 car.) *</label><input type="password" name="password" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label small">Nome *</label><input type="text" name="nome" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label small">Cognome *</label><input type="text" name="cognome" class="form-control" required></div>
    <div class="col-md-2">
      <label class="form-label small">Ruolo</label>
      <select name="ruolo" class="form-select"><option value="admin">Admin</option><option value="user">User</option></select>
    </div>
    <div class="col-md-4"><label class="form-label small">Email</label><input type="email" name="email" class="form-control"></div>
    <div class="col-md-3 form-check mt-4"><input type="checkbox" name="usa_2fa" class="form-check-input" id="usa2faNuovo"><label class="form-check-label small" for="usa2faNuovo">Richiedi 2FA via email</label></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-save"></i> Crea</button></div>
  </form>
</div>

<a href="aziende.php" class="btn btn-outline-secondary mt-3">Torna alle aziende</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
