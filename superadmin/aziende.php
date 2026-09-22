<?php
$titolo_pagina = 'Aziende';
require_once __DIR__ . '/../includes/header_superadmin.php';

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'crea_azienda') {
            $codiceAzienda = trim($_POST['codice_azienda']);
            $ragioneSociale = trim($_POST['ragione_sociale']);
            $emailAzienda = trim($_POST['email_azienda']);
            $usernameAdmin = trim($_POST['username_admin']);
            $passwordAdmin = $_POST['password_admin'] ?? '';
            $nomeAdmin = trim($_POST['nome_admin']);
            $cognomeAdmin = trim($_POST['cognome_admin']);
            $emailAdmin = trim($_POST['email_admin']);

            if ($codiceAzienda === '' || $ragioneSociale === '' || $usernameAdmin === '' || $nomeAdmin === '' || $cognomeAdmin === '') {
                $errore = 'Compila tutti i campi obbligatori (azienda e primo amministratore).';
            } elseif (strlen($passwordAdmin) < 8) {
                $errore = 'La password dell\'amministratore deve avere almeno 8 caratteri.';
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO aziende (codice_azienda, ragione_sociale, email) VALUES (?,?,?)");
                $stmt->execute([$codiceAzienda, $ragioneSociale, $emailAzienda]);
                $aziendaId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO utenti (azienda_id, username, password_hash, nome, cognome, email, ruolo) VALUES (?,?,?,?,?,?,'admin')");
                $stmt->execute([$aziendaId, $usernameAdmin, password_hash($passwordAdmin, PASSWORD_DEFAULT), $nomeAdmin, $cognomeAdmin, $emailAdmin]);

                crea_dati_iniziali_azienda($pdo, $aziendaId);

                log_attivita($pdo, 'crea_azienda', 'aziende', $aziendaId, $ragioneSociale);
                $pdo->commit();
                $_SESSION['flash_msg'] = "Azienda \"$ragioneSociale\" creata con il suo primo amministratore.";
                $_SESSION['flash_type'] = 'success';
                header('Location: aziende.php'); exit;
            }
        } elseif ($azione === 'modifica_azienda') {
            $id = (int) $_POST['id'];
            $codiceAzienda = trim($_POST['codice_azienda']);
            $ragioneSociale = trim($_POST['ragione_sociale']);
            $emailAzienda = trim($_POST['email_azienda']);
            if ($ragioneSociale === '' || $codiceAzienda === '') {
                $errore = 'Codice azienda e ragione sociale sono obbligatori.';
            } else {
                $pdo->prepare("UPDATE aziende SET codice_azienda=?, ragione_sociale=?, email=? WHERE id=?")->execute([$codiceAzienda, $ragioneSociale, $emailAzienda, $id]);
                log_attivita($pdo, 'modifica_azienda', 'aziende', $id, $ragioneSociale);
                $_SESSION['flash_msg'] = 'Azienda aggiornata. Se hai cambiato il Codice Azienda, ricordati di comunicarlo ai suoi utenti: da ora dovranno usare quello nuovo per accedere.';
                $_SESSION['flash_type'] = 'success';
                header('Location: aziende.php'); exit;
            }
        } elseif ($azione === 'attiva_disattiva') {
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE aziende SET attivo = 1 - attivo WHERE id = ?")->execute([$id]);
            log_attivita($pdo, 'toggle_azienda', 'aziende', $id);
            header('Location: aziende.php'); exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice azienda o username già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
    $stmt->execute([$modificaId]);
    $inModifica = $stmt->fetch();
}

