<?php
$titolo_pagina = 'Configurazione API distributore';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$fornitoreId = (int) ($_GET['fornitore_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM fornitori WHERE id = ? AND azienda_id = ?");
$stmt->execute([$fornitoreId, azienda_id()]);
$fornitore = $stmt->fetch();
if (!$fornitore) { die('Fornitore non trovato.'); }

$stmt = $pdo->prepare("SELECT * FROM distributori_api_config WHERE fornitore_id = ?");
$stmt->execute([$fornitoreId]);
$config = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $dati = [
        trim($_POST['nome_distributore']), trim($_POST['api_base_url']), trim($_POST['api_key']),
        trim($_POST['api_secret']), trim($_POST['client_id']), trim($_POST['store_id']), isset($_POST['attivo']) ? 1 : 0
    ];
    if ($config) {
        $pdo->prepare("UPDATE distributori_api_config SET nome_distributore=?, api_base_url=?, api_key=?, api_secret=?, client_id=?, store_id=?, attivo=? WHERE id=?")
            ->execute([...$dati, $config['id']]);
    } else {
        $pdo->prepare("INSERT INTO distributori_api_config (nome_distributore, api_base_url, api_key, api_secret, client_id, store_id, attivo, fornitore_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([...$dati, $fornitoreId]);
    }
    $_SESSION['flash_msg'] = 'Configurazione API salvata.'; $_SESSION['flash_type'] = 'success';
    header('Location: api_config.php?fornitore_id=' . $fornitoreId); exit;
}
?>
<h4><i class="bi bi-plug"></i> Configurazione API - <?= h($fornitore['ragione_sociale']) ?></h4>
<p class="text-muted">Inserisci le credenziali fornite dal distributore per abilitare l'aggiornamento automatico di prezzi e disponibilità in tempo reale.</p>
<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label">Distributore *</label>
      <select name="nome_distributore" class="form-select" required>
        <?php foreach (['mouser'=>'Mouser Electronics','digikey'=>'DigiKey','arrow'=>'Arrow Electronics','avnet'=>'Avnet','farnell'=>'Farnell / element14 / Newark','tme'=>'TME (Transfer Multisort Elektronik)','rs'=>'RS Components'] as $val=>$lbl): ?>
        <option value="<?= $val ?>" <?= ($config['nome_distributore'] ?? '')===$val?'selected':'' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8"><label class="form-label">Endpoint API base URL</label><input type="text" name="api_base_url" class="form-control" value="<?= h($config['api_base_url'] ?? '') ?>" placeholder="es. https://api.mouser.com/api/v1"></div>
    <div class="col-md-4"><label class="form-label">API Key</label><input type="text" name="api_key" class="form-control" value="<?= h($config['api_key'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">API Secret</label><input type="text" name="api_secret" class="form-control" value="<?= h($config['api_secret'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Client ID / Login (DigiKey, Arrow)</label><input type="text" name="client_id" class="form-control" value="<?= h($config['client_id'] ?? '') ?>"></div>
    <div class="col-md-6">
      <label class="form-label">Sito di riferimento</label>
      <input type="text" name="store_id" class="form-control" value="<?= h($config['store_id'] ?? '') ?>" placeholder="es. it.farnell.com">
      <div class="form-text">Per Farnell/element14/Newark: dominio regionale del tuo account (es. <code>it.farnell.com</code>, <code>uk.farnell.com</code>). Per TME: codice paese a 2 lettere (es. <code>IT</code>). Lascia vuoto per gli altri distributori.</div>
    </div>
    <div class="col-md-6 form-check mt-4 pt-2"><input type="checkbox" name="attivo" class="form-check-input" id="att" <?= (!$config || $config['attivo']) ? 'checked' : '' ?>><label class="form-check-label" for="att">Configurazione attiva</label></div>
  </div>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Salva</button>
    <a href="list.php" class="btn btn-outline-secondary">Torna ai fornitori</a>
  </div>
</form>
<div class="alert alert-info mt-3">
  <i class="bi bi-info-circle"></i> Le chiavi API dei distributori si ottengono registrandosi ai rispettivi portali sviluppatori
  (Mouser API, DigiKey API Developer Portal, Arrow's ELSA/API, Avnet API, RS PRO Integration). La logica di chiamata è in <code>api/distributori.php</code>.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
