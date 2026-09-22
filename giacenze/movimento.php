<?php
$titolo_pagina = 'Movimento di magazzino';
require_once __DIR__ . '/../includes/header.php';

$componenteIdPre = (int) ($_GET['componente_id'] ?? 0);
$componentePre = null;
if ($componenteIdPre) {
    $stmtPre = $pdo->prepare("SELECT id, codice_interno, descrizione FROM componenti WHERE id = ? AND azienda_id = ?");
    $stmtPre->execute([$componenteIdPre, azienda_id()]);
    $componentePre = $stmtPre->fetch();
}
$magazzini = $pdo->prepare("SELECT id, descrizione FROM magazzini WHERE azienda_id = ? AND attivo=1 ORDER BY descrizione");
$magazzini->execute([azienda_id()]); $magazzini = $magazzini->fetchAll();
$causali = $pdo->prepare("SELECT * FROM causali_movimento WHERE azienda_id = ? AND attivo = 1 ORDER BY ordinamento, descrizione");
$causali->execute([azienda_id()]); $causali = $causali->fetchAll();

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $componenteId = (int) $_POST['componente_id'];
    $magazzinoId = (int) $_POST['magazzino_id'];
    $magazzinoDestId = $_POST['magazzino_destinazione_id'] ?: null;
    $ubicazioneId = $_POST['ubicazione_id'] ?: null;
    $lottoId = $_POST['lotto_id'] ?: null;
    $tipo = $_POST['tipo_movimento'];
    $qta = num_or($_POST['quantita'] ?? null, 0);
    $causale = trim($_POST['causale'] ?? '');
    if ($causale === '__altro__') {
        $causale = trim($_POST['causale_altro'] ?? '');
    }

    // verifica che componente/magazzini/ubicazione/lotto appartengano all'azienda corrente
    $stmtV = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
    $stmtV->execute([$componenteId, azienda_id()]);
    $stmtM = $pdo->prepare("SELECT id FROM magazzini WHERE id=? AND azienda_id=?");
    $stmtM->execute([$magazzinoId, azienda_id()]);
    $magazzinoDestOk = true;
    if ($magazzinoDestId) {
        $stmtMD = $pdo->prepare("SELECT id FROM magazzini WHERE id=? AND azienda_id=?");
        $stmtMD->execute([$magazzinoDestId, azienda_id()]);
        $magazzinoDestOk = (bool) $stmtMD->fetch();
    }
    $ubicazioneOk = true;
    if ($ubicazioneId) {
        $stmtU = $pdo->prepare("SELECT u.id FROM ubicazioni u JOIN magazzini m ON m.id=u.magazzino_id WHERE u.id=? AND u.magazzino_id=? AND m.azienda_id=?");
        $stmtU->execute([$ubicazioneId, $magazzinoId, azienda_id()]);
        $ubicazioneOk = (bool) $stmtU->fetch();
    }
    $lottoOk = true;
    if ($lottoId) {
        $stmtL = $pdo->prepare("SELECT id FROM lotti WHERE id=? AND azienda_id=?");
        $stmtL->execute([$lottoId, azienda_id()]);
        $lottoOk = (bool) $stmtL->fetch();
    }

    if (!$stmtV->fetch() || !$stmtM->fetch() || !$magazzinoDestOk || !$ubicazioneOk || !$lottoOk) {
        $errore = 'Componente, magazzino, ubicazione o lotto non validi.';
    } else {
    try {
        registra_movimento($pdo, [
            'componente_id' => $componenteId,
            'lotto_id' => $lottoId,
            'magazzino_id' => $magazzinoId,
            'magazzino_destinazione_id' => $magazzinoDestId,
            'ubicazione_id' => $ubicazioneId,
            'tipo_movimento' => $tipo,
            'quantita' => $qta,
            'causale' => $causale,
            'riferimento_documento' => trim($_POST['riferimento_documento']),
        ]);
        log_attivita($pdo, 'movimento_magazzino', 'componenti', $componenteId, "$tipo di $qta");
        $_SESSION['flash_msg'] = 'Movimento registrato correttamente.';
        $_SESSION['flash_type'] = 'success';
        header('Location: view.php'); exit;
    } catch (Exception $e) {
        $errore = 'Errore: ' . $e->getMessage();
    }
    }
}
?>
<h4><i class="bi bi-arrow-left-right"></i> Registra movimento di magazzino</h4>
<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<form method="post" class="card p-4 mt-3" id="formMov">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Componente *</label>
      <div id="componenteContainer"></div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Lotto (opzionale, per scarichi/trasferimenti tracciati)</label>
      <select name="lotto_id" id="lottoSelect" class="form-select"><option value="">-- Nessun lotto --</option></select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Tipo movimento *</label>
      <select name="tipo_movimento" id="tipoMovimento" class="form-select" required>
        <option value="carico">Carico</option>
        <option value="scarico">Scarico</option>
        <option value="trasferimento">Trasferimento</option>
        <option value="rettifica">Rettifica</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Magazzino *</label>
      <select name="magazzino_id" id="magazzinoSelect" class="form-select" required>
        <?php foreach ($magazzini as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['descrizione']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Ubicazione (opzionale)</label>
      <select name="ubicazione_id" id="ubicazioneSelect" class="form-select"><option value="">-- Nessuna --</option></select>
    </div>
    <div class="col-md-3" id="campoDestinazione" style="display:none;">
      <label class="form-label">Magazzino destinazione</label>
      <select name="magazzino_destinazione_id" class="form-select">
        <?php foreach ($magazzini as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['descrizione']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Quantità *</label>
      <input type="text" name="quantita" class="form-control" required placeholder="Positiva; per rettifiche negative usa il segno -">
    </div>

    <div class="col-md-6">
      <label class="form-label">Causale</label>
      <select name="causale" id="causaleSelect" class="form-select">
        <option value="">-- Nessuna --</option>
        <?php foreach ($causali as $ca): ?>
        <option value="<?= h($ca['descrizione']) ?>" data-tipo="<?= h($ca['tipo_movimento']) ?>"><?= h($ca['descrizione']) ?></option>
        <?php endforeach; ?>
        <option value="__altro__">Altro (specifica)...</option>
      </select>
      <input type="text" name="causale_altro" id="causaleAltro" class="form-control mt-2" placeholder="Specifica la causale" style="display:none;">
      <div class="form-text">Gestisci l'elenco in <a href="../admin/causali_movimento.php">Amministrazione</a>.</div>
    </div>
    <div class="col-md-6"><label class="form-label">Riferimento documento</label><input type="text" name="riferimento_documento" class="form-control"></div>
  </div>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Registra movimento</button>
    <a href="view.php" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>

<script>
function filtraCausali() {
  const tipo = document.getElementById('tipoMovimento').value;
  const select = document.getElementById('causaleSelect');
  Array.from(select.options).forEach(opt => {
    if (!opt.dataset.tipo) { opt.style.display = ''; return; } // "-- Nessuna --" e "Altro"
    opt.style.display = (opt.dataset.tipo === 'tutti' || opt.dataset.tipo === tipo) ? '' : 'none';
  });
  // se l'opzione selezionata non è più valida per questo tipo, torna a "-- Nessuna --"
  const selezionata = select.options[select.selectedIndex];
  if (selezionata && selezionata.dataset.tipo && selezionata.style.display === 'none') {
    select.value = '';
  }
}
document.getElementById('tipoMovimento').addEventListener('change', function() {
  document.getElementById('campoDestinazione').style.display = this.value === 'trasferimento' ? 'block' : 'none';
  filtraCausali();
});
document.getElementById('causaleSelect').addEventListener('change', function() {
  document.getElementById('causaleAltro').style.display = this.value === '__altro__' ? 'block' : 'none';
});
filtraCausali();
document.getElementById('magazzinoSelect').addEventListener('change', function() {
  const magazzinoId = this.value;
  const ubicazioneSelect = document.getElementById('ubicazioneSelect');
  ubicazioneSelect.innerHTML = '<option value="">-- Nessuna --</option>';
  if (!magazzinoId) return;
  fetch('ubicazioni_ajax.php?magazzino_id=' + magazzinoId)
    .then(r => r.json())
    .then(ubicazioni => {
      ubicazioni.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.codice + (u.descrizione ? ' - ' + u.descrizione : '');
        ubicazioneSelect.appendChild(opt);
      });
    });
});
document.getElementById('magazzinoSelect').dispatchEvent(new Event('change'));

function caricaLottiPerComponente(compId) {
  const lottoSelect = document.getElementById('lottoSelect');
  lottoSelect.innerHTML = '<option value="">-- Nessun lotto --</option>';
  if (!compId) return;
  fetch('lotti_ajax.php?componente_id=' + compId)
    .then(r => r.json())
    .then(lotti => {
      lotti.forEach(l => {
        const opt = document.createElement('option');
        opt.value = l.id;
        opt.textContent = l.codice_lotto_interno + ' (residuo: ' + l.quantita_residua + ')';
        lottoSelect.appendChild(opt);
      });
    });
}

const selettoreComponente = creaSelettoreComponente(document.getElementById('componenteContainer'), {
  nomeCampo: 'componente_id',
  onSelezione: function (c) { caricaLottiPerComponente(c.id); }
});
<?php if ($componentePre): ?>
(function () {
  const hidden = selettoreComponente.querySelector('.sc-hidden');
  const testo = selettoreComponente.querySelector('.sc-testo');
  hidden.value = <?= json_encode($componentePre['id']) ?>;
  testo.value = <?= json_encode($componentePre['codice_interno'] . ' - ' . $componentePre['descrizione']) ?>;
  caricaLottiPerComponente(hidden.value);
})();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
