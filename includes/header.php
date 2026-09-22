<?php
require_once __DIR__ . '/functions.php';
require_login();
require_azienda(); // pagina aziendale: il superadmin non vi accede, serve un'azienda in sessione

// Rileva automaticamente in quale "sezione" dell'app ci troviamo dalla cartella
// dello script richiesto (es. .../mrp_app/componenti/list.php -> 'componenti') e
// blocca l'accesso se l'utente corrente non è abilitato per quella sezione.
require_sezione(sezione_corrente());

$u = current_user();
$pagina = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="<?= h(lingua_corrente()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($titolo_pagina) ? h($titolo_pagina) . ' - ' : '' ?>MRP Elettronica</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= h(base_url('assets/style.css')) ?>?v=<?= h(APP_VERSION) ?>" rel="stylesheet">
<script>window.BASE_URL_APP = <?= json_encode(base_url('')) ?>;</script>
<script src="<?= h(base_url('assets/selettore_componente.js')) ?>?v=<?= h(APP_VERSION) ?>"></script>
</head>
<body>
<nav class="navbar navbar-dark navbar-expand-lg" style="background:#1e3a5f;">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= h(base_url('index.php')) ?>"><i class="bi bi-cpu"></i> MRP Elettronica</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <?php if (utente_ha_accesso_sezione('componenti')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('componenti/list.php')) ?>"><i class="bi bi-box-seam"></i> <?= h(t('nav.componenti')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('bom')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('bom/list.php')) ?>"><i class="bi bi-diagram-3"></i> <?= h(t('nav.bom')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('eco')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('eco/list.php')) ?>"><i class="bi bi-pencil-square"></i> <?= h(t('nav.eco')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('giacenze')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('giacenze/view.php')) ?>"><i class="bi bi-boxes"></i> <?= h(t('nav.giacenze')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('lotti')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('lotti/list.php')) ?>"><i class="bi bi-upc-scan"></i> <?= h(t('nav.lotti')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('magazzini')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('magazzini/list.php')) ?>"><i class="bi bi-building"></i> <?= h(t('nav.magazzini')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('fornitori')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('fornitori/list.php')) ?>"><i class="bi bi-truck"></i> <?= h(t('nav.fornitori')) ?></a></li><?php endif; ?>
        <?php if (utente_ha_accesso_sezione('ordini_acquisto')): ?><li class="nav-item"><a class="nav-link" href="<?= h(base_url('ordini_acquisto/list.php')) ?>"><i class="bi bi-cart"></i> <?= h(t('nav.ordini_acquisto')) ?></a></li><?php endif; ?>
        <?php if (is_admin()): ?>
        <li class="nav-item"><a class="nav-link" href="<?= h(base_url('utenti/list.php')) ?>"><i class="bi bi-people"></i> <?= h(t('nav.utenti')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= h(base_url('admin/index.php')) ?>"><i class="bi bi-gear"></i> <?= h(t('nav.admin')) ?></a></li>
        <?php endif; ?>
      </ul>
      <div class="dropdown me-3">
        <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <?= h(LINGUE_DISPONIBILI[lingua_corrente()]) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach (LINGUE_DISPONIBILI as $codice => $nomeLingua): ?>
            <li><a class="dropdown-item <?= $codice === lingua_corrente() ? 'active' : '' ?>" href="<?= h(base_url('includes/cambia_lingua.php')) ?>?lingua=<?= $codice ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"><?= h($nomeLingua) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <span class="navbar-text text-white me-3">
        <?= h($u['nome'] . ' ' . $u['cognome']) ?> <span class="badge bg-secondary"><?= h($u['ruolo']) ?></span>
        <?php if (is_sola_lettura()): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye"></i> Sola lettura in questa sezione</span><?php endif; ?>
      </span>
      <a href="<?= h(base_url('auth/logout.php')) ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> <?= h(t('nav.logout')) ?></a>
    </div>
  </div>
</nav>
<div class="container-fluid py-4 px-4">
<?php if (!empty($_SESSION['flash_msg'])): ?>
  <div class="alert alert-<?= h($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show">
    <?= h($_SESSION['flash_msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
<?php endif; ?>
