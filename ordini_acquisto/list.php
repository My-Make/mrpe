<?php
$titolo_pagina = 'Ordini di Acquisto';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$statoFiltro = trim($_GET['stato'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;
$statiValidi = ['bozza','inviato','confermato','parziale','ricevuto','annullato'];

$sqlBase = " FROM ordini_acquisto o JOIN fornitori f ON f.id = o.fornitore_id WHERE o.azienda_id = ?";
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (o.numero_ordine LIKE ? OR f.ragione_sociale LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}
if ($statoFiltro !== '' && in_array($statoFiltro, $statiValidi, true)) {
    $sqlBase .= " AND o.stato = ?";
    $params[] = $statoFiltro;
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleOrdini = (int) $stmtTot->fetch()['c'];

$ordini = $pdo->prepare("SELECT o.*, f.ragione_sociale, (SELECT COUNT(*) FROM ordini_acquisto_righe r WHERE r.ordine_id=o.id) n_righe"
    . $sqlBase . " ORDER BY o.data_ordine DESC LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina));
$ordini->execute($params); $ordini = $ordini->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-cart"></i> Ordini di Acquisto</h4>
  <?php if (!is_sola_lettura()): ?><a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuovo ordine</a><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-6"><input type="text" name="q" class="form-control" placeholder="Cerca per numero ordine o fornitore..." value="<?= h($ricerca) ?>"></div>
    <div class="col-md-3">
      <select name="stato" class="form-select">
        <option value="">Tutti gli stati</option>
        <?php foreach ($statiValidi as $s): ?>
        <option value="<?= $s ?>" <?= $statoFiltro===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Cerca</button></div>
  </form>
</div>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Numero</th><th>Fornitore</th><th>Data ordine</th><th>Consegna prevista</th><th>Stato</th><th class="text-end">Righe</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($ordini as $o): ?>
    <tr>
      <td><?= h($o['numero_ordine']) ?></td>
      <td><?= h($o['ragione_sociale']) ?></td>
      <td><?= date('d/m/Y', strtotime($o['data_ordine'])) ?></td>
      <td><?= $o['data_consegna_prevista'] ? date('d/m/Y', strtotime($o['data_consegna_prevista'])) : '-' ?></td>
      <td>
        <?php $colori = ['bozza'=>'secondary','inviato'=>'info','confermato'=>'primary','parziale'=>'warning','ricevuto'=>'success','annullato'=>'danger']; ?>
        <span class="badge bg-<?= $colori[$o['stato']] ?> text-<?= $o['stato']==='parziale'?'dark':'white' ?>"><?= h($o['stato']) ?></span>
      </td>
      <td class="text-end"><?= $o['n_righe'] ?></td>
      <td><a href="view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">Apri</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$ordini): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessun ordine di acquisto presente.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?= paginazione_html($pagina, $totaleOrdini, $perPagina, ['q' => $ricerca, 'stato' => $statoFiltro]) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
