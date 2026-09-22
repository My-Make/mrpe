<?php
$titolo_pagina = 'Ordini di Modifica (ECO)';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$statoFiltro = trim($_GET['stato'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;
$statiValidi = ['bozza','in_revisione','approvato','rifiutato','implementato','annullato'];
$etichetteStato = ['bozza'=>'Bozza','in_revisione'=>'In revisione','approvato'=>'Approvato','rifiutato'=>'Rifiutato','implementato'=>'Implementato','annullato'=>'Annullato'];
$coloriStato = ['bozza'=>'secondary','in_revisione'=>'info','approvato'=>'primary','rifiutato'=>'danger','implementato'=>'success','annullato'=>'dark'];

$sqlBase = " FROM eco e WHERE e.azienda_id = ?";
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (e.numero_eco LIKE ? OR e.titolo LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}
if ($statoFiltro !== '' && in_array($statoFiltro, $statiValidi, true)) {
    $sqlBase .= " AND e.stato = ?";
    $params[] = $statoFiltro;
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleEco = (int) $stmtTot->fetch()['c'];

$sql = "SELECT e.*,
        CASE WHEN e.tipo_oggetto='componente' THEN c.codice_interno ELSE bc.codice_interno END codice_oggetto,
        CASE WHEN e.tipo_oggetto='componente' THEN c.descrizione ELSE bc.descrizione END descrizione_oggetto,
        r.nome richiedente_nome, r.cognome richiedente_cognome
        FROM eco e
        LEFT JOIN componenti c ON e.tipo_oggetto='componente' AND c.id = e.oggetto_id
        LEFT JOIN bom b ON e.tipo_oggetto='bom' AND b.id = e.oggetto_id
        LEFT JOIN componenti bc ON bc.id = b.componente_padre_id
        LEFT JOIN utenti r ON r.id = e.richiedente_id
        WHERE e.azienda_id = ?" . ($ricerca !== '' ? " AND (e.numero_eco LIKE ? OR e.titolo LIKE ?)" : "")
        . ($statoFiltro !== '' && in_array($statoFiltro, $statiValidi, true) ? " AND e.stato = ?" : "")
        . " ORDER BY e.data_richiesta DESC LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$elenco = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-pencil-square"></i> Ordini di Modifica (ECO)</h4>
  <?php if (!is_sola_lettura()): ?><a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuovo ECO</a><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-6"><input type="text" name="q" class="form-control" placeholder="Cerca per numero o titolo..." value="<?= h($ricerca) ?>"></div>
    <div class="col-md-3">
      <select name="stato" class="form-select">
        <option value="">Tutti gli stati</option>
        <?php foreach ($statiValidi as $s): ?>
        <option value="<?= $s ?>" <?= $statoFiltro===$s?'selected':'' ?>><?= h($etichetteStato[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Cerca</button></div>
  </form>
</div>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Numero</th><th>Titolo</th><th>Oggetto</th><th>Priorità</th><th>Stato</th><th>Richiedente</th><th>Data</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($elenco as $e): ?>
    <tr>
      <td><?= h($e['numero_eco']) ?></td>
      <td><?= h($e['titolo']) ?></td>
      <td><?= h($e['tipo_oggetto']==='componente'?'Componente':'BOM') ?>: <?= h($e['codice_oggetto']) ?> - <?= h($e['descrizione_oggetto']) ?></td>
      <td><?= h(ucfirst($e['priorita'])) ?></td>
      <td><span class="badge bg-<?= $coloriStato[$e['stato']] ?>"><?= h($etichetteStato[$e['stato']]) ?></span></td>
      <td><?= h(trim(($e['richiedente_nome'] ?? '') . ' ' . ($e['richiedente_cognome'] ?? ''))) ?: '-' ?></td>
      <td class="small"><?= date('d/m/Y', strtotime($e['data_richiesta'])) ?></td>
      <td><a href="view.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary">Apri</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$elenco): ?><tr><td colspan="8" class="text-center text-muted py-4">Nessun ECO presente.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?= paginazione_html($pagina, $totaleEco, $perPagina, ['q' => $ricerca, 'stato' => $statoFiltro]) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
