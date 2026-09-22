<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('componenti');
header('Content-Type: application/json');

$ricerca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 24;
$offset = ($pagina - 1) * $perPagina;

$where = "c.azienda_id = ? AND c.immagine IS NOT NULL AND c.immagine != ''";
$params = [azienda_id()];
if ($ricerca !== '') {
    $where .= " AND (c.codice_interno LIKE ? OR c.descrizione LIKE ? OR c.immagine LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}

// conteggio totale (immagini distinte che soddisfano il filtro)
$stmtTot = $pdo->prepare("SELECT COUNT(*) c FROM (SELECT c.immagine FROM componenti c WHERE $where GROUP BY c.immagine) t");
$stmtTot->execute($params);
$totale = (int) $stmtTot->fetch()['c'];

// pagina di risultati: per ogni immagine, un componente di esempio che la usa (per contesto) + quanti la usano
$sql = "SELECT c.immagine, MIN(c.codice_interno) codice_esempio, COUNT(*) n_componenti
        FROM componenti c
        WHERE $where
        GROUP BY c.immagine
        ORDER BY c.immagine
        LIMIT $perPagina OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode([
    'immagini' => $stmt->fetchAll(),
    'totale' => $totale,
    'pagina' => $pagina,
    'per_pagina' => $perPagina,
]);
