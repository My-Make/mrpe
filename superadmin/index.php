<?php
$titolo_pagina = 'Dashboard';
require_once __DIR__ . '/../includes/header_superadmin.php';

$nAziende = $pdo->query("SELECT COUNT(*) c FROM aziende")->fetch()['c'];
$nAziendeAttive = $pdo->query("SELECT COUNT(*) c FROM aziende WHERE attivo = 1")->fetch()['c'];
$nUtenti = $pdo->query("SELECT COUNT(*) c FROM utenti WHERE ruolo IN ('admin','user')")->fetch()['c'];

$aziendeRecenti = $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM utenti u WHERE u.azienda_id = a.id) n_utenti
                                FROM aziende a ORDER BY a.data_creazione DESC LIMIT 5")->fetchAll();
?>
<h4><i class="bi bi-speedometer2"></i> Dashboard Super Admin</h4>

<div class="row g-3 my-2">
  <div class="col-md-4"><div class="card p-3 text-center"><div class="text-muted small">Aziende totali</div><div class="fs-3 fw-bold"><?= $nAziende ?></div></div></div>
  <div class="col-md-4"><div class="card p-3 text-center"><div class="text-muted small">Aziende attive</div><div class="fs-3 fw-bold text-success"><?= $nAziendeAttive ?></div></div></div>
  <div class="col-md-4"><div class="card p-3 text-center"><div class="text-muted small">Utenti totali (tutte le aziende)</div><div class="fs-3 fw-bold"><?= $nUtenti ?></div></div></div>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0">Ultime aziende create</h6>
    <a href="aziende.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Nuova azienda</a>
  </div>
  <table class="table table-sm mb-0">
    <thead><tr><th>Codice</th><th>Ragione sociale</th><th>Utenti</th><th>Stato</th><th>Creata il</th></tr></thead>
    <tbody>
    <?php foreach ($aziendeRecenti as $a): ?>
      <tr>
        <td><?= h($a['codice_azienda']) ?></td>
        <td><?= h($a['ragione_sociale']) ?></td>
        <td><?= $a['n_utenti'] ?></td>
        <td><?= $a['attivo'] ? '<span class="badge bg-success">Attiva</span>' : '<span class="badge bg-secondary">Disattiva</span>' ?></td>
        <td><?= date('d/m/Y', strtotime($a['data_creazione'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$aziendeRecenti): ?><tr><td colspan="5" class="text-center text-muted py-3">Nessuna azienda creata. <a href="aziende.php">Creane una</a>.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
