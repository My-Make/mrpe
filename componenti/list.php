<?php
$titolo_pagina = 'Componenti';
require_once __DIR__ . '/../includes/header.php';

$ricerca = trim($_GET['q'] ?? '');
$tipoFiltro = (int) ($_GET['tipo'] ?? 0);
$mostraDisattivati = isset($_GET['disattivati']);
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 50;

$sqlBase = " FROM componenti c
        JOIN unita_misura um ON um.id = c.um_base_id
        JOIN tipi_componente t ON t.id = c.tipo_componente_id
        LEFT JOIN tecnologie tec ON tec.id = c.tecnologia_id
        WHERE c.azienda_id = ? AND " . ($mostraDisattivati ? "1=1" : "c.attivo = 1");
$params = [azienda_id()];
if ($ricerca !== '') {
    $sqlBase .= " AND (c.codice_interno LIKE ? OR c.sigla LIKE ? OR c.descrizione LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}
if ($tipoFiltro) {
    $sqlBase .= " AND c.tipo_componente_id = ?";
    $params[] = $tipoFiltro;
}

$stmtTot = $pdo->prepare("SELECT COUNT(*) c" . $sqlBase);
$stmtTot->execute($params);
$totaleComponenti = (int) $stmtTot->fetch()['c'];

$sql = "SELECT c.*, um.codice um_codice, t.descrizione tipo_descrizione, tec.codice tecnologia_codice,
        (SELECT COALESCE(SUM(g.quantita),0) FROM giacenze g WHERE g.componente_id = c.id AND g.azienda_id = c.azienda_id) giacenza"
        . $sqlBase . " ORDER BY c.codice_interno LIMIT $perPagina OFFSET " . (($pagina - 1) * $perPagina);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$componenti = $stmt->fetchAll();

$tipiComponente = $pdo->prepare("SELECT * FROM tipi_componente WHERE azienda_id = ? AND attivo = 1 ORDER BY ordinamento, descrizione");
$tipiComponente->execute([azienda_id()]);
$tipiComponente = $tipiComponente->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-box-seam"></i> <?= h(t('componenti.titolo')) ?></h4>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEsportaCsv"><i class="bi bi-download"></i> <?= h(t('componenti.esporta_csv')) ?></button>
    <?php if (!is_sola_lettura()): ?><a href="importa_csv.php" class="btn btn-outline-secondary"><i class="bi bi-upload"></i> <?= h(t('componenti.importa_csv')) ?></a><?php endif; ?>
    <?php if (!is_sola_lettura()): ?><a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= h(t('componenti.nuovo')) ?></a><?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalEsportaCsv" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="get" action="esporta_csv.php">
      <input type="hidden" name="q" value="<?= h($ricerca) ?>">
      <input type="hidden" name="tipo" value="<?= h((string) $tipoFiltro) ?>">
      <input type="hidden" name="disattivati" value="<?= $mostraDisattivati ? '1' : '' ?>">
      <div class="modal-header">
        <h6 class="modal-title">Esporta CSV — scegli le colonne</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Vengono esportati i componenti che rispettano i filtri di ricerca attualmente attivi nell'elenco.</p>
        <div class="mb-2">
          <a href="#" class="small" onclick="document.querySelectorAll('.colonna-csv').forEach(c=>c.checked=true); return false;">Seleziona tutte</a>
          &middot;
          <a href="#" class="small" onclick="document.querySelectorAll('.colonna-csv').forEach(c=>c.checked=false); return false;">Deseleziona tutte</a>
        </div>
        <?php
        $COLONNE_CSV = [
            'codice_interno' => 'Codice interno', 'sigla' => 'Sigla', 'descrizione' => 'Descrizione',
            'tipo_componente' => 'Tipo componente', 'categoria' => 'Categoria', 'tecnologia' => 'Tecnologia', 'um_base' => 'UM base',
            'case_componente' => 'Case/Package', 'prezzo_medio' => 'Prezzo medio', 'valuta' => 'Valuta',
            'scorta_minima' => 'Scorta minima', 'scorta_massima' => 'Scorta massima', 'punto_riordino' => 'Punto di riordino',
            'lead_time_giorni' => 'Lead time (gg)', 'revisione_corrente' => 'Revisione corrente',
            'specifiche' => 'Specifiche', 'note' => 'Note', 'attivo' => 'Attivo',
        ];
        ?>
        <div class="row">
          <?php foreach ($COLONNE_CSV as $val => $lbl): ?>
            <div class="col-md-6 form-check">
              <input type="checkbox" name="colonne[]" value="<?= $val ?>" class="form-check-input colonna-csv" id="csv_<?= $val ?>" checked <?= $val === 'codice_interno' ? 'disabled' : '' ?>>
              <?php if ($val === 'codice_interno'): ?><input type="hidden" name="colonne[]" value="codice_interno"><?php endif; ?>
              <label class="form-check-label" for="csv_<?= $val ?>"><?= h($lbl) ?><?= $val === 'codice_interno' ? ' (sempre incluso)' : '' ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-primary"><i class="bi bi-download"></i> Scarica CSV</button>
      </div>
    </form>
  </div></div>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-md-6">
      <input type="text" name="q" class="form-control" placeholder="<?= h(t('componenti.cerca_placeholder')) ?>" value="<?= h($ricerca) ?>">
    </div>
    <div class="col-md-3">
      <select name="tipo" class="form-select">
        <option value="0"><?= h(t('componenti.tutti_i_tipi')) ?></option>
        <?php foreach ($tipiComponente as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $tipoFiltro===$t['id']?'selected':'' ?>><?= h($t['descrizione']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> <?= h(t('azione.cerca')) ?></button>
    </div>
    <div class="col-12 form-check">
      <input type="checkbox" name="disattivati" value="1" class="form-check-input" id="mostraDis" <?= $mostraDisattivati ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="mostraDis"><?= h(t('componenti.mostra_disattivati')) ?></label>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-responsive">
  <table class="table table-hover mb-0 align-middle">
    <thead>
      <tr>
        <th></th><th><?= h(t('componenti.codice')) ?></th><th><?= h(t('componenti.form.sigla')) ?></th><th><?= h(t('componenti.descrizione')) ?></th><th><?= h(t('componenti.tipo')) ?></th><th>Tecn.</th><th>Rev.</th>
        <th class="text-end"><?= h(t('componenti.giacenza')) ?></th><th>UM</th><th class="text-end"><?= h(t('componenti.prezzo')) ?> medio</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($componenti as $c): ?>
      <tr class="<?= $c['attivo'] ? '' : 'text-muted' ?>">
        <td style="width:48px;">
          <?php if (!empty($c['immagine'])): ?>
            <img src="../<?= h($c['immagine']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
          <?php else: ?>
            <div class="d-flex align-items-center justify-content-center bg-light" style="width:40px;height:40px;border-radius:4px;">
              <i class="bi bi-image text-muted"></i>
            </div>
          <?php endif; ?>
        </td>
        <td><a href="view.php?id=<?= $c['id'] ?>"><?= h($c['codice_interno']) ?></a> <?php if (!$c['attivo']): ?><span class="badge bg-warning text-dark"><?= h(t('stato.disattivato')) ?></span><?php endif; ?>
          <?php $badgeCicloVita = ['nrnd' => ['warning text-dark', 'NRND'], 'eol' => ['danger', 'EOL'], 'obsoleto' => ['dark', 'OBSOLETO']];
          if (isset($badgeCicloVita[$c['stato_ciclo_vita']])): [$classeBadge, $etichetta] = $badgeCicloVita[$c['stato_ciclo_vita']]; ?>
            <span class="badge bg-<?= $classeBadge ?>"><?= $etichetta ?></span>
          <?php endif; ?>
        </td>
        <td><?= h($c['sigla']) ?></td>
        <td><?= h($c['descrizione']) ?></td>
        <td><span class="badge bg-secondary"><?= h($c['tipo_descrizione']) ?></span></td>
        <td><?php if (!empty($c['tecnologia_codice'])): ?><span class="badge bg-dark"><?= h($c['tecnologia_codice']) ?></span><?php endif; ?></td>
        <td><?= h($c['revisione_corrente']) ?></td>
        <td class="text-end <?= $c['giacenza'] <= $c['punto_riordino'] && $c['punto_riordino']>0 ? 'text-danger fw-bold' : '' ?>">
          <?= number_format($c['giacenza'], 2, ',', '.') ?>
        </td>
        <td><?= h($c['um_codice']) ?></td>
        <td class="text-end"><?= number_format($c['prezzo_medio'], 4, ',', '.') ?> <?= h($c['valuta']) ?></td>
        <td class="text-end">
          <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
          <?php if (!is_sola_lettura()): ?><a href="form.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$componenti): ?>
      <tr><td colspan="11" class="text-center text-muted py-4"><?= h(t('componenti.nessun_risultato')) ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?= paginazione_html($pagina, $totaleComponenti, $perPagina, ['q' => $ricerca, 'tipo' => $tipoFiltro, 'disattivati' => $mostraDisattivati ? 1 : '']) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
