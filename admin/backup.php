<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

// ------------------------------------------------------------
// Esportazione backup (streaming diretto al browser, nessun file salvato sul server)
// ------------------------------------------------------------
if (isset($_GET['scarica'])) {
    csrf_verify_get(); // vedi includes/functions.php: verifica il token passato in query string

    while (ob_get_level() > 0) { ob_end_clean(); } // evita che output bufferizzato corrompa il file

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', DB_NAME) . '_' . date('Y-m-d_His') . '.sql"');
    header('Cache-Control: no-store, no-cache');
    header('X-Accel-Buffering: no');

    echo "-- ============================================================\n";
    echo "-- Backup del database " . DB_NAME . "\n";
    echo "-- Generato il " . date('Y-m-d H:i:s') . " da MRP Elettronica (admin/backup.php)\n";
    echo "-- ============================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tabelle = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabelle as $tabella) {
        echo "-- ------------------------------------------------------------\n";
        echo "-- Tabella: $tabella\n";
        echo "-- ------------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$tabella`;\n";

        $creazione = $pdo->query("SHOW CREATE TABLE `$tabella`")->fetch();
        echo $creazione['Create Table'] . ";\n\n";

        $totaleRighe = (int) $pdo->query("SELECT COUNT(*) FROM `$tabella`")->fetchColumn();
        if ($totaleRighe === 0) { continue; }

        $dimensioneBlocco = 500;
        $colonne = null;
        for ($offset = 0; $offset < $totaleRighe; $offset += $dimensioneBlocco) {
            $stmt = $pdo->query("SELECT * FROM `$tabella` LIMIT $dimensioneBlocco OFFSET $offset");
            $righeSql = [];
            while ($riga = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($colonne === null) { $colonne = array_keys($riga); }
                $valori = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($riga));
                $righeSql[] = '(' . implode(',', $valori) . ')';
            }
            if ($righeSql) {
                $elencoColonne = '`' . implode('`,`', $colonne) . '`';
                echo "INSERT INTO `$tabella` ($elencoColonne) VALUES\n" . implode(",\n", $righeSql) . ";\n\n";
            }
            flush();
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    log_attivita($pdo, 'backup_database', 'sistema', null, DB_NAME);
    exit;
}

// ------------------------------------------------------------
// Pagina informativa
// ------------------------------------------------------------
$titolo_pagina = 'Backup database';
require_once __DIR__ . '/../includes/header.php';

$tabelle = $pdo->query("SELECT TABLE_NAME nome, TABLE_ROWS righe_stimate,
        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) dimensione_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME")->fetchAll();
$dimensioneTotaleMb = array_sum(array_column($tabelle, 'dimensione_mb'));
?>
<h4><i class="bi bi-hdd-network"></i> Backup database</h4>
<p class="text-muted">Esporta una copia completa del database (struttura + dati) in un file <code>.sql</code> standard, importabile con phpMyAdmin o dal client <code>mysql</code> — lo stesso formato usato da <code>db.sql</code> e <code>migration_v2.sql</code> in questo progetto.</p>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">Database</div><div class="fw-bold"><?= h(DB_NAME) ?></div></div></div>
  <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">Tabelle</div><div class="fs-4 fw-bold"><?= count($tabelle) ?></div></div></div>
  <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">Dimensione stimata</div><div class="fs-4 fw-bold"><?= number_format($dimensioneTotaleMb, 1, ',', '.') ?> MB</div></div></div>
  <div class="col-md-3 d-flex align-items-center justify-content-center">
    <a href="?scarica=1&csrf=<?= h(csrf_token()) ?>" class="btn btn-primary btn-lg"><i class="bi bi-download"></i> Scarica backup ora</a>
  </div>
</div>

<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle"></i> Il file generato contiene <strong>tutti i dati</strong>, incluse eventuali chiavi API dei distributori configurate in Fornitori. Conservalo in un posto sicuro e non condividerlo né caricarlo su repository pubblici.
</div>

<div class="card">
<table class="table table-sm mb-0">
  <thead><tr><th>Tabella</th><th class="text-end">Righe (stimate)</th><th class="text-end">Dimensione</th></tr></thead>
  <tbody>
  <?php foreach ($tabelle as $t): ?>
    <tr>
      <td><?= h($t['nome']) ?></td>
      <td class="text-end"><?= number_format((int)$t['righe_stimate'], 0, ',', '.') ?></td>
      <td class="text-end"><?= number_format($t['dimensione_mb'], 2, ',', '.') ?> MB</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="text-muted small mt-3">
  Per database molto grandi, l'esportazione via browser può richiedere tempo o essere limitata dai parametri PHP del tuo hosting
  (<code>max_execution_time</code>, <code>memory_limit</code>). Per un uso ricorrente/automatizzato, valuta un backup pianificato lato hosting (es. cron con <code>mysqldump</code>), se il tuo piano lo consente.
</p>

<a href="index.php" class="btn btn-outline-secondary mt-2">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
