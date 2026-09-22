<?php
$titolo_pagina = 'Lotti';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;

$sqlBase = " FROM lotti l JOIN componenti c ON c.id = l.componente_id JOIN fornitori f ON f.id = l.fornitore_id
        WHERE l.azienda_id = ?";
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (l.codice_lotto_interno LIKE ? OR l.codice_lotto_fornitore LIKE ? OR c.codice_interno LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleLotti = (int) $stmtTot->fetch()['c'];

$sql = "SELECT l.*, c.codice_interno, c.descrizione componente_desc, f.ragione_sociale" . $sqlBase
     . " ORDER BY l.data_ricezione DESC LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina);
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$lotti = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-upc-scan"></i> Tracciabilità Lotti</h4>
  <?php if (!is_sola_lettura()): ?><a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuovo lotto</a><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="Cerca per codice lotto interno, lotto fornitore o codice componente..." value="<?= h($ricerca) ?>"></div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Cerca</button></div>
  </form>
</div>

<div class="card">
<div class="table-responsive">
<table class="table table-hover mb-0">
  <thead><tr><th>Lotto interno</th><th>Componente</th><th>Fornitore</th><th>Lotto fornitore</th><th>DDT</th><th>Ricezione</th><th>Scadenza</th><th class="text-end">Iniziale</th><th class="text-end">Residua</th><th>Stato</th></tr></thead>
  <tbody>
  <?php foreach ($lotti as $l): ?>
    <tr>
      <td><strong><?= h($l['codice_lotto_interno']) ?></strong></td>
      <td><a href="../componenti/view.php?id=<?= $l['componente_id'] ?>"><?= h($l['codice_interno']) ?></a> <div class="small text-muted"><?= h($l['componente_desc']) ?></div></td>
      <td><?= h($l['ragione_sociale']) ?></td>
      <td><?= h($l['codice_lotto_fornitore']) ?></td>
      <td><?= h($l['ddt_riferimento']) ?></td>
      <td><?= date('d/m/Y', strtotime($l['data_ricezione'])) ?></td>
      <td><?= $l['data_scadenza'] ? date('d/m/Y', strtotime($l['data_scadenza'])) : '-' ?></td>
      <td class="text-end"><?= number_format($l['quantita_iniziale'],2,',','.') ?></td>
      <td class="text-end"><?= number_format($l['quantita_residua'],2,',','.') ?></td>
      <td>
        <?php $colori = ['disponibile'=>'success','quarantena'=>'warning','esaurito'=>'secondary','bloccato'=>'danger']; ?>
        <span class="badge bg-<?= $colori[$l['stato']] ?>"><?= h($l['stato']) ?></span>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lotti): ?><tr><td colspan="10" class="text-center text-muted py-4">Nessun lotto trovato.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>

<?= paginazione_html($pagina, $totaleLotti, $perPagina, ['q' => $ricerca]) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
