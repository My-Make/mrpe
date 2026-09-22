<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_azienda();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$escludiId = (int) ($_GET['escludi_id'] ?? 0);
$soloConBom = isset($_GET['solo_con_bom']);
$bomId = (int) ($_GET['bom_id'] ?? 0);

$sql = "SELECT DISTINCT c.id, c.codice_interno, c.descrizione, c.um_base_id FROM componenti c";
if ($soloConBom) { $sql .= " JOIN tipi_componente t ON t.id = c.tipo_componente_id"; }
if ($bomId) { $sql .= " JOIN bom_righe br ON br.componente_id = c.id"; }
$sql .= " WHERE c.azienda_id = ? AND c.attivo = 1";
$params = [azienda_id()];
if ($soloConBom) { $sql .= " AND t.ha_distinta_base = 1"; }
if ($bomId) {
    // verifica che la bom richiesta appartenga davvero all'azienda corrente, per sicurezza
    $sql .= " AND br.bom_id = ? AND EXISTS (SELECT 1 FROM bom b WHERE b.id = br.bom_id AND b.azienda_id = ?)";
    $params[] = $bomId; $params[] = azienda_id();
}
if ($q !== '') {
    $sql .= " AND (c.codice_interno LIKE ? OR c.descrizione LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}
if ($escludiId) {
    $sql .= " AND c.id != ?";
    $params[] = $escludiId;
}
$sql .= " ORDER BY c.codice_interno LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
