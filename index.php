<?php
$titolo_pagina = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE attivo=1 AND azienda_id=?");
$stmt->execute([azienda_id()]); $nComponenti = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM (
        SELECT c.id, c.punto_riordino FROM componenti c
        LEFT JOIN giacenze g ON g.componente_id = c.id AND g.azienda_id = c.azienda_id
        WHERE c.attivo = 1 AND c.azienda_id = ?
        GROUP BY c.id, c.punto_riordino
        HAVING COALESCE(SUM(g.quantita),0) <= c.punto_riordino AND c.punto_riordino > 0
    ) t");
$stmt->execute([azienda_id()]); $nSottoScorta = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(g.quantita * c.prezzo_medio),0) v
        FROM giacenze g JOIN componenti c ON c.id = g.componente_id WHERE g.azienda_id = ?");
$stmt->execute([azienda_id()]); $valoreMagazzino = $stmt->fetch()['v'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM lotti WHERE stato = 'disponibile' AND azienda_id = ?");
$stmt->execute([azienda_id()]); $nLotti = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM ordini_acquisto WHERE stato IN ('bozza','inviato','confermato','parziale') AND azienda_id = ?");
$stmt->execute([azienda_id()]); $nOrdiniAperti = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT c.codice_interno, c.descrizione, c.punto_riordino, COALESCE(SUM(g.quantita),0) giacenza
        FROM componenti c LEFT JOIN giacenze g ON g.componente_id = c.id AND g.azienda_id = c.azienda_id
        WHERE c.attivo = 1 AND c.azienda_id = ?
        GROUP BY c.id, c.codice_interno, c.descrizione, c.punto_riordino
        HAVING giacenza <= c.punto_riordino AND c.punto_riordino > 0
        ORDER BY giacenza ASC LIMIT 8");
$stmt->execute([azienda_id()]); $sottoScorta = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT m.*, c.codice_interno, c.descrizione, mg.descrizione magazzino_desc
        FROM movimenti_magazzino m
        JOIN componenti c ON c.id = m.componente_id
        JOIN magazzini mg ON mg.id = m.magazzino_id
        WHERE m.azienda_id = ?
        ORDER BY m.data_movimento DESC LIMIT 8");
$stmt->execute([azienda_id()]); $ultimiMovimenti = $stmt->fetchAll();
?>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card stat-card p-3"><div class="text-muted small">Componenti attivi</div><div class="fs-3 fw-bold"><?= $nComponenti ?></div></div></div>
  <div class="col-md-3"><div class="card stat-card p-3"><div class="text-muted small">Sotto scorta minima</div><div class="fs-3 fw-bold text-danger"><?= $nSottoScorta ?></div></div></div>
  <div class="col-md-3"><div class="card stat-card p-3"><div class="text-muted small">Valore magazzino</div><div class="fs-3 fw-bold">&euro; <?= number_format($valoreMagazzino, 2, ',', '.') ?></div></div></div>
  <div class="col-md-3"><div class="card stat-card p-3"><div class="text-muted small">Ordini acquisto aperti</div><div class="fs-3 fw-bold"><?= $nOrdiniAperti ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card p-3">
      <h6><i class="bi bi-exclamation-triangle text-warning"></i> Componenti sotto punto di riordino</h6>
      <?php if (!$sottoScorta): ?>
        <p class="text-muted small mb-0">Nessun componente sotto scorta minima.</p>
      <?php else: ?>
      <table class="table table-sm">
        <thead><tr><th>Codice</th><th>Descrizione</th><th class="text-end">Giacenza</th><th class="text-end">Riordino</th></tr></thead>
        <tbody>
        <?php foreach ($sottoScorta as $r): ?>
          <tr>
            <td><?= h($r['codice_interno']) ?></td>
            <td><?= h($r['descrizione']) ?></td>
            <td class="text-end text-danger"><?= number_format($r['giacenza'],2) ?></td>
            <td class="text-end"><?= number_format($r['punto_riordino'],2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3">
      <h6><i class="bi bi-clock-history"></i> Ultimi movimenti di magazzino</h6>
      <table class="table table-sm">
        <thead><tr><th>Data</th><th>Componente</th><th>Tipo</th><th class="text-end">Qtà</th></tr></thead>
        <tbody>
        <?php foreach ($ultimiMovimenti as $m): ?>
          <tr>
            <td class="small"><?= date('d/m/Y H:i', strtotime($m['data_movimento'])) ?></td>
            <td class="small"><?= h($m['codice_interno']) ?></td>
            <td><span class="badge bg-<?= $m['tipo_movimento']==='carico'?'success':($m['tipo_movimento']==='scarico'?'danger':'secondary') ?>"><?= h($m['tipo_movimento']) ?></span></td>
            <td class="text-end"><?= number_format($m['quantita'],2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
