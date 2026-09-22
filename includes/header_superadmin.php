<?php
require_once __DIR__ . '/functions.php';
require_superadmin();
$u = current_user();
?>
<!DOCTYPE html>
<html lang="<?= h(lingua_corrente()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($titolo_pagina) ? h($titolo_pagina) . ' - ' : '' ?>Super Admin - MRP Elettronica</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= h(base_url('assets/style.css')) ?>?v=<?= h(APP_VERSION) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark navbar-expand-lg" style="background:#3a1e5f;">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= h(base_url('superadmin/index.php')) ?>"><i class="bi bi-building-gear"></i> <?= h(t('nav.superadmin')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?= h(base_url('superadmin/aziende.php')) ?>"><i class="bi bi-buildings"></i> Aziende</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= h(base_url('superadmin/backup.php')) ?>"><i class="bi bi-hdd-network"></i> Backup</a></li>
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
      <span class="navbar-text text-white me-3"><?= h($u['nome'] . ' ' . $u['cognome']) ?> <span class="badge bg-light text-dark">Super Admin</span></span>
      <a href="<?= h(base_url('superadmin/profilo.php')) ?>" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-person-circle"></i> <?= h(t('nav.profilo')) ?></a>
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
