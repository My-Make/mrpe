<?php
$titolo_pagina = 'Nuovo ordine di acquisto';
require_once __DIR__ . '/../includes/header.php';

$fornitori = $pdo->prepare("SELECT id, ragione_sociale FROM fornitori WHERE azienda_id = ? AND attivo=1 ORDER BY ragione_sociale");
$fornitori->execute([azienda_id()]); $fornitori = $fornitori->fetchAll();
$unitaMisura = $pdo->prepare("SELECT * FROM unita_misura WHERE azienda_id = ? ORDER BY descrizione");
$unitaMisura->execute([azienda_id()]); $unitaMisura = $unitaMisura->fetchAll();
$umDefaultId = null; foreach ($unitaMisura as $u) { if ($u['is_predefinita']) { $umDefaultId = $u['id']; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fornitoreId = (int) $_POST['fornitore_id'];
    $stmtF = $pdo->prepare("SELECT id FROM fornitori WHERE id=? AND azienda_id=?");
    $stmtF->execute([$fornitoreId, azienda_id()]);
    if (!$stmtF->fetch()) {
        $errore = 'Fornitore non valido.';
    } else {
    $pdo->beginTransaction();
    try {
        $numero = genera_numero_ordine($pdo);
        $stmt = $pdo->prepare("INSERT INTO ordini_acquisto (azienda_id, numero_ordine, fornitore_id, data_ordine, data_consegna_prevista, note, utente_id)
                                VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([azienda_id(), $numero, $fornitoreId, $_POST['data_ordine'], $_POST['data_consegna_prevista'] ?: null, trim($_POST['note']), $_SESSION['user_id']]);
        $ordineId = $pdo->lastInsertId();

        $componentiRiga = $_POST['riga_componente_id'] ?? [];
        $quantitaRiga = $_POST['riga_quantita'] ?? [];
        $prezzoRiga = $_POST['riga_prezzo'] ?? [];
        $umRiga = $_POST['riga_um'] ?? [];
        $codiceFornitoreRiga = $_POST['riga_codice_fornitore'] ?? [];

        $insert = $pdo->prepare("INSERT INTO ordini_acquisto_righe (ordine_id, componente_id, codice_fornitore, quantita_ordinata, prezzo_unitario, unita_misura_id) VALUES (?,?,?,?,?,?)");
        $stmtVerificaComp = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
        foreach ($componentiRiga as $i => $compId) {
            if (!$compId || !$quantitaRiga[$i]) continue;
            $stmtVerificaComp->execute([(int)$compId, azienda_id()]);
            if (!$stmtVerificaComp->fetch()) continue; // salta componenti non appartenenti all'azienda
            $insert->execute([$ordineId, (int)$compId, trim($codiceFornitoreRiga[$i] ?? '') ?: null, num_or($quantitaRiga[$i] ?? null, 0), num_or($prezzoRiga[$i] ?? null, 0), (int)$umRiga[$i]]);
        }
        log_attivita($pdo, 'crea_ordine_acquisto', 'ordini_acquisto', $ordineId, $numero);
        $pdo->commit();
        $_SESSION['flash_msg'] = "Ordine $numero creato."; $_SESSION['flash_type'] = 'success';
        header('Location: view.php?id=' . $ordineId); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errore = 'Errore: ' . $e->getMessage();
    }
    }
}
?>
<h4><i class="bi bi-cart"></i> Nuovo ordine di acquisto</h4>
<?php if (!empty($errore)): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-5">
      <label class="form-label">Fornitore *</label>
      <select name="fornitore_id" id="fornitoreSelect" class="form-select" required>
        <option value="">-- Seleziona --</option>
        <?php foreach ($fornitori as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['ragione_sociale']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label">Data ordine *</label><input type="date" name="data_ordine" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-4"><label class="form-label">Consegna prevista</label><input type="date" name="data_consegna_prevista" class="form-control"></div>
  </div>

  <h6 class="mt-4">Righe ordine</h6>
  <table class="table table-sm" id="tabellaRighe">
    <thead><tr><th style="width:50px;">Riga</th><th style="min-width:220px;">Componente</th><th style="width:130px;">Cod. fornitore</th><th style="width:110px;">Quantità</th><th style="width:110px;">Prezzo unit.</th><th style="width:90px;">UM</th><th></th></tr></thead>
    <tbody></tbody>
  </table>
  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="aggiungiRiga()"><i class="bi bi-plus-lg"></i> Aggiungi riga</button>

  <div class="mt-3"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Crea ordine</button>
    <a href="list.php" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>

<script>
const unitaMisura = <?= json_encode($unitaMisura) ?>;
const umDefaultId = <?= json_encode($umDefaultId) ?>;
let contatore = 0;

function aggiungiRiga() {
  contatore++;
  const tbody = document.querySelector('#tabellaRighe tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="text-center numero-riga align-middle"></td>
    <td class="componente-cella"></td>
    <td><input type="text" name="riga_codice_fornitore[]" class="form-control form-control-sm"></td>
    <td><input type="text" name="riga_quantita[]" class="form-control form-control-sm"></td>
    <td><input type="text" name="riga_prezzo[]" class="form-control form-control-sm"></td>
    <td><select name="riga_um[]" class="form-select form-select-sm um-select">
      ${unitaMisura.map(u => `<option value="${u.id}" ${u.id === umDefaultId ? 'selected' : ''}>${u.codice}</option>`).join('')}
    </select></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); rinumeraRighe();"><i class="bi bi-trash"></i></button></td>`;
  tbody.appendChild(tr);
  rinumeraRighe();

  creaSelettoreComponente(tr.querySelector('.componente-cella'), {
    nomeCampo: 'riga_componente_id[]',
    onSelezione: function (c) {
      // imposta come default la UM base del componente scelto (l'utente può comunque cambiarla)
      if (c.um_base_id) { tr.querySelector('.um-select').value = c.um_base_id; }
      aggiornaDatiFornitore(tr, c.id);
    }
  });
}

// recupera da componenti_fornitori (per il fornitore scelto in alto) codice, prezzo e MOQ
function aggiornaDatiFornitore(tr, componenteId) {
  const fornitoreId = document.getElementById('fornitoreSelect').value;
  if (!fornitoreId || !componenteId) return;

  fetch('lookup_fornitore_ajax.php?componente_id=' + componenteId + '&fornitore_id=' + fornitoreId)
    .then(r => r.json())
    .then(dati => {
      if (!dati.trovato) return;
      tr.querySelector('[name="riga_codice_fornitore[]"]').value = dati.codice_fornitore || '';
      if (dati.prezzo !== null) { tr.querySelector('[name="riga_prezzo[]"]').value = dati.prezzo; }
      if (dati.moq !== null) { tr.querySelector('[name="riga_quantita[]"]').value = dati.moq; }
    });
}

// se cambia il fornitore in alto, riaggiorna il recupero dati per tutte le righe con un componente già scelto
document.getElementById('fornitoreSelect').addEventListener('change', function() {
  document.querySelectorAll('#tabellaRighe tbody tr').forEach(tr => {
    const compId = tr.querySelector('.sc-hidden')?.value;
    if (compId) { aggiornaDatiFornitore(tr, compId); }
  });
});

function rinumeraRighe() {
  document.querySelectorAll('#tabellaRighe tbody tr').forEach((tr, indice) => {
    tr.querySelector('.numero-riga').textContent = indice + 1;
  });
}

aggiungiRiga();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
