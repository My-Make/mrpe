<?php
$titolo_pagina = 'Ubicazioni';
require_once __DIR__ . '/../includes/header.php';

$magazzinoId = (int) ($_GET['magazzino_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM magazzini WHERE id = ? AND azienda_id = ?");
$stmt->execute([$magazzinoId, azienda_id()]);
$magazzino = $stmt->fetch();
if (!$magazzino) { die('Magazzino non trovato.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pdo->prepare("INSERT INTO ubicazioni (magazzino_id, codice, descrizione) VALUES (?,?,?)")
        ->execute([$magazzinoId, trim($_POST['codice']), trim($_POST['descrizione'])]);
    header('Location: ubicazioni.php?magazzino_id=' . $magazzinoId); exit;
}

$ubicazioni = $pdo->prepare("SELECT * FROM ubicazioni WHERE magazzino_id = ? ORDER BY codice");
$ubicazioni->execute([$magazzinoId]); $ubicazioni = $ubicazioni->fetchAll();
?>
<h4><i class="bi bi-geo-alt"></i> Ubicazioni - <?= h($magazzino['descrizione']) ?></h4>
<div class="card">
<table class="table table-sm mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th></tr></thead>
  <tbody>
  <?php foreach ($ubicazioni as $u): ?>
    <tr><td><?= h($u['codice']) ?></td><td><?= h($u['descrizione']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$ubicazioni): ?><tr><td colspan="2" class="text-center text-muted py-3">Nessuna ubicazione definita.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<div class="card p-3 mt-3">
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-3"><label class="form-label small">Codice (es. A-01-03)</label><input type="text" name="codice" class="form-control" required></div>
    <div class="col-md-5"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Aggiungi</button></div>
  </form>
</div>
<a href="list.php" class="btn btn-outline-secondary mt-3">Torna ai magazzini</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
