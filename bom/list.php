<?php
$titolo_pagina = 'Distinte Base';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;

$sqlBase = " FROM bom b JOIN componenti c ON c.id = b.componente_padre_id WHERE b.azienda_id = ?";
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (c.codice_interno LIKE ? OR c.descrizione LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleBom = (int) $stmtTot->fetch()['c'];

$boms = $pdo->prepare("SELECT b.*, c.codice_interno, c.descrizione, (SELECT COUNT(*) FROM bom_righe br WHERE br.bom_id = b.id) n_righe,
    (SELECT e.id FROM eco e WHERE e.tipo_oggetto = 'bom' AND e.oggetto_id = b.id AND e.stato NOT IN ('implementato','rifiutato','annullato') ORDER BY e.id DESC LIMIT 1) eco_pendente_id,
    (SELECT e.numero_eco FROM eco e WHERE e.tipo_oggetto = 'bom' AND e.oggetto_id = b.id AND e.stato NOT IN ('implementato','rifiutato','annullato') ORDER BY e.id DESC LIMIT 1) eco_pendente_numero"
    . $sqlBase . " ORDER BY c.codice_interno, b.revisione DESC LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina));
$boms->execute($params); $boms = $boms->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-diagram-3"></i> <?= h(t('bom.titolo')) ?></h4>
  <form method="get" action="editor.php" class="d-flex gap-2" style="min-width:320px;">
    <div id="semilavoratoContainer" class="flex-fill"></div>
    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= h(t('bom.vai')) ?></button>
  </form>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="<?= h(t('bom.cerca_placeholder')) ?>" value="<?= h($ricerca) ?>"></div>
    <div class="col-md-4"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> <?= h(t('azione.cerca')) ?></button></div>
  </form>
</div>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th><?= h(t('bom.componente_padre')) ?></th><th><?= h(t('bom.revisione')) ?></th><th><?= h(t('bom.stato')) ?></th><th class="text-end"><?= h(t('bom.n_righe')) ?></th><th><?= h(t('bom.data_creazione')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($boms as $b): ?>
    <tr class="<?= $b['attivo'] ? '' : 'text-muted' ?>">
      <td><?= h($b['codice_interno']) ?> - <?= h($b['descrizione']) ?></td>
      <td><span class="badge bg-primary"><?= h($b['revisione']) ?></span></td>
      <td>
        <?php $colori = ['bozza'=>'secondary','attiva'=>'success','obsoleta'=>'danger']; ?>
        <span class="badge bg-<?= $colori[$b['stato']] ?>"><?= h(t('bom.stato.' . $b['stato'])) ?></span>
        <?php if (!$b['attivo']): ?><span class="badge bg-dark"><?= h(t('bom.disattivata')) ?></span><?php endif; ?>
        <?php if ($b['eco_pendente_id']): ?>
          <a href="../eco/view.php?id=<?= $b['eco_pendente_id'] ?>" class="badge bg-warning text-dark text-decoration-none" title="<?= h(t('bom.eco_pendente_titolo')) ?>">
            <i class="bi bi-exclamation-triangle"></i> <?= h(t('bom.eco_pendente', ['numero' => $b['eco_pendente_numero']])) ?>
          </a>
        <?php endif; ?>
      </td>
      <td class="text-end"><?= $b['n_righe'] ?></td>
      <td><?= date('d/m/Y', strtotime($b['data_creazione'])) ?></td>
      <td><a href="editor.php?componente_id=<?= $b['componente_padre_id'] ?>&bom_id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary"><?= h(t('azione.apri')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$boms): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= h(t('bom.nessuna_bom')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?= paginazione_html($pagina, $totaleBom, $perPagina, ['q' => $ricerca]) ?>

<script>
creaSelettoreComponente(document.getElementById('semilavoratoContainer'), {
  nomeCampo: 'componente_id',
  soloConBom: true,
  placeholder: <?= json_encode(t('bom.cerca_semilavorato')) ?>
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
