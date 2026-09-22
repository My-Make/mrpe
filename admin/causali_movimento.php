<?php
$titolo_pagina = 'Causali movimento';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$errore = '';
$tipiMovimento = ['tutti' => 'Tutti i tipi', 'carico' => 'Carico', 'scarico' => 'Scarico', 'trasferimento' => 'Trasferimento', 'rettifica' => 'Rettifica'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'salva') {
            $id = (int) ($_POST['id'] ?? 0);
            $codice = trim($_POST['codice']);
            $descrizione = trim($_POST['descrizione']);
            $tipoMovimento = $_POST['tipo_movimento'];
            $ordinamento = (int) ($_POST['ordinamento'] ?: 0);

            if ($codice === '' || $descrizione === '' || !isset($tipiMovimento[$tipoMovimento])) {
                $errore = 'Compila tutti i campi obbligatori.';
            } elseif ($id) {
                $pdo->prepare("UPDATE causali_movimento SET codice=?, descrizione=?, tipo_movimento=?, ordinamento=? WHERE id=? AND azienda_id=?")
                    ->execute([$codice, $descrizione, $tipoMovimento, $ordinamento, $id, azienda_id()]);
            } else {
                $pdo->prepare("INSERT INTO causali_movimento (azienda_id, codice, descrizione, tipo_movimento, ordinamento) VALUES (?,?,?,?,?)")
                    ->execute([azienda_id(), $codice, $descrizione, $tipoMovimento, $ordinamento]);
            }
            if (!$errore) {
                $_SESSION['flash_msg'] = 'Causale salvata.'; $_SESSION['flash_type'] = 'success';
                header('Location: causali_movimento.php'); exit;
            }
        } elseif ($azione === 'attiva_disattiva') {
            $pdo->prepare("UPDATE causali_movimento SET attivo = 1 - attivo WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            header('Location: causali_movimento.php'); exit;
        } elseif ($azione === 'elimina') {
            $pdo->prepare("DELETE FROM causali_movimento WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            $_SESSION['flash_msg'] = 'Causale eliminata.'; $_SESSION['flash_type'] = 'success';
            header('Location: causali_movimento.php'); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM causali_movimento WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$modificaId, azienda_id()]);
    $inModifica = $stmt->fetch();
}

$causali = $pdo->prepare("SELECT * FROM causali_movimento WHERE azienda_id = ? ORDER BY tipo_movimento, ordinamento, descrizione");
$causali->execute([azienda_id()]); $causali = $causali->fetchAll();
?>
<h4><i class="bi bi-arrow-left-right"></i> Causali di movimento</h4>
<p class="text-muted">Elenco delle causali proponibili quando si registra un movimento di magazzino manuale. "Tutti i tipi" la rende disponibile per carico, scarico, trasferimento e rettifica.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th><th>Tipo movimento</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($causali as $c): ?>
    <tr class="<?= $c['attivo'] ? '' : 'text-muted' ?>">
      <td><?= h($c['codice']) ?></td>
      <td><?= h($c['descrizione']) ?></td>
      <td><span class="badge bg-secondary"><?= h($tipiMovimento[$c['tipo_movimento']] ?? $c['tipo_movimento']) ?></span></td>
      <td>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="attiva_disattiva">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm <?= $c['attivo'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>"><?= $c['attivo'] ? 'Attiva' : 'Disattiva' ?></button>
        </form>
      </td>
      <td class="text-end">
        <a href="?modifica=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questa causale?');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="elimina">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$causali): ?><tr><td colspan="5" class="text-center text-muted py-4">Nessuna causale configurata.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><?= $inModifica ? 'Modifica causale' : 'Nuova causale' ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="salva">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?? 0 ?>">
    <div class="col-md-3"><label class="form-label small">Codice</label><input type="text" name="codice" class="form-control" required value="<?= h($inModifica['codice'] ?? '') ?>" placeholder="es. rettifica_inventario"></div>
    <div class="col-md-4"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" required value="<?= h($inModifica['descrizione'] ?? '') ?>" placeholder="es. Rettifica da inventario fisico"></div>
    <div class="col-md-2">
      <label class="form-label small">Tipo movimento</label>
      <select name="tipo_movimento" class="form-select">
        <?php foreach ($tipiMovimento as $val => $lbl): ?>
        <option value="<?= $val ?>" <?= ($inModifica['tipo_movimento'] ?? 'tutti')===$val?'selected':'' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label small">Ordinamento</label><input type="number" name="ordinamento" class="form-control" value="<?= h((string)($inModifica['ordinamento'] ?? 0)) ?>"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <?php if ($inModifica): ?><a href="causali_movimento.php" class="small">Annulla modifica</a><?php endif; ?>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
