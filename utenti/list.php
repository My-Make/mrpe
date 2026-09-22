<?php
$titolo_pagina = 'Utenti';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$utenti = $pdo->prepare("SELECT u.*,
        (SELECT COUNT(*) FROM utenti_permessi_sezioni p WHERE p.utente_id = u.id AND p.livello = 'nessuno') n_nessuno,
        (SELECT COUNT(*) FROM utenti_permessi_sezioni p WHERE p.utente_id = u.id AND p.livello = 'lettura') n_lettura
        FROM utenti u WHERE u.azienda_id = ? ORDER BY u.cognome, u.nome");
$utenti->execute([azienda_id()]); $utenti = $utenti->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-people"></i> Gestione Utenti</h4>
  <a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuovo utente</a>
</div>
<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Username</th><th>Nome</th><th>Email</th><th>Ruolo</th><th>Permessi</th><th>Stato</th><th>Ultimo accesso</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($utenti as $u): ?>
    <tr>
      <td><?= h($u['username']) ?></td>
      <td><?= h($u['nome'] . ' ' . $u['cognome']) ?></td>
      <td><?= h($u['email']) ?></td>
      <td><span class="badge bg-<?= $u['ruolo']==='admin'?'danger':'secondary' ?>"><?= h($u['ruolo']) ?></span></td>
      <td>
        <?php if ($u['ruolo'] === 'admin'): ?>
          <span class="text-muted small">accesso completo</span>
        <?php else: ?>
          <?php if ($u['n_nessuno'] > 0): ?><span class="badge bg-danger"><?= $u['n_nessuno'] ?> escluse</span><?php endif; ?>
          <?php if ($u['n_lettura'] > 0): ?><span class="badge bg-warning text-dark"><?= $u['n_lettura'] ?> in sola lettura</span><?php endif; ?>
          <?php if ($u['n_nessuno'] == 0 && $u['n_lettura'] == 0): ?><span class="text-muted small">accesso completo</span><?php endif; ?>
        <?php endif; ?>
      </td>
      <td><?= $u['attivo'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Disattivo</span>' ?></td>
      <td class="small"><?= $u['ultimo_accesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_accesso'])) : '-' ?></td>
      <td><a href="form.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
