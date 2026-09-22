<?php
$titolo_pagina = 'Fornitori';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;

$sqlBase = " FROM fornitori WHERE azienda_id = ?";
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (ragione_sociale LIKE ? OR codice LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleFornitori = (int) $stmtTot->fetch()['c'];

$fornitori = $pdo->prepare("SELECT *" . $sqlBase . " ORDER BY ragione_sociale LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina));
$fornitori->execute($params); $fornitori = $fornitori->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-truck"></i> Fornitori e Distributori</h4>
  <?php if (!is_sola_lettura()): ?><a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuovo fornitore</a><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="Cerca per ragione sociale o codice..." value="<?= h($ricerca) ?>"></div>
    <div class="col-md-4"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Cerca</button></div>
  </form>
</div>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Ragione sociale</th><th>Codice</th><th>Email</th><th>Telefono</th><th>Distributore</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($fornitori as $f): ?>
    <tr>
      <td><?= h($f['ragione_sociale']) ?></td>
      <td><?= h($f['codice']) ?></td>
      <td><?= h($f['email']) ?></td>
      <td><?= h($f['telefono']) ?></td>
      <td><?= $f['is_distributore'] ? '<span class="badge bg-info text-dark">Sì</span>' : '' ?></td>
      <td class="text-end">
        <?php if (!is_sola_lettura()): ?><a href="form.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a><?php endif; ?>
        <?php if ($f['is_distributore']): ?><a href="api_config.php?fornitore_id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-plug"></i> API</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$fornitori): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessun fornitore trovato.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?= paginazione_html($pagina, $totaleFornitori, $perPagina, ['q' => $ricerca]) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
