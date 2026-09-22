<?php
$titolo_pagina = 'Magazzini';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_admin();
    $pdo->prepare("INSERT INTO magazzini (azienda_id, codice, descrizione, indirizzo, tipo) VALUES (?,?,?,?,?)")
        ->execute([azienda_id(), trim($_POST['codice']), trim($_POST['descrizione']), trim($_POST['indirizzo']), $_POST['tipo']]);
    $_SESSION['flash_msg'] = 'Magazzino creato.'; $_SESSION['flash_type'] = 'success';
    header('Location: list.php'); exit;
}

$magazzini = $pdo->prepare("SELECT m.*, (SELECT COUNT(*) FROM ubicazioni u WHERE u.magazzino_id = m.id) n_ubicazioni FROM magazzini m WHERE m.azienda_id = ? ORDER BY m.codice");
$magazzini->execute([azienda_id()]); $magazzini = $magazzini->fetchAll();
?>
<h4><i class="bi bi-building"></i> Magazzini</h4>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th><th>Tipo</th><th>Ubicazioni</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($magazzini as $m): ?>
    <tr>
      <td><?= h($m['codice']) ?></td>
      <td><?= h($m['descrizione']) ?></td>
      <td><span class="badge bg-secondary"><?= h($m['tipo']) ?></span></td>
      <td><?= $m['n_ubicazioni'] ?></td>
      <td><?= $m['attivo'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-danger">Disattivo</span>' ?></td>
      <td><a href="ubicazioni.php?magazzino_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Ubicazioni</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if (is_admin()): ?>
<div class="card p-4 mt-3">
  <h6>Nuovo magazzino</h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-2"><label class="form-label small">Codice</label><input type="text" name="codice" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label small">Indirizzo</label><input type="text" name="indirizzo" class="form-control"></div>
    <div class="col-md-2">
      <label class="form-label small">Tipo</label>
      <select name="tipo" class="form-select">
        <?php foreach (['materie_prime'=>'Materie prime','semilavorati'=>'Semilavorati','prodotti_finiti'=>'Prodotti finiti','conto_lavoro'=>'Conto lavoro','quarantena'=>'Quarantena'] as $val=>$lbl): ?>
        <option value="<?= $val ?>"><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i></button></div>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
