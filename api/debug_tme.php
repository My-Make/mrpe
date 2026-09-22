<?php
/**
 * Mostra la risposta GREZZA di GET /products/data (scope prices+stock) per un
 * codice, così da leggere i nomi esatti dei campi prezzo/disponibilità e
 * correggere definitivamente il parsing in api/distributori.php.
 * Riservato agli amministratori.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();
require_sezione('componenti');

$fornitoreId = (int) ($_GET['fornitore_id'] ?? 0);
$codice = trim($_GET['codice'] ?? '');
$stmt = $pdo->prepare("SELECT f.ragione_sociale, dac.* FROM distributori_api_config dac
                        JOIN fornitori f ON f.id = dac.fornitore_id
                        WHERE dac.fornitore_id = ? AND f.azienda_id = ? AND dac.nome_distributore = 'tme'");
$stmt->execute([$fornitoreId, azienda_id()]);
$config = $stmt->fetch();

$titolo_pagina = 'Debug TME - risposta grezza prezzo/stock';
require_once __DIR__ . '/../includes/header.php';
?>
<h4><i class="bi bi-bug"></i> Risposta grezza /products/data</h4>

<form method="get" class="card p-3 mb-3 row g-2 align-items-end">
  <div class="col-md-4">
    <label class="form-label small">Fornitore (con configurazione TME attiva)</label>
    <select name="fornitore_id" class="form-select">
      <?php
      $elenco = $pdo->prepare("SELECT f.id, f.ragione_sociale FROM fornitori f
                                JOIN distributori_api_config dac ON dac.fornitore_id = f.id AND dac.nome_distributore = 'tme'
                                WHERE f.azienda_id = ?");
      $elenco->execute([azienda_id()]);
      foreach ($elenco->fetchAll() as $f): ?>
        <option value="<?= $f['id'] ?>" <?= $fornitoreId == $f['id'] ? 'selected' : '' ?>><?= h($f['ragione_sociale']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label small">Codice da testare</label>
    <input type="text" name="codice" class="form-control" value="<?= h($codice) ?>" placeholder="es. LM7805-CDI" required>
  </div>
  <div class="col-md-4"><button class="btn btn-primary w-100">Mostra risposta</button></div>
</form>

<?php if (!$fornitoreId || $codice === ''): ?>
  <p class="text-muted">Seleziona un fornitore e un codice da testare.</p>
<?php elseif (!$config): ?>
  <div class="alert alert-danger">Nessuna configurazione TME trovata per questo fornitore.</div>
<?php else:
    $basic = base64_encode($config['api_key'] . ':' . $config['api_secret']);
    $chToken = curl_init('https://api.tme.eu/auth/token');
    curl_setopt_array($chToken, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT => 'MRP-Elettronica/1.0',
        CURLOPT_TIMEOUT => 15,
    ]);
    $rispostaToken = curl_exec($chToken);
    curl_close($chToken);
    $token = json_decode((string) $rispostaToken, true)['access_token'] ?? null;

    if (!$token): ?>
      <div class="alert alert-danger">Autenticazione fallita inaspettatamente: <?= h((string) $rispostaToken) ?></div>
    <?php else:
    $paese = strtolower(trim($config['store_id'] ?? '') ?: 'it');

    $parti = [];
    foreach (['country' => $paese, 'currency' => 'EUR'] as $k => $v) { $parti[] = rawurlencode($k) . '=' . rawurlencode($v); }
    foreach (['prices', 'stock'] as $s) { $parti[] = 'scope%5B%5D=' . rawurlencode($s); }
    $parti[] = 'symbols%5B%5D=' . rawurlencode($codice);
    $url = 'https://api.tme.eu/products/data?' . implode('&', $parti);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept-Language: ' . $paese, 'Accept: application/json'],
        CURLOPT_USERAGENT => 'MRP-Elettronica/1.0',
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decodificata = json_decode((string) $risposta, true);
    ?>
    <p class="small text-muted">URL: <code><?= h($url) ?></code> — HTTP <?= $httpCode ?></p>
    <pre class="small bg-light p-3" style="white-space:pre-wrap;"><?= h($decodificata !== null ? json_encode($decodificata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $risposta) ?></pre>
  <?php endif; ?>
<?php endif; ?>

<a href="../fornitori/list.php" class="btn btn-outline-secondary mt-3">Torna ai fornitori</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
