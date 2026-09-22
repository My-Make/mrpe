<?php
$titolo_pagina = 'Tecnologie';
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
            $ordinamento = (int) ($_POST['ordinamento'] ?: 0);

            if ($codice === '' || $descrizione === '') {
                $errore = 'Codice e descrizione sono obbligatori.';
            } elseif ($id) {
                $pdo->prepare("UPDATE tecnologie SET codice=?, descrizione=?, ordinamento=? WHERE id=? AND azienda_id=?")->execute([$codice, $descrizione, $ordinamento, $id, azienda_id()]);
            } else {
                $pdo->prepare("INSERT INTO tecnologie (azienda_id, codice, descrizione, ordinamento) VALUES (?,?,?,?)")->execute([azienda_id(), $codice, $descrizione, $ordinamento]);
            }
            if (!$errore) {
                $_SESSION['flash_msg'] = 'Tecnologia salvata.'; $_SESSION['flash_type'] = 'success';
                header('Location: tecnologie.php'); exit;
            }
        } elseif ($azione === 'imposta_predefinita') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tecnologie SET is_predefinita = 0 WHERE azienda_id = ?")->execute([azienda_id()]);
            $pdo->prepare("UPDATE tecnologie SET is_predefinita = 1 WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            $pdo->commit();
            header('Location: tecnologie.php'); exit;
        } elseif ($azione === 'attiva_disattiva') {
            $pdo->prepare("UPDATE tecnologie SET attivo = 1 - attivo WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            header('Location: tecnologie.php'); exit;
        } elseif ($azione === 'elimina') {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE tecnologia_id = ? AND azienda_id = ?");
            $stmt->execute([(int) $_POST['id'], azienda_id()]);
            if ($stmt->fetch()['c'] > 0) {
                $_SESSION['flash_msg'] = 'Impossibile eliminare: la tecnologia è usata da uno o più componenti. Disattivala invece.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $pdo->prepare("DELETE FROM tecnologie WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
                $_SESSION['flash_msg'] = 'Tecnologia eliminata.'; $_SESSION['flash_type'] = 'success';
            }
            header('Location: tecnologie.php'); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM tecnologie WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$modificaId, azienda_id()]);
    $inModifica = $stmt->fetch();
}

$tecnologie = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM componenti c WHERE c.tecnologia_id = t.id) n_componenti
                            FROM tecnologie t WHERE t.azienda_id = ? ORDER BY t.ordinamento, t.descrizione");
$tecnologie->execute([azienda_id()]); $tecnologie = $tecnologie->fetchAll();
?>
<h4><i class="bi bi-cpu"></i> Tecnologie</h4>
<p class="text-muted">Tecnologia di montaggio/costruzione del componente (es. SMD, THT), usata come attributo informativo nell'anagrafica.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th><th>Default</th><th>N° componenti</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tecnologie as $t): ?>
    <tr class="<?= $t['attivo'] ? '' : 'text-muted' ?>">
      <td><?= h($t['codice']) ?></td>
      <td><?= h($t['descrizione']) ?></td>
      <td>
        <?php if ($t['is_predefinita']): ?>
          <span class="badge bg-success">Predefinita</span>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="imposta_predefinita">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary">Imposta default</button>
          </form>
        <?php endif; ?>
      </td>
      <td><?= $t['n_componenti'] ?></td>
      <td>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="attiva_disattiva">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm <?= $t['attivo'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>"><?= $t['attivo'] ? 'Attiva' : 'Disattiva' ?></button>
        </form>
      </td>
      <td class="text-end">
        <a href="?modifica=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <?php if ($t['n_componenti'] == 0): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questa tecnologia?');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="elimina">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tecnologie): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessuna tecnologia configurata.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><?= $inModifica ? 'Modifica tecnologia' : 'Nuova tecnologia' ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="salva">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?? 0 ?>">
    <div class="col-md-3"><label class="form-label small">Codice</label><input type="text" name="codice" class="form-control" required maxlength="20" value="<?= h($inModifica['codice'] ?? '') ?>" placeholder="es. SMD"></div>
    <div class="col-md-5"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" required value="<?= h($inModifica['descrizione'] ?? '') ?>" placeholder="es. SMD - Surface Mount Device"></div>
    <div class="col-md-2"><label class="form-label small">Ordinamento</label><input type="number" name="ordinamento" class="form-control" value="<?= h((string)($inModifica['ordinamento'] ?? 0)) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <?php if ($inModifica): ?><a href="tecnologie.php" class="small">Annulla modifica</a><?php endif; ?>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
