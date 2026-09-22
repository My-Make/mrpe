<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('giacenze');
header('Content-Type: application/json');

$componenteId = (int) ($_GET['componente_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, codice_lotto_interno, quantita_residua FROM lotti
                        WHERE componente_id = ? AND azienda_id = ? AND quantita_residua > 0 AND stato = 'disponibile' ORDER BY data_ricezione");
$stmt->execute([$componenteId, azienda_id()]);
echo json_encode($stmt->fetchAll());
