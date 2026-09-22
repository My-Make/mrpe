<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('ordini_acquisto');
header('Content-Type: application/json');

$componenteId = (int) ($_GET['componente_id'] ?? 0);
$fornitoreId = (int) ($_GET['fornitore_id'] ?? 0);

$stmt = $pdo->prepare("SELECT cf.codice_fornitore, cf.prezzo, cf.moq
                        FROM componenti_fornitori cf
                        JOIN componenti c ON c.id = cf.componente_id
                        WHERE cf.componente_id = ? AND cf.fornitore_id = ? AND c.azienda_id = ?
                        ORDER BY cf.preferito DESC
                        LIMIT 1");
$stmt->execute([$componenteId, $fornitoreId, azienda_id()]);
$riga = $stmt->fetch();

if ($riga) {
    $riga['trovato'] = true;
    echo json_encode($riga);
} else {
    echo json_encode(['trovato' => false]);
}
