<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_azienda();
header('Content-Type: application/json');

$bomId = (int) ($_GET['bom_id'] ?? 0);
$componenteId = (int) ($_GET['componente_id'] ?? 0);

$stmt = $pdo->prepare("SELECT br.quantita, br.unita_misura_id, br.designatore, um.codice um_codice
                        FROM bom_righe br
                        JOIN bom b ON b.id = br.bom_id
                        JOIN unita_misura um ON um.id = br.unita_misura_id
                        WHERE br.bom_id = ? AND br.componente_id = ? AND b.azienda_id = ?
                        LIMIT 1");
$stmt->execute([$bomId, $componenteId, azienda_id()]);
$riga = $stmt->fetch();

echo json_encode($riga ?: null);
