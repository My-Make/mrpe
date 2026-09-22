<?php
$titolo_pagina = 'Utente';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$u = null;
$permessiAttuali = []; // ['componenti' => 'lettura', ...]
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, azienda_id()]);
    $u = $stmt->fetch();
    if (!$u) { die('Utente non trovato.'); }
    $stmt = $pdo->prepare("SELECT sezione, livello FROM utenti_permessi_sezioni WHERE utente_id = ?");
    $stmt->execute([$u['id']]);
    $permessiAttuali = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username']);
    $nome = trim($_POST['nome']);
    $cognome = trim($_POST['cognome']);
    $email = trim($_POST['email']);
    $ruolo = $_POST['ruolo'] === 'admin' ? 'admin' : 'user';
    $attivo = isset($_POST['attivo']) ? 1 : 0;
    $usa2fa = isset($_POST['usa_2fa']) ? 1 : 0;
    $permessiInviati = $_POST['permessi'] ?? []; // ['componenti' => 'lettura'|'scrittura'|'nessuno', ...]
    $password = $_POST['password'] ?? '';

    if ($username === '' || $nome === '' || $cognome === '') {
        $errore = 'Compila tutti i campi obbligatori.';
    } elseif ($usa2fa && $email === '') {
        $errore = 'Per abilitare la verifica in due passaggi (2FA) serve un indirizzo email.';
    } elseif (!$u && strlen($password) < 8) {
        $errore = 'La password deve avere almeno 8 caratteri.';
    } else {
        try {
            $pdo->beginTransaction();

            if ($u) {
                if ($password !== '') {
                    if (strlen($password) < 8) { throw new Exception('La nuova password deve avere almeno 8 caratteri.'); }
                    $pdo->prepare("UPDATE utenti SET username=?, nome=?, cognome=?, email=?, ruolo=?, attivo=?, usa_2fa=?, password_hash=? WHERE id=? AND azienda_id=?")
                        ->execute([$username, $nome, $cognome, $email, $ruolo, $attivo, $usa2fa, password_hash($password, PASSWORD_DEFAULT), $u['id'], azienda_id()]);
                } else {
                    $pdo->prepare("UPDATE utenti SET username=?, nome=?, cognome=?, email=?, ruolo=?, attivo=?, usa_2fa=? WHERE id=? AND azienda_id=?")
                        ->execute([$username, $nome, $cognome, $email, $ruolo, $attivo, $usa2fa, $u['id'], azienda_id()]);
                }
                $idFinale = $u['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO utenti (azienda_id, username, password_hash, nome, cognome, email, ruolo, attivo, usa_2fa) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([azienda_id(), $username, password_hash($password, PASSWORD_DEFAULT), $nome, $cognome, $email, $ruolo, $attivo, $usa2fa]);
                $idFinale = $pdo->lastInsertId();
            }

            // riscrive da zero i permessi per sezione di questo utente
            $pdo->prepare("DELETE FROM utenti_permessi_sezioni WHERE utente_id = ?")->execute([$idFinale]);
            $insertPermesso = $pdo->prepare("INSERT INTO utenti_permessi_sezioni (utente_id, sezione, livello) VALUES (?, ?, ?)");
            foreach (array_keys(SEZIONI_APP) as $codiceSezione) {
                $livello = $permessiInviati[$codiceSezione] ?? 'scrittura';
                if (!in_array($livello, ['nessuno', 'lettura', 'scrittura'], true)) { $livello = 'scrittura'; }
                if ($livello !== 'scrittura') { // 'scrittura' è il default implicito: non serve salvare la riga
                    $insertPermesso->execute([$idFinale, $codiceSezione, $livello]);
                }
            }

            log_attivita($pdo, $u ? 'modifica_utente' : 'crea_utente', 'utenti', $idFinale);
            $pdo->commit();
            $_SESSION['flash_msg'] = 'Utente salvato.'; $_SESSION['flash_type'] = 'success';
            header('Location: list.php'); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Username già esistente.' : $e->getMessage();
        }
    }
}
?>
<h4><i class="bi bi-people"></i> <?= $u ? 'Modifica utente' : 'Nuovo utente' ?></h4>
<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>
<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required value="<?= h($u['username'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required value="<?= h($u['nome'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Cognome *</label><input type="text" name="cognome" class="form-control" required value="<?= h($u['cognome'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($u['email'] ?? '') ?>"></div>
    <div class="col-md-3">
      <label class="form-label">Ruolo *</label>
      <select name="ruolo" id="ruoloSelect" class="form-select">
        <option value="user" <?= ($u['ruolo'] ?? 'user')==='user'?'selected':'' ?>>User</option>
        <option value="admin" <?= ($u['ruolo'] ?? '')==='admin'?'selected':'' ?>>Admin</option>
      </select>
    </div>
    <div class="col-md-3 form-check mt-4 pt-2">
      <input type="checkbox" name="attivo" class="form-check-input" id="att" <?= (!$u || $u['attivo']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="att">Utente attivo</label>
    </div>
    <div class="col-md-6">
      <label class="form-label"><?= $u ? 'Nuova password (lascia vuoto per non modificare)' : 'Password *' ?></label>
      <input type="password" name="password" class="form-control" <?= $u ? '' : 'required' ?>>
    </div>
    <div class="col-md-6 form-check mt-4 pt-2">
      <input type="checkbox" name="usa_2fa" class="form-check-input" id="usa2fa" <?= !empty($u['usa_2fa']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="usa2fa">Richiedi verifica in due passaggi (2FA) via email al login</label>
    </div>
  </div>

  <div id="blocco-permessi" class="mt-4 pt-3 border-top">
    <h6><i class="bi bi-shield-lock"></i> Permessi per sezione (si applicano solo agli utenti di ruolo "User" — gli amministratori hanno sempre accesso completo a tutto)</h6>
    <p class="text-muted small">Per ogni sezione scegli il livello di accesso: <strong>Nessuno</strong> la nasconde completamente (menu e pagine bloccate), <strong>Sola lettura</strong> permette di consultarla senza modificarla, <strong>Scrittura</strong> dà accesso completo.</p>

    <table class="table table-sm align-middle">
      <thead><tr><th>Sezione</th><th class="text-center" style="width:110px;">Nessuno</th><th class="text-center" style="width:110px;">Sola lettura</th><th class="text-center" style="width:110px;">Scrittura</th></tr></thead>
      <tbody>
      <?php foreach (SEZIONI_APP as $codice => $etichetta):
        $livelloAttuale = $permessiAttuali[$codice] ?? 'scrittura'; ?>
        <tr>
          <td><?= h($etichetta) ?></td>
          <?php foreach (['nessuno' => 'Nessuno', 'lettura' => 'Sola lettura', 'scrittura' => 'Scrittura'] as $val => $lbl): ?>
          <td class="text-center">
            <input type="radio" name="permessi[<?= h($codice) ?>]" value="<?= $val ?>" class="form-check-input"
                   <?= $livelloAttuale === $val ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Salva</button>
    <a href="list.php" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>

<script>
function aggiornaVisibilitaPermessi() {
  document.getElementById('blocco-permessi').style.display = document.getElementById('ruoloSelect').value === 'admin' ? 'none' : 'block';
}
document.getElementById('ruoloSelect').addEventListener('change', aggiornaVisibilitaPermessi);
aggiornaVisibilitaPermessi();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