$aziende = $pdo->query("SELECT a.*,
        (SELECT COUNT(*) FROM utenti u WHERE u.azienda_id = a.id AND u.ruolo='admin') n_admin,
        (SELECT COUNT(*) FROM utenti u WHERE u.azienda_id = a.id) n_utenti,
        (SELECT COUNT(*) FROM componenti c WHERE c.azienda_id = a.id) n_componenti
        FROM aziende a ORDER BY a.ragione_sociale")->fetchAll();
?>
<h4><i class="bi bi-buildings"></i> Aziende</h4>
<p class="text-muted">Ogni azienda ha i propri dati completamente separati (componenti, magazzini, ordini...). Gli utenti di un'azienda accedono con il relativo Codice Azienda.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Ragione sociale</th><th>Email</th><th class="text-end">Admin</th><th class="text-end">Utenti tot.</th><th class="text-end">Componenti</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($aziende as $a): ?>
    <tr class="<?= $a['attivo'] ? '' : 'text-muted' ?>">
      <td><strong><?= h($a['codice_azienda']) ?></strong></td>
      <td><?= h($a['ragione_sociale']) ?></td>
      <td><?= h($a['email']) ?></td>
      <td class="text-end"><?= $a['n_admin'] ?></td>
      <td class="text-end"><?= $a['n_utenti'] ?></td>
      <td class="text-end"><?= $a['n_componenti'] ?></td>
      <td><?= $a['attivo'] ? '<span class="badge bg-success">Attiva</span>' : '<span class="badge bg-secondary">Disattiva</span>' ?></td>
      <td class="text-end">
        <a href="utenti.php?azienda_id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Gestisci utenti"><i class="bi bi-people"></i></a>
        <a href="?modifica=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <form method="post" class="d-inline" onsubmit="return confirm('<?= $a['attivo'] ? 'Disattivare' : 'Riattivare' ?> questa azienda? <?= $a['attivo'] ? 'I suoi utenti non potranno più accedere.' : '' ?>');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="attiva_disattiva">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button class="btn btn-sm <?= $a['attivo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"><i class="bi bi-power"></i></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$aziende): ?><tr><td colspan="8" class="text-center text-muted py-4">Nessuna azienda creata.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($inModifica): ?>
<div class="card p-4 mt-3">
  <h6>Modifica azienda: <?= h($inModifica['codice_azienda']) ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="modifica_azienda">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?>">
    <div class="col-md-3"><label class="form-label small">Codice Azienda</label><input type="text" name="codice_azienda" class="form-control" required value="<?= h($inModifica['codice_azienda']) ?>"></div>
    <div class="col-md-4"><label class="form-label small">Ragione sociale</label><input type="text" name="ragione_sociale" class="form-control" required value="<?= h($inModifica['ragione_sociale']) ?>"></div>
    <div class="col-md-3"><label class="form-label small">Email</label><input type="email" name="email_azienda" class="form-control" value="<?= h($inModifica['email']) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <div class="alert alert-warning mt-3 mb-0 py-2">
    <i class="bi bi-exclamation-triangle"></i> Se cambi il Codice Azienda, tutti gli utenti di questa azienda dovranno usare il nuovo codice al prossimo accesso: quello vecchio smette di funzionare immediatamente. Comunicalo prima di salvare.
  </div>
  <a href="aziende.php" class="small">Annulla modifica</a>
</div>
<?php endif; ?>

<div class="card p-4 mt-3">
  <h6><i class="bi bi-plus-lg"></i> Nuova azienda</h6>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="crea_azienda">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label small">Codice Azienda *</label><input type="text" name="codice_azienda" class="form-control" required placeholder="es. ACME01"></div>
      <div class="col-md-4"><label class="form-label small">Ragione sociale *</label><input type="text" name="ragione_sociale" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label small">Email azienda</label><input type="email" name="email_azienda" class="form-control"></div>
    </div>
    <hr>
    <p class="small text-muted mb-2">Primo utente amministratore dell'azienda</p>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label small">Username *</label><input type="text" name="username_admin" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label small">Password (min 8 car.) *</label><input type="password" name="password_admin" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label small">Nome *</label><input type="text" name="nome_admin" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label small">Cognome *</label><input type="text" name="cognome_admin" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label small">Email</label><input type="email" name="email_admin" class="form-control"></div>
    </div>
    <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Crea azienda e amministratore</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
