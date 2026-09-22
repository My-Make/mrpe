<?php
$titolo_pagina = 'Nuovo lotto';
require_once __DIR__ . '/../includes/header.php';

$componenteIdPre = (int) ($_GET['componente_id'] ?? 0);
$componentePre = null;
if ($componenteIdPre) {
    $stmtPre = $pdo->prepare("SELECT id, codice_interno, descrizione FROM componenti WHERE id = ? AND azienda_id = ?");
    $stmtPre->execute([$componenteIdPre, azienda_id()]);
    $componentePre = $stmtPre->fetch();
}
$fornitori = $pdo->prepare("SELECT id, ragione_sociale FROM fornitori WHERE azienda_id = ? AND attivo=1 ORDER BY ragione_sociale");
$fornitori->execute([azienda_id()]); $fornitori = $fornitori->fetchAll();
$magazzini = $pdo->prepare("SELECT id, descrizione FROM magazzini WHERE azienda_id = ? AND attivo=1 ORDER BY descrizione");
$magazzini->execute([azienda_id()]); $magazzini = $magazzini->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $componenteId = (int) $_POST['componente_id'];
    $fornitoreId = (int) $_POST['fornitore_id'];
    $magazzinoId = (int) $_POST['magazzino_id'];
    $qta = num_or($_POST['quantita_iniziale'] ?? null, 0);

    // verifica appartenenza all'azienda corrente
    $stmtC = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?"); $stmtC->execute([$componenteId, azienda_id()]);
    $stmtF = $pdo->prepare("SELECT id FROM fornitori WHERE id=? AND azienda_id=?"); $stmtF->execute([$fornitoreId, azienda_id()]);
    $stmtM = $pdo->prepare("SELECT id FROM magazzini WHERE id=? AND azienda_id=?"); $stmtM->execute([$magazzinoId, azienda_id()]);
    if (!$stmtC->fetch() || !$stmtF->fetch() || !$stmtM->fetch()) {
        $errore = 'Componente, fornitore o magazzino non validi.';
    } else {

    $pdo->beginTransaction();
    try {
        $codiceInterno = genera_codice_lotto_interno($pdo);
        $stmt = $pdo->prepare("INSERT INTO lotti (azienda_id, componente_id, fornitore_id, codice_lotto_fornitore, codice_lotto_interno,
            ddt_riferimento, data_ricezione, data_scadenza, quantita_iniziale, quantita_residua, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            azienda_id(), $componenteId, $fornitoreId, trim($_POST['codice_lotto_fornitore']), $codiceInterno,
            trim($_POST['ddt_riferimento']), $_POST['data_ricezione'], $_POST['data_scadenza'] ?: null,
            $qta, $qta, trim($_POST['note'])
        ]);
        $lottoId = $pdo->lastInsertId();

        // carico automatico a magazzino
        registra_movimento($pdo, [
            'componente_id' => $componenteId,
            'lotto_id' => $lottoId,
            'magazzino_id' => $magazzinoId,
            'tipo_movimento' => 'carico',
            'quantita' => $qta,
            'causale' => 'Ricezione merce - nuovo lotto',
            'riferimento_documento' => trim($_POST['ddt_riferimento']),
        ]);

        log_attivita($pdo, 'crea_lotto', 'lotti', $lottoId, "Lotto $codiceInterno");
        $pdo->commit();
        $_SESSION['flash_msg'] = "Lotto $codiceInterno creato e caricato a magazzino.";
        $_SESSION['flash_type'] = 'success';
        header('Location: list.php'); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errore = 'Errore: ' . $e->getMessage();
    }
    }
}
?>
<h4><i class="bi bi-upc-scan"></i> Nuovo lotto (ricezione merce)</h4>
<?php if (!empty($errore)): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Componente *</label>
      <div id="componenteContainer"></div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Fornitore *</label>
      <select name="fornitore_id" class="form-select" required>
        <?php foreach ($fornitori as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['ragione_sociale']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4"><label class="form-label">Codice lotto fornitore *</label><input type="text" name="codice_lotto_fornitore" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Riferimento DDT</label><input type="text" name="ddt_riferimento" class="form-control"></div>
    <div class="col-md-4">
      <label class="form-label">Magazzino di carico *</label>
      <select name="magazzino_id" class="form-select" required>
        <?php foreach ($magazzini as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['descrizione']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4"><label class="form-label">Data ricezione *</label><input type="date" name="data_ricezione" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-4"><label class="form-label">Data scadenza</label><input type="date" name="data_scadenza" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Quantità ricevuta *</label><input type="text" name="quantita_iniziale" class="form-control" required></div>
    <div class="col-12"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Crea lotto e carica a magazzino</button>
    <a href="list.php" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>
<script>
const selettoreComponente = creaSelettoreComponente(document.getElementById('componenteContainer'), { nomeCampo: 'componente_id' });
<?php if ($componentePre): ?>
selettoreComponente.querySelector('.sc-hidden').value = <?= json_encode($componentePre['id']) ?>;
selettoreComponente.querySelector('.sc-testo').value = <?= json_encode($componentePre['codice_interno'] . ' - ' . $componentePre['descrizione']) ?>;
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
