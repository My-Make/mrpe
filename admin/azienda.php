<?php
$titolo_pagina = 'Dati azienda';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
$stmt->execute([azienda_id()]);
$azienda = $stmt->fetch();

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $indirizzo = trim($_POST['indirizzo']);
    $piva = trim($_POST['piva']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);

    $logo = $azienda['logo'];
    $logoJpeg = $azienda['logo_jpeg'];

    if (isset($_POST['rimuovi_logo'])) {
        foreach ([$logo, $logoJpeg] as $vecchio) {
            if ($vecchio) { $f = __DIR__ . '/../' . $vecchio; if (is_file($f)) { @unlink($f); } }
        }
        $logo = null; $logoJpeg = null;
    } elseif (!empty($_FILES['logo']['name'])) {
        $ris = gestisci_upload($_FILES['logo'], IMG_UPLOAD_DIR, IMG_UPLOAD_URL, ALLOWED_IMG_EXT);
        if (!$ris['ok']) {
            $errore = $ris['errore'];
        } else {
            foreach ([$logo, $logoJpeg] as $vecchio) {
                if ($vecchio) { $f = __DIR__ . '/../' . $vecchio; if (is_file($f)) { @unlink($f); } }
            }
            $logo = $ris['percorso'];
            $logoJpeg = genera_logo_jpeg($logo); // null se GD non disponibile: il logo resta visibile qui ma non nel PDF
        }
    }

    if (!$errore) {
        $pdo->prepare("UPDATE aziende SET indirizzo=?, piva=?, telefono=?, email=?, logo=?, logo_jpeg=? WHERE id=?")
            ->execute([$indirizzo, $piva, $telefono, $email, $logo, $logoJpeg, azienda_id()]);
        log_attivita($pdo, 'modifica_dati_azienda', 'aziende', azienda_id());
        $_SESSION['flash_msg'] = 'Dati azienda aggiornati.'; $_SESSION['flash_type'] = 'success';
        header('Location: azienda.php'); exit;
    }
}
?>
<h4><i class="bi bi-building"></i> Dati azienda</h4>
<p class="text-muted">Questi dati vengono usati come intestazione "mittente" nei documenti generati dall'app, come l'ordine di acquisto in PDF. Ragione sociale e Codice Azienda sono gestiti dal Super Admin.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>
<?php if ($azienda['logo'] && !$azienda['logo_jpeg']): ?>
<div class="alert alert-warning">Il logo è caricato e visibile qui, ma non è stato possibile prepararlo per il PDF (l'estensione PHP "GD" non risulta disponibile su questo hosting). Il PDF verrà generato senza logo. Contatta il supporto del tuo hosting se vuoi risolverlo, oppure carica direttamente un file JPEG.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card p-4 mt-3" style="max-width:600px;">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Ragione sociale</label><input type="text" class="form-control" value="<?= h($azienda['ragione_sociale']) ?>" disabled></div>
  <div class="mb-3"><label class="form-label">Indirizzo</label><input type="text" name="indirizzo" class="form-control" value="<?= h($azienda['indirizzo'] ?? '') ?>" placeholder="Via, numero, CAP, città, provincia"></div>
  <div class="mb-3"><label class="form-label">P.IVA / Codice Fiscale</label><input type="text" name="piva" class="form-control" value="<?= h($azienda['piva'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label">Telefono</label><input type="text" name="telefono" class="form-control" value="<?= h($azienda['telefono'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($azienda['email'] ?? '') ?>"></div>
  <div class="mb-3">
    <label class="form-label">Logo</label>
    <?php if (!empty($azienda['logo'])): ?>
      <div class="d-flex align-items-center gap-3 mb-2">
        <img src="../<?= h($azienda['logo']) ?>" style="max-height:70px; border-radius:4px; border:1px solid #ddd;" alt="Logo">
        <div class="form-check">
          <input type="checkbox" name="rimuovi_logo" class="form-check-input" id="rimLogo" value="1">
          <label class="form-check-label small" for="rimLogo">Rimuovi logo attuale</label>
        </div>
      </div>
    <?php endif; ?>
    <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
    <div class="form-text">JPG, PNG o WEBP. Verrà convertito automaticamente in JPEG per l'uso nei PDF.</div>
  </div>
  <button class="btn btn-primary"><i class="bi bi-save"></i> Salva</button>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
