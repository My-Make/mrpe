<?php
$titolo_pagina = 'Unità di misura';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'salva') {
            $id = (int) ($_POST['id'] ?? 0);
            $codice = strtoupper(trim($_POST['codice']));
            $descrizione = trim($_POST['descrizione']);

            if ($codice === '' || $descrizione === '') {
                $errore = 'Codice e descrizione sono obbligatori.';
            } elseif ($id) {
                $pdo->prepare("UPDATE unita_misura SET codice=?, descrizione=? WHERE id=? AND azienda_id=?")->execute([$codice, $descrizione, $id, azienda_id()]);
            } else {
                $pdo->prepare("INSERT INTO unita_misura (azienda_id, codice, descrizione) VALUES (?,?,?)")->execute([azienda_id(), $codice, $descrizione]);
            }
            if (!$errore) {
                $_SESSION['flash_msg'] = 'Unità di misura salvata.'; $_SESSION['flash_type'] = 'success';
                header('Location: unita_misura.php'); exit;
            }
        } elseif ($azione === 'imposta_predefinita') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE unita_misura SET is_predefinita = 0 WHERE azienda_id = ?")->execute([azienda_id()]);
            $pdo->prepare("UPDATE unita_misura SET is_predefinita = 1 WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            $pdo->commit();
            header('Location: unita_misura.php'); exit;
        } elseif ($azione === 'elimina') {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE um_base_id = ? AND azienda_id = ?");
            $stmt->execute([(int) $_POST['id'], azienda_id()]);
            $usiComponenti = $stmt->fetch()['c'];
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti_unita_misura cu JOIN componenti c ON c.id=cu.componente_id WHERE cu.unita_misura_id = ? AND c.azienda_id = ?");
            $stmt->execute([(int) $_POST['id'], azienda_id()]);
            $usiAggiuntive = $stmt->fetch()['c'];
            if ($usiComponenti > 0 || $usiAggiuntive > 0) {
                $_SESSION['flash_msg'] = 'Impossibile eliminare: questa unità di misura è già in uso.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $pdo->prepare("DELETE FROM unita_misura WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
                $_SESSION['flash_msg'] = 'Unità di misura eliminata.'; $_SESSION['flash_type'] = 'success';
            }
            header('Location: unita_misura.php'); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM unita_misura WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$modificaId, azienda_id()]);
    $inModifica = $stmt->fetch();
}

$unita = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM componenti c WHERE c.um_base_id = u.id) n_componenti
                       FROM unita_misura u WHERE u.azienda_id = ? ORDER BY u.descrizione");
$unita->execute([azienda_id()]); $unita = $unita->fetchAll();
?>
<h4><i class="bi bi-rulers"></i> Unità di misura</h4>
<p class="text-muted">Unità disponibili per i componenti. Quella impostata come predefinita viene proposta automaticamente nella creazione di un nuovo componente.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th><th>Default</th><th>N° componenti (UM base)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($unita as $u): ?>
    <tr>
      <td><?= h($u['codice']) ?></td>
      <td><?= h($u['descrizione']) ?></td>
      <td>
        <?php if ($u['is_predefinita']): ?>
          <span class="badge bg-success">Predefinita</span>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="imposta_predefinita">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary">Imposta default</button>
          </form>
        <?php endif; ?>
      </td>
      <td><?= $u['n_componenti'] ?></td>
      <td class="text-end">
        <a href="?modifica=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <?php if ($u['n_componenti'] == 0): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questa unità di misura?');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="elimina">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$unita): ?><tr><td colspan="5" class="text-center text-muted py-4">Nessuna unità di misura configurata.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><?= $inModifica ? 'Modifica unità di misura' : 'Nuova unità di misura' ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="salva">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?? 0 ?>">
    <div class="col-md-3"><label class="form-label small">Codice (es. PZ, MT, KG)</label><input type="text" name="codice" class="form-control" required maxlength="10" value="<?= h($inModifica['codice'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" required value="<?= h($inModifica['descrizione'] ?? '') ?>"></div>
    <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <?php if ($inModifica): ?><a href="unita_misura.php" class="small">Annulla modifica</a><?php endif; ?>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
