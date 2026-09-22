<?php
$titolo_pagina = 'Editor BOM';
require_once __DIR__ . '/../includes/header.php';

$componenteId = (int) ($_GET['componente_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM componenti WHERE id = ? AND azienda_id = ?");
$stmt->execute([$componenteId, azienda_id()]);
$padre = $stmt->fetch();
if (!$padre) { die('Componente non trovato.'); }

$tutteRevisioni = $pdo->prepare("SELECT b.*, uc.nome creatore_nome, uc.cognome creatore_cognome, um.nome modificatore_nome, um.cognome modificatore_cognome
                                  FROM bom b
                                  LEFT JOIN utenti uc ON uc.id = b.utente_id
                                  LEFT JOIN utenti um ON um.id = b.utente_modifica_id
                                  WHERE b.componente_padre_id = ? AND b.azienda_id = ? ORDER BY b.id DESC");
$tutteRevisioni->execute([$componenteId, azienda_id()]); $tutteRevisioni = $tutteRevisioni->fetchAll();

$bomId = (int) ($_GET['bom_id'] ?? 0);
$bom = null;
if ($bomId) {
    foreach ($tutteRevisioni as $r) { if ($r['id'] == $bomId) { $bom = $r; break; } }
} elseif ($tutteRevisioni) {
    $bom = $tutteRevisioni[0];
}

$righe = [];
if ($bom) {
    $stmt = $pdo->prepare("SELECT br.*, c.codice_interno, c.descrizione, t.descrizione tipo_descrizione, t.ha_distinta_base, um.codice um_codice
                            FROM bom_righe br JOIN componenti c ON c.id = br.componente_id
                            JOIN tipi_componente t ON t.id = c.tipo_componente_id
                            JOIN unita_misura um ON um.id = br.unita_misura_id
                            WHERE br.bom_id = ? ORDER BY br.ordinamento, br.id");
    $stmt->execute([$bom['id']]);
    $righe = $stmt->fetchAll();
}

$unitaMisura = $pdo->prepare("SELECT * FROM unita_misura WHERE azienda_id = ? ORDER BY descrizione");
$unitaMisura->execute([azienda_id()]); $unitaMisura = $unitaMisura->fetchAll();
$umDefaultId = null; foreach ($unitaMisura as $u) { if ($u['is_predefinita']) { $umDefaultId = $u['id']; break; } }

$fabbisogno = $bom ? esplodi_bom($pdo, $componenteId, 1) : [];
$prossimoOrdinamento = $righe ? (max(array_column($righe, 'ordinamento')) + 10) : 10;

// riga eventualmente in modifica (per modificare quantità/designatore/UM senza cancellare e riaggiungere)
$modificaRigaId = (int) ($_GET['modifica_riga'] ?? 0);
$rigaInModifica = null;
if ($modificaRigaId) {
    foreach ($righe as $r) { if ($r['id'] == $modificaRigaId) { $rigaInModifica = $r; break; } }
}
$ecoPendente = null;
if ($bom) {
    $stmt = $pdo->prepare("SELECT id, numero_eco, titolo, stato FROM eco WHERE tipo_oggetto = 'bom' AND oggetto_id = ? AND azienda_id = ?
                            AND stato NOT IN ('implementato','rifiutato','annullato') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$bom['id'], azienda_id()]);
    $ecoPendente = $stmt->fetch();
}
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h4><i class="bi bi-diagram-3"></i> Distinta Base: <?= h($padre['codice_interno']) ?> - <?= h($padre['descrizione']) ?></h4>
    <?php if ($bom): ?>
      <span class="badge bg-primary">Revisione <?= h($bom['revisione']) ?></span>
      <?php $colori = ['bozza'=>'secondary','attiva'=>'success','obsoleta'=>'danger']; ?>
      <span class="badge bg-<?= $colori[$bom['stato']] ?>"><?= h($bom['stato']) ?></span>
      <?php if (!$bom['attivo']): ?><span class="badge bg-dark">Disattivata</span><?php endif; ?>
      <?php if ($bom['data_inizio_validita'] || $bom['data_fine_validita']): ?>
        <span class="badge bg-light text-dark border">
          <i class="bi bi-calendar-range"></i>
          <?= $bom['data_inizio_validita'] ? date('d/m/Y', strtotime($bom['data_inizio_validita'])) : '-' ?>
          &rarr;
          <?= $bom['data_fine_validita'] ? date('d/m/Y', strtotime($bom['data_fine_validita'])) : '-' ?>
        </span>
      <?php endif; ?>
      <div class="small text-muted mt-1">
        Creata il <?= date('d/m/Y H:i', strtotime($bom['data_creazione'])) ?><?= $bom['creatore_nome'] ? ' da ' . h($bom['creatore_nome'] . ' ' . $bom['creatore_cognome']) : '' ?>
        <?php if ($bom['data_modifica']): ?>
          &middot; ultima modifica il <?= date('d/m/Y H:i', strtotime($bom['data_modifica'])) ?><?= $bom['modificatore_nome'] ? ' da ' . h($bom['modificatore_nome'] . ' ' . $bom['modificatore_cognome']) : '' ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="text-end">
    <?php if ($bom): ?>
    <a href="esplosione.php?componente_id=<?= $componenteId ?>" class="btn btn-outline-primary btn-sm mb-2"><i class="bi bi-diagram-2"></i> Esplosione multilivello</a>
    <?php if (utente_ha_accesso_sezione('eco') && !is_sola_lettura('eco')): ?>
    <a href="../eco/form.php?tipo_oggetto=bom&oggetto_id=<?= $bom['id'] ?>" class="btn btn-outline-warning btn-sm mb-2"><i class="bi bi-pencil-square"></i> Crea ECO</a>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($tutteRevisioni): ?>
    <select class="form-select form-select-sm mb-2" onchange="window.location='editor.php?componente_id=<?= $componenteId ?>&bom_id='+this.value">
      <?php foreach ($tutteRevisioni as $r): ?>
      <option value="<?= $r['id'] ?>" <?= $bom && $bom['id']==$r['id']?'selected':'' ?>>Rev. <?= h($r['revisione']) ?> (<?= h($r['stato']) ?><?= !$r['attivo'] ? ', disattivata' : '' ?>)</option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
</div>

<?php if (!$bom): ?>
  <div class="card p-4">
    <p>Non esiste ancora nessuna distinta base per questo componente.</p>
    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="save.php" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="crea_bom">
      <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
      <div class="col-md-2"><label class="form-label small">Revisione</label><input type="text" name="revisione" class="form-control" value="A" required></div>
      <div class="col-md-4"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small">Inizio validità</label><input type="date" name="data_inizio_validita" class="form-control" value="<?= date('Y') ?>-01-01"></div>
      <div class="col-md-2"><label class="form-label small">Fine Validità</label><input type="date" name="data_fine_validita" class="form-control" value="2099-12-31"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Crea BOM</button></div>
    </form>
    <?php else: ?>
    <p class="text-muted small mb-0">Sei in modalità sola lettura: non puoi creare una nuova distinta base.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

  <?php if ($ecoPendente): ?>
    <?php $etichetteStatoEco = ['bozza'=>'Bozza','in_revisione'=>'In revisione','approvato'=>'Approvato']; ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
      <span>
        <i class="bi bi-exclamation-triangle"></i>
        C'è un <strong>ECO <?= h($ecoPendente['numero_eco']) ?></strong> non ancora concluso su questa revisione
        (stato: <?= h($etichetteStatoEco[$ecoPendente['stato']] ?? $ecoPendente['stato']) ?>) — "<?= h($ecoPendente['titolo']) ?>".
      </span>
      <a href="../eco/view.php?id=<?= $ecoPendente['id'] ?>" class="btn btn-sm btn-outline-dark">Apri ECO</a>
    </div>
  <?php endif; ?>

  <div class="card p-4 mb-3">
    <h6>Righe distinta base (livello 1)</h6>
    <table class="table table-sm">
      <thead><tr><th>Id</th><th>Codice</th><th>Descrizione</th><th>Tipo</th><th class="text-end">Quantità</th><th>UM</th><th>Designatore</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($righe as $r): ?>
        <tr class="<?= $rigaInModifica && $rigaInModifica['id']==$r['id'] ? 'table-warning' : '' ?>">
          <td><?= (int) $r['ordinamento'] ?></td>
          <td><a href="../componenti/view.php?id=<?= $r['componente_id'] ?>"><?= h($r['codice_interno']) ?></a></td>
          <td><?= h($r['descrizione']) ?></td>
          <td>
            <?php if ($r['ha_distinta_base']): ?>
              <a href="editor.php?componente_id=<?= $r['componente_id'] ?>" class="badge bg-info text-dark text-decoration-none"><?= h($r['tipo_descrizione']) ?> - apri sua BOM</a>
            <?php else: ?>
              <span class="badge bg-secondary"><?= h($r['tipo_descrizione']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= number_format($r['quantita'],4,',','.') ?></td>
          <td><?= h($r['um_codice']) ?></td>
          <td><?= h($r['designatore']) ?></td>
          <td>
            <?php if ($bom['stato'] === 'bozza' && !is_sola_lettura()): ?>
            <a href="editor.php?componente_id=<?= $componenteId ?>&bom_id=<?= $bom['id'] ?>&modifica_riga=<?= $r['id'] ?>#modifica-riga" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <form method="post" action="save.php" class="d-inline" onsubmit="return confirm('Rimuovere questa riga?');">
              <?= csrf_field() ?>
              <input type="hidden" name="azione" value="elimina_riga">
              <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
              <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
              <input type="hidden" name="riga_id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$righe): ?><tr><td colspan="8" class="text-center text-muted">Nessuna riga inserita.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <?php if ($bom['stato'] === 'bozza' && $rigaInModifica && !is_sola_lettura()): ?>
    <div id="modifica-riga" class="alert alert-warning mt-3 mb-0">
      <strong>Modifica riga: <?= h($rigaInModifica['codice_interno']) ?> - <?= h($rigaInModifica['descrizione']) ?></strong>
      <form method="post" action="save.php" class="row g-2 align-items-end mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="modifica_riga">
        <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
        <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
        <input type="hidden" name="riga_id" value="<?= $rigaInModifica['id'] ?>">
        <div class="col-md-2"><label class="form-label small">Id</label><input type="number" name="ordinamento" class="form-control" value="<?= (int) $rigaInModifica['ordinamento'] ?>"></div>
        <div class="col-md-3"><label class="form-label small">Quantità</label><input type="text" name="quantita" class="form-control" required value="<?= h((string) $rigaInModifica['quantita']) ?>"></div>
        <div class="col-md-3">
          <label class="form-label small">UM</label>
          <select name="unita_misura_id" class="form-select">
            <?php foreach ($unitaMisura as $um): ?><option value="<?= $um['id'] ?>" <?= $rigaInModifica['unita_misura_id']==$um['id']?'selected':'' ?>><?= h($um['codice']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label small">Designatore</label><input type="text" name="designatore" class="form-control" value="<?= h($rigaInModifica['designatore']) ?>" placeholder="es. R1,R2"></div>
        <div class="col-md-3 d-flex gap-2">
          <button class="btn btn-primary flex-fill"><i class="bi bi-save"></i> Salva</button>
          <a href="editor.php?componente_id=<?= $componenteId ?>&bom_id=<?= $bom['id'] ?>" class="btn btn-outline-secondary">Annulla</a>
        </div>
      </form>
    </div>
    <?php elseif ($bom['stato'] === 'bozza' && !is_sola_lettura()): ?>
    <form method="post" action="save.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="aggiungi_riga">
      <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
      <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
      <div class="col-md-1"><label class="form-label small">Id</label><input type="number" name="ordinamento" class="form-control" value="<?= $prossimoOrdinamento ?>"></div>
      <div class="col-md-3">
        <label class="form-label small">Componente/semilavorato figlio</label>
        <div id="figlioContainer"></div>
      </div>
      <div class="col-md-2"><label class="form-label small">Quantità</label><input type="text" name="quantita" class="form-control" required></div>
      <div class="col-md-2">
        <label class="form-label small">UM</label>
        <select name="unita_misura_id" id="umRigaSelect" class="form-select">
          <?php foreach ($unitaMisura as $um): ?><option value="<?= $um['id'] ?>" <?= $umDefaultId==$um['id']?'selected':'' ?>><?= h($um['codice']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small">Designatore</label><input type="text" name="designatore" class="form-control" placeholder="es. R1,R2"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Aggiungi</button></div>
    </form>
    <script>
    creaSelettoreComponente(document.getElementById('figlioContainer'), {
      nomeCampo: 'riga_componente_id',
      escludiId: <?= (int) $componenteId ?>,
      onSelezione: function (c) { if (c.um_base_id) { document.getElementById('umRigaSelect').value = c.um_base_id; } }
    });
    </script>
    <?php endif; ?>
  </div>

  <div class="card p-4 mb-3">
    <h6><i class="bi bi-diagram-2"></i> Fabbisogno totale componenti base (esplosione multilivello per 1 unità)</h6>
    <p class="text-muted small">I semilavorati vengono esplosi ricorsivamente fino ai componenti elementari.</p>
    <table class="table table-sm">
      <thead><tr><th>Codice</th><th>Descrizione</th><th class="text-end">Quantità necessaria</th></tr></thead>
      <tbody>
      <?php foreach ($fabbisogno as $f): ?>
        <tr><td><?= h($f['codice']) ?></td><td><?= h($f['descrizione']) ?></td><td class="text-end"><?= number_format($f['quantita'],4,',','.') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$fabbisogno): ?><tr><td colspan="3" class="text-center text-muted">Aggiungi righe alla distinta per vedere il fabbisogno.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!is_sola_lettura()): ?>
  <div class="card p-4">
    <h6>Stato revisione</h6>
    <form method="post" action="save.php" class="d-flex gap-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="cambia_stato">
      <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
      <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
      <select name="stato" class="form-select" style="max-width:200px;">
        <?php foreach (['bozza','attiva','obsoleta'] as $s): ?>
        <option value="<?= $s ?>" <?= $bom['stato']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline-primary">Aggiorna stato</button>
    </form>
    <form method="post" action="save.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="modifica_validita">
      <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
      <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
      <div class="col-md-3"><label class="form-label small">Inizio validità</label><input type="date" name="data_inizio_validita" class="form-control" value="<?= h($bom['data_inizio_validita'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label small">Fine Validità</label><input type="date" name="data_fine_validita" class="form-control" value="<?= h($bom['data_fine_validita'] ?? '') ?>"></div>
      <div class="col-md-4"><button class="btn btn-outline-secondary"><i class="bi bi-calendar-range"></i> Aggiorna validità</button></div>
    </form>
    <form method="post" action="save.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="duplica_revisione">
      <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
      <input type="hidden" name="bom_id_origine" value="<?= $bom['id'] ?>">
      <div class="col-md-3"><label class="form-label small">Nuova revisione</label><input type="text" name="nuova_revisione" class="form-control" required></div>
      <div class="col-md-4"><button class="btn btn-outline-secondary"><i class="bi bi-files"></i> Duplica come nuova revisione</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (is_admin()): ?>
  <div class="card p-4 mt-3 border-danger-subtle">
    <h6 class="text-danger"><i class="bi bi-exclamation-triangle"></i> Gestione distinta base</h6>
    <p class="small text-muted mb-3">
      Righe presenti: <strong><?= count($righe) ?></strong>.
      L'eliminazione definitiva è possibile solo se la distinta base non contiene più righe; in alternativa puoi disattivarla per nasconderla senza eliminarla.
    </p>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($bom['attivo']): ?>
      <form method="post" action="save.php" onsubmit="return confirm('Disattivare questa distinta base? Resterà consultabile ma non selezionabile per nuove esplosioni.');">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="disattiva_bom">
        <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
        <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
        <button class="btn btn-outline-warning"><i class="bi bi-eye-slash"></i> Disattiva BOM</button>
      </form>
      <?php else: ?>
      <form method="post" action="save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="riattiva_bom">
        <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
        <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
        <button class="btn btn-outline-success"><i class="bi bi-eye"></i> Riattiva BOM</button>
      </form>
      <?php endif; ?>

      <form method="post" action="save.php" onsubmit="return confirm('Eliminare DEFINITIVAMENTE questa distinta base? L\'operazione non è reversibile.');">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="elimina_bom">
        <input type="hidden" name="componente_id" value="<?= $componenteId ?>">
        <input type="hidden" name="bom_id" value="<?= $bom['id'] ?>">
        <button class="btn btn-outline-danger" <?= count($righe) > 0 ? 'disabled title="La distinta base contiene ancora righe"' : '' ?>><i class="bi bi-trash"></i> Elimina definitivamente</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
