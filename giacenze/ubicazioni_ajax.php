<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('giacenze');
header('Content-Type: application/json');

$magazzinoId = (int) ($_GET['magazzino_id'] ?? 0);
// verifica che il magazzino appartenga all'azienda corrente prima di restituire le ubicazioni
$stmt = $pdo->prepare("SELECT u.id, u.codice, u.descrizione FROM ubicazioni u
                        JOIN magazzini m ON m.id = u.magazzino_id
                        WHERE u.magazzino_id = ? AND m.azienda_id = ? ORDER BY u.codice");
$stmt->execute([$magazzinoId, azienda_id()]);
echo json_encode($stmt->fetchAll());
