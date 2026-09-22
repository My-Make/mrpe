<?php
$titolo_pagina = 'Il mio profilo';
require_once __DIR__ . '/../includes/header_superadmin.php';

$stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email']);
    $usa2fa = isset($_POST['usa_2fa']) ? 1 : 0;
    $password = $_POST['password'] ?? '';

    if ($usa2fa && $email === '') {
        $errore = 'Per abilitare la verifica in due passaggi (2FA) serve un indirizzo email.';
    } else {
        try {
            if ($password !== '') {
                if (strlen($password) < 8) { throw new Exception('La nuova password deve avere almeno 8 caratteri.'); }
                $pdo->prepare("UPDATE utenti SET email=?, usa_2fa=?, password_hash=? WHERE id=?")
                    ->execute([$email, $usa2fa, password_hash($password, PASSWORD_DEFAULT), $me['id']]);
            } else {
                $pdo->prepare("UPDATE utenti SET email=?, usa_2fa=? WHERE id=?")->execute([$email, $usa2fa, $me['id']]);
            }
            log_attivita($pdo, 'modifica_profilo_superadmin', 'utenti', $me['id']);
            $_SESSION['flash_msg'] = 'Profilo aggiornato.'; $_SESSION['flash_type'] = 'success';
            header('Location: profilo.php'); exit;
        } catch (Exception $e) {
            $errore = $e->getMessage();
        }
    }
}
?>
<h4><i class="bi bi-person-circle"></i> Il mio profilo</h4>
<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<form method="post" class="card p-4 mt-3" style="max-width:500px;">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= h($me['username']) ?>" disabled></div>
  <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($me['email']) ?>"></div>
  <div class="mb-3 form-check">
    <input type="checkbox" name="usa_2fa" class="form-check-input" id="usa2fa" <?= $me['usa_2fa'] ? 'checked' : '' ?>>
    <label class="form-check-label" for="usa2fa">Richiedi verifica in due passaggi (2FA) via email al login</label>
  </div>
  <div class="mb-3"><label class="form-label">Nuova password (lascia vuoto per non modificare)</label><input type="password" name="password" class="form-control"></div>
  <button class="btn btn-primary"><i class="bi bi-save"></i> Salva</button>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
