<?php
$titolo_pagina = 'Ordine di acquisto';
require_once __DIR__ . '/../includes/header.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT o.*, f.ragione_sociale FROM ordini_acquisto o JOIN fornitori f ON f.id=o.fornitore_id WHERE o.id=? AND o.azienda_id=?");
$stmt->execute([$id, azienda_id()]);
$ordine = $stmt->fetch();
if (!$ordine) { die('Ordine non trovato.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['azione'] === 'cambia_stato') {
    csrf_verify();
    $pdo->prepare("UPDATE ordini_acquisto SET stato=? WHERE id=? AND azienda_id=?")->execute([$_POST['stato'], $id, azienda_id()]);
    header('Location: view.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['azione'] === 'ricevi_riga') {
    csrf_verify();
    $rigaId = (int) $_POST['riga_id'];
    $qtaRicevuta = num_or($_POST['quantita_ricevuta'] ?? null, 0);
    $magazzinoId = (int) $_POST['magazzino_id'];
    $codiceLottoFornitore = trim($_POST['codice_lotto_fornitore']);

    $stmtMag = $pdo->prepare("SELECT id FROM magazzini WHERE id=? AND azienda_id=?");
    $stmtMag->execute([$magazzinoId, azienda_id()]);
    $stmt = $pdo->prepare("SELECT r.* FROM ordini_acquisto_righe r JOIN ordini_acquisto o ON o.id=r.ordine_id
                            WHERE r.id=? AND r.ordine_id=? AND o.azienda_id=?");
    $stmt->execute([$rigaId, $id, azienda_id()]);
    $riga = $stmt->fetch();

    if (!$stmtMag->fetch() || !$riga) {
        $errore = 'Magazzino o riga ordine non validi.';
    } else {
    $pdo->beginTransaction();
    try {
        $codiceInterno = genera_codice_lotto_interno($pdo);
        $pdo->prepare("INSERT INTO lotti (azienda_id, componente_id, fornitore_id, codice_lotto_fornitore, codice_lotto_interno,
            ordine_acquisto_id, data_ricezione, quantita_iniziale, quantita_residua)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([azienda_id(), $riga['componente_id'], $ordine['fornitore_id'], $codiceLottoFornitore, $codiceInterno, $id, date('Y-m-d'), $qtaRicevuta, $qtaRicevuta]);
        $lottoId = $pdo->lastInsertId();

        registra_movimento($pdo, [
            'componente_id' => $riga['componente_id'], 'lotto_id' => $lottoId, 'magazzino_id' => $magazzinoId,
            'tipo_movimento' => 'carico', 'quantita' => $qtaRicevuta,
            'causale' => 'Ricezione da ordine ' . $ordine['numero_ordine'], 'riferimento_documento' => $ordine['numero_ordine']
        ]);

        $pdo->prepare("UPDATE ordini_acquisto_righe SET quantita_ricevuta = quantita_ricevuta + ? WHERE id=?")->execute([$qtaRicevuta, $rigaId]);

        // aggiorna stato ordine: verifica se tutte le righe sono complete
        $righeTot = $pdo->prepare("SELECT SUM(quantita_ordinata) tot, SUM(quantita_ricevuta) ric FROM ordini_acquisto_righe WHERE ordine_id=?");
        $righeTot->execute([$id]);
        $tot = $righeTot->fetch();
        $nuovoStato = $tot['ric'] >= $tot['tot'] ? 'ricevuto' : 'parziale';
        $pdo->prepare("UPDATE ordini_acquisto SET stato=? WHERE id=? AND azienda_id=?")->execute([$nuovoStato, $id, azienda_id()]);

        log_attivita($pdo, 'ricezione_merce', 'ordini_acquisto', $id, "Lotto $codiceInterno");
        $pdo->commit();
        $_SESSION['flash_msg'] = "Merce ricevuta e caricata come lotto $codiceInterno.";
        $_SESSION['flash_type'] = 'success';
        header('Location: view.php?id=' . $id); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errore = 'Errore: ' . $e->getMessage();
    }
    }
}

$righe = $pdo->prepare("SELECT r.*, c.codice_interno, c.descrizione, um.codice um_codice
                         FROM ordini_acquisto_righe r JOIN componenti c ON c.id=r.componente_id
                         JOIN unita_misura um ON um.id = r.unita_misura_id WHERE r.ordine_id=?");
$righe->execute([$id]); $righe = $righe->fetchAll();
$magazzini = $pdo->prepare("SELECT id, descrizione FROM magazzini WHERE azienda_id = ? AND attivo=1");
$magazzini->execute([azienda_id()]); $magazzini = $magazzini->fetchAll();
?>
<h4 class="d-flex justify-content-between align-items-center">
  <span><i class="bi bi-cart"></i> Ordine <?= h($ordine['numero_ordine']) ?> - <?= h($ordine['ragione_sociale']) ?></span>
  <a href="pdf.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Scarica PDF</a>
</h4>
<?php if (!empty($errore)): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<?php if (!is_sola_lettura()): ?>
<div class="card p-3 mb-3">
  <form method="post" class="d-flex gap-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="cambia_stato">
    <label class="form-label small mb-0">Stato ordine:</label>
    <select name="stato" class="form-select form-select-sm" style="max-width:200px;">
      <?php foreach (['bozza','inviato','confermato','parziale','ricevuto','annullato'] as $s): ?>
      <option value="<?= $s ?>" <?= $ordine['stato']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-primary btn-sm">Aggiorna</button>
  </form>
</div>
<?php endif; ?>

<div class="card p-4">
  <h6>Righe ordine</h6>
  <table class="table table-sm">
    <thead><tr><th style="width:50px;">Riga</th><th>Componente</th><th>Cod. fornitore</th><th class="text-end">Ordinata</th><th class="text-end">Ricevuta</th><th class="text-end">Prezzo</th><th>UM</th><th>Ricevi</th></tr></thead>
    <tbody>
    <?php $numRiga = 0; foreach ($righe as $r): $numRiga++; ?>
      <tr>
        <td class="text-center"><?= $numRiga ?></td>
        <td><?= h($r['codice_interno']) ?> - <?= h($r['descrizione']) ?></td>
        <td><?= h($r['codice_fornitore']) ?: '-' ?></td>
        <td class="text-end"><?= number_format($r['quantita_ordinata'],2,',','.') ?></td>
        <td class="text-end"><?= number_format($r['quantita_ricevuta'],2,',','.') ?></td>
        <td class="text-end"><?= number_format($r['prezzo_unitario'],4,',','.') ?></td>
        <td><?= h($r['um_codice']) ?></td>
        <td>
          <?php if ($r['quantita_ricevuta'] < $r['quantita_ordinata']): ?>
            <?php if (!is_sola_lettura()): ?>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalRicevi<?= $r['id'] ?>"><i class="bi bi-box-arrow-in-down"></i> Ricevi</button>

            <div class="modal fade" id="modalRicevi<?= $r['id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="azione" value="ricevi_riga">
                    <input type="hidden" name="riga_id" value="<?= $r['id'] ?>">
                    <div class="modal-header"><h6 class="modal-title">Ricevi merce - <?= h($r['codice_interno']) ?></h6></div>
                    <div class="modal-body">
                      <div class="mb-2"><label class="form-label small">Quantità ricevuta</label><input type="text" name="quantita_ricevuta" class="form-control" required></div>
                      <div class="mb-2"><label class="form-label small">Codice lotto fornitore</label><input type="text" name="codice_lotto_fornitore" class="form-control" required></div>
                      <div class="mb-2">
                        <label class="form-label small">Magazzino di carico</label>
                        <select name="magazzino_id" class="form-select" required>
                          <?php foreach ($magazzini as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['descrizione']) ?></option><?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                      <button class="btn btn-success">Conferma ricezione</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php else: ?>
            <span class="badge bg-secondary">In attesa</span>
            <?php endif; ?>
          <?php else: ?>
          <span class="badge bg-success">Completa</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<a href="list.php" class="btn btn-outline-secondary mt-3">Torna agli ordini</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
