<?php
$titolo_pagina = 'Esplosione BOM';
require_once __DIR__ . '/../includes/header.php';

$componenteId = (int) ($_GET['componente_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM componenti WHERE id = ? AND azienda_id = ?");
$stmt->execute([$componenteId, azienda_id()]);
$componente = $stmt->fetch();
if (!$componente) { die('Componente non trovato.'); }

$quantitaRichiesta = num_or($_GET['quantita'] ?? null, 1);
if ($quantitaRichiesta <= 0) { $quantitaRichiesta = 1; }

$albero = albero_bom($pdo, $componenteId, $quantitaRichiesta);
$totaliBase = $albero ? esplodi_bom($pdo, $componenteId, $quantitaRichiesta) : [];

// conta quanti livelli ha effettivamente l'esplosione (per un'informazione rapida in pagina)
function profondita_albero(array $nodi): int {
    $max = 0;
    foreach ($nodi as $n) {
        $max = max($max, $n['figli'] ? 1 + profondita_albero($n['figli']) : 1);
    }
    return $max;
}
$profondita = profondita_albero($albero);

// stampa ricorsivamente l'albero multilivello, usando la classe .bom-tree già presente in assets/style.css
function stampa_albero_bom(array $nodi): void {
    if (!$nodi) { return; }
    echo '<ul>';
    foreach ($nodi as $n) {
        $badge = $n['ha_distinta_base']
            ? '<span class="badge bg-primary ms-1">semilavorato</span>'
            : '<span class="badge bg-secondary ms-1">base</span>';
        echo '<li>';
        echo '<a href="../componenti/view.php?id=' . (int) $n['componente_id'] . '">' . h($n['codice_interno']) . '</a>';
        echo ' - ' . h($n['descrizione']);
        echo ' <strong>' . number_format($n['quantita_totale'], 3, ',', '.') . ' ' . h($n['um']) . '</strong>';
        if (!empty($n['designatore'])) { echo ' <span class="text-muted small">(' . h($n['designatore']) . ')</span>'; }
        echo $badge;
        if ($n['ha_distinta_base'] && !$n['figli']) {
            echo '<div class="small text-warning">(!) nessuna distinta base attiva trovata per questo semilavorato</div>';
        } elseif ($n['figli']) {
            stampa_albero_bom($n['figli']);
        }
        echo '</li>';
    }
    echo '</ul>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-diagram-3"></i> Esplosione multilivello - <?= h($componente['codice_interno']) ?></h4>
  <a href="editor.php?componente_id=<?= $componenteId ?>" class="btn btn-outline-secondary btn-sm">Torna alla distinta base</a>
</div>

<form method="get" class="card p-3 mb-3 row g-2 align-items-end">
  <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
  <div class="col-md-3">
    <label class="form-label small">Quantità da produrre</label>
    <input type="text" name="quantita" class="form-control" value="<?= h((string) $quantitaRichiesta) ?>">
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Ricalcola</button></div>
  <div class="col-md-7 text-md-end text-muted small align-self-center">
    <?= $albero ? 'Esplosione su ' . $profondita . ' livell' . ($profondita === 1 ? 'o' : 'i') : 'Questo componente non ha una distinta base attiva.' ?>
  </div>
</form>

<?php if (!$albero): ?>
  <div class="alert alert-info">
    <?= h($componente['codice_interno']) ?> non ha una distinta base <strong>attiva</strong> associata (o non ne ha affatto). Vai a
    <a href="editor.php?componente_id=<?= $componenteId ?>">Distinta base</a> per crearne una.
  </div>
<?php else: ?>

<div class="card p-4">
  <div class="bom-tree">
    <ul>
      <li>
        <strong><?= h($componente['codice_interno']) ?></strong> - <?= h($componente['descrizione']) ?>
        <span class="badge bg-dark ms-1"><?= number_format($quantitaRichiesta, 3, ',', '.') ?> da produrre</span>
        <?php stampa_albero_bom($albero); ?>
      </li>
    </ul>
  </div>
</div>

<div class="card p-4 mt-3">
  <h6><i class="bi bi-list-check"></i> Fabbisogno totale componenti base (tutti i livelli sommati)</h6>
  <table class="table table-sm mb-0">
    <thead><tr><th>Codice</th><th>Descrizione</th><th class="text-end">Quantità totale</th></tr></thead>
    <tbody>
    <?php foreach ($totaliBase as $t): ?>
      <tr>
        <td><?= h($t['codice']) ?></td>
        <td><?= h($t['descrizione']) ?></td>
        <td class="text-end"><?= number_format($t['quantita'], 3, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
