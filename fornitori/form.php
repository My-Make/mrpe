<?php
$titolo_pagina = 'Fornitore';
require_once __DIR__ . '/../includes/header.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$f = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM fornitori WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, azienda_id()]);
    $f = $stmt->fetch();
    if (!$f) { die('Fornitore non trovato.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $dati = [
        trim($_POST['ragione_sociale']), trim($_POST['codice']),
        trim($_POST['codice_fiscale']), trim($_POST['piva']),
        trim($_POST['indirizzo']), trim($_POST['cap']), trim($_POST['citta']), trim($_POST['provincia']), trim($_POST['nazione']),
        trim($_POST['sede_operativa']), trim($_POST['telefono']), trim($_POST['email']), trim($_POST['pec']), trim($_POST['sito_web']), trim($_POST['referente']),
        trim($_POST['iban']), trim($_POST['bic_swift']), trim($_POST['condizioni_pagamento']), trim($_POST['metodo_pagamento']),
        trim($_POST['regime_iva']), trim($_POST['ritenuta_acconto']), trim($_POST['iscrizione_enti']),
        trim($_POST['reparto_acquisti']), trim($_POST['valuta']) ?: 'EUR', trim($_POST['categoria_merceologica']),
        isset($_POST['is_distributore']) ? 1 : 0, trim($_POST['note'])
    ];
    $colonne = "ragione_sociale, codice, codice_fiscale, piva, indirizzo, cap, citta, provincia, nazione, sede_operativa,
                telefono, email, pec, sito_web, referente, iban, bic_swift, condizioni_pagamento, metodo_pagamento,
                regime_iva, ritenuta_acconto, iscrizione_enti, reparto_acquisti, valuta, categoria_merceologica, is_distributore, note";
    if ($f) {
        $set = implode(', ', array_map(fn($c) => "$c=?", array_map('trim', explode(',', $colonne))));
        $pdo->prepare("UPDATE fornitori SET $set WHERE id=? AND azienda_id=?")
            ->execute([...$dati, $f['id'], azienda_id()]);
        $idFinale = $f['id'];
    } else {
        $placeholder = implode(',', array_fill(0, count($dati) + 1, '?'));
        $pdo->prepare("INSERT INTO fornitori (azienda_id, $colonne) VALUES ($placeholder)")
            ->execute([azienda_id(), ...$dati]);
        $idFinale = $pdo->lastInsertId();
    }
    log_attivita($pdo, $f ? 'modifica_fornitore' : 'crea_fornitore', 'fornitori', $idFinale);
    $_SESSION['flash_msg'] = 'Fornitore salvato.'; $_SESSION['flash_type'] = 'success';
    header('Location: list.php'); exit;
}
?>
<h4><i class="bi bi-truck"></i> <?= $f ? 'Modifica fornitore' : 'Nuovo fornitore' ?></h4>
<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>

  <h6 class="text-primary border-bottom pb-2 mb-3">Dati generali e identificativi</h6>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Ragione sociale *</label><input type="text" name="ragione_sociale" class="form-control" required value="<?= h($f['ragione_sociale'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Codice</label><input type="text" name="codice" class="form-control" value="<?= h($f['codice'] ?? '') ?>"></div>
    <div class="col-md-3 form-check mt-4 pt-2">
      <input type="checkbox" name="is_distributore" class="form-check-input" id="isd" <?= !empty($f['is_distributore']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="isd">È un grande distributore (Mouser, Digikey, Arrow, Avnet, RS...)</label>
    </div>
    <div class="col-md-4"><label class="form-label">Codice Fiscale</label><input type="text" name="codice_fiscale" class="form-control" value="<?= h($f['codice_fiscale'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Partita IVA</label><input type="text" name="piva" class="form-control" value="<?= h($f['piva'] ?? '') ?>"></div>
    <div class="col-md-4"></div>

    <div class="col-md-6"><label class="form-label">Indirizzo sede legale (via e numero)</label><input type="text" name="indirizzo" class="form-control" value="<?= h($f['indirizzo'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label">CAP</label><input type="text" name="cap" class="form-control" value="<?= h($f['cap'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label">Città</label><input type="text" name="citta" class="form-control" value="<?= h($f['citta'] ?? '') ?>"></div>
    <div class="col-md-1"><label class="form-label">Prov.</label><input type="text" name="provincia" class="form-control" maxlength="10" value="<?= h($f['provincia'] ?? '') ?>"></div>
    <div class="col-md-1"><label class="form-label">Nazione</label><input type="text" name="nazione" class="form-control" value="<?= h($f['nazione'] ?? 'Italia') ?>"></div>
    <div class="col-md-12"><label class="form-label">Sede operativa <span class="text-muted small">(solo se diversa dalla sede legale)</span></label><input type="text" name="sede_operativa" class="form-control" value="<?= h($f['sede_operativa'] ?? '') ?>"></div>

    <div class="col-md-3"><label class="form-label">Telefono</label><input type="text" name="telefono" class="form-control" value="<?= h($f['telefono'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($f['email'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">PEC</label><input type="email" name="pec" class="form-control" value="<?= h($f['pec'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Sito web</label><input type="text" name="sito_web" class="form-control" placeholder="https://..." value="<?= h($f['sito_web'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Referente</label><input type="text" name="referente" class="form-control" value="<?= h($f['referente'] ?? '') ?>"></div>
  </div>

  <h6 class="text-primary border-bottom pb-2 mb-3 mt-4">Dati amministrativi e contabili</h6>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">IBAN</label><input type="text" name="iban" class="form-control" value="<?= h($f['iban'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label">BIC/SWIFT</label><input type="text" name="bic_swift" class="form-control" value="<?= h($f['bic_swift'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Condizioni di pagamento</label><input type="text" name="condizioni_pagamento" class="form-control" placeholder="es. 30 gg fine mese" value="<?= h($f['condizioni_pagamento'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Metodo di pagamento</label><input type="text" name="metodo_pagamento" class="form-control" placeholder="es. Bonifico bancario" value="<?= h($f['metodo_pagamento'] ?? '') ?>"></div>

    <div class="col-md-4"><label class="form-label">Regime IVA</label><input type="text" name="regime_iva" class="form-control" placeholder="es. Regime forfettario, Split payment" value="<?= h($f['regime_iva'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Ritenuta d'acconto</label><input type="text" name="ritenuta_acconto" class="form-control" placeholder="es. 20%, vuoto se non soggetto" value="<?= h($f['ritenuta_acconto'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Iscrizione enti/casse previdenziali</label><input type="text" name="iscrizione_enti" class="form-control" placeholder="es. Enasarco" value="<?= h($f['iscrizione_enti'] ?? '') ?>"></div>
  </div>

  <h6 class="text-primary border-bottom pb-2 mb-3 mt-4">Dati di acquisto e commerciali</h6>
  <div class="row g-3">
    <div class="col-md-5"><label class="form-label">Reparto acquisti / tipologia beni-servizi forniti</label><input type="text" name="reparto_acquisti" class="form-control" value="<?= h($f['reparto_acquisti'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label">Valuta</label><input type="text" name="valuta" class="form-control" value="<?= h($f['valuta'] ?? 'EUR') ?>"></div>
    <div class="col-md-5"><label class="form-label">Categoria merceologica / settore</label><input type="text" name="categoria_merceologica" class="form-control" value="<?= h($f['categoria_merceologica'] ?? '') ?>"></div>
    <div class="col-12"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="3"><?= h($f['note'] ?? '') ?></textarea></div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Salva</button>
    <a href="list.php" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
