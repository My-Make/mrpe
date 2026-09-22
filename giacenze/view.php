<?php
$titolo_pagina = 'Giacenze';
require_once __DIR__ . '/../includes/header.php';

$magazzinoFiltro = (int) ($_GET['magazzino_id'] ?? 0);
$ricerca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;

$sqlBase = " FROM giacenze g
        JOIN componenti c ON c.id = g.componente_id
        JOIN unita_misura um ON um.id = c.um_base_id
        JOIN magazzini m ON m.id = g.magazzino_id
        WHERE g.azienda_id = ? AND g.quantita != 0";
$params = [azienda_id()];
if ($magazzinoFiltro) { $sqlBase .= " AND g.magazzino_id = ?"; $params[] = $magazzinoFiltro; }
if ($ricerca !== '') { $sqlBase .= " AND (c.codice_interno LIKE ? OR c.descrizione LIKE ?)"; $params[] = "%$ricerca%"; $params[] = "%$ricerca%"; }

// il conteggio totale va fatto sul raggruppamento (componente+magazzino), non sulle righe grezze di giacenze
$stmtTot = $pdo->prepare("SELECT COUNT(*) c FROM (SELECT c.id" . $sqlBase . " GROUP BY c.id, g.magazzino_id) t");
$stmtTot->execute($params);
$totaleRighe = (int) $stmtTot->fetch()['c'];

$sql = "SELECT c.id componente_id, c.codice_interno, c.descrizione, um.codice um_codice,
        g.magazzino_id, m.descrizione magazzino_desc, SUM(g.quantita) quantita"
        . $sqlBase . " GROUP BY c.id, g.magazzino_id ORDER BY c.codice_interno LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina);
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$righe = $stmt->fetchAll();

$magazzini = $pdo->prepare("SELECT * FROM magazzini WHERE azienda_id = ? AND attivo=1 ORDER BY descrizione");
$magazzini->execute([azienda_id()]); $magazzini = $magazzini->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-boxes"></i> Giacenze di magazzino</h4>
  <?php if (!is_sola_lettura()): ?><a href="movimento.php" class="btn btn-primary"><i class="bi bi-arrow-left-right"></i> Nuovo movimento</a><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-4">
      <select name="magazzino_id" class="form-select" onchange="this.form.submit()">
        <option value="0">Tutti i magazzini</option>
        <?php foreach ($magazzini as $m): ?>
        <option value="<?= $m['id'] ?>" <?= $magazzinoFiltro==$m['id']?'selected':'' ?>><?= h($m['descrizione']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5"><input type="text" name="q" class="form-control" placeholder="Cerca per codice o descrizione componente..." value="<?= h($ricerca) ?>"></div>
    <div class="col-md-3"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Cerca</button></div>
  </form>
</div>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Componente</th><th>Descrizione</th><th>Magazzino</th><th class="text-end">Quantità</th><th>UM</th></tr></thead>
  <tbody>
  <?php foreach ($righe as $r): ?>
    <tr>
      <td><a href="../componenti/view.php?id=<?= $r['componente_id'] ?>"><?= h($r['codice_interno']) ?></a></td>
      <td><?= h($r['descrizione']) ?></td>
      <td><?= h($r['magazzino_desc']) ?></td>
      <td class="text-end"><?= number_format($r['quantita'],2,',','.') ?></td>
      <td><?= h($r['um_codice']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$righe): ?><tr><td colspan="5" class="text-center text-muted py-4">Nessuna giacenza presente.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?= paginazione_html($pagina, $totaleRighe, $perPagina, ['magazzino_id' => $magazzinoFiltro, 'q' => $ricerca]) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
