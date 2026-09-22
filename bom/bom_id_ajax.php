<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_azienda();
header('Content-Type: application/json');

$componenteId = (int) ($_GET['componente_id'] ?? 0);

$stmt = $pdo->prepare("SELECT b.id, b.revisione FROM bom b JOIN componenti c ON c.id = b.componente_padre_id
                        WHERE b.componente_padre_id = ? AND c.azienda_id = ?
                        ORDER BY (b.stato = 'attiva') DESC, b.id DESC LIMIT 1");
$stmt->execute([$componenteId, azienda_id()]);
$riga = $stmt->fetch();

// revisione "base" per la proposta: la primissima mai creata per questo componente padre
// (stabile nel tempo), non quella attuale - che dopo un ECO già implementato potrebbe
// contenere il riferimento a quell'ECO precedente (es. "A-ECO-2026-0001"): usare quella
// come base incatenerebbe i riferimenti invece di riflettere sempre l'ECO corrente
$revisioneBase = null;
if ($riga) {
    $stmtPrima = $pdo->prepare("SELECT revisione FROM bom WHERE componente_padre_id = ? AND azienda_id = ? ORDER BY id ASC LIMIT 1");
    $stmtPrima->execute([$componenteId, azienda_id()]);
    $revisioneBase = $stmtPrima->fetch()['revisione'] ?? $riga['revisione'];
}

echo json_encode(['bom_id' => $riga['id'] ?? null, 'revisione' => $riga['revisione'] ?? null, 'revisione_base' => $revisioneBase]);
