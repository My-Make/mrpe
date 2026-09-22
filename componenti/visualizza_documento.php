<?php
/**
 * Serve un allegato del componente forzando l'apertura inline nel browser
 * (Content-Disposition: inline) invece del download, per i tipi di file
 * che il browser è in grado di visualizzare nativamente (PDF, immagini, testo).
 *
 * Uso: visualizza_documento.php?id=ID_DOCUMENTO
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('componenti');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT cd.* FROM componenti_documenti cd
                        JOIN componenti c ON c.id = cd.componente_id
                        WHERE cd.id = ? AND c.azienda_id = ?");
$stmt->execute([$id, azienda_id()]);
$doc = $stmt->fetch();

if (!$doc || $doc['tipo_allegato'] !== 'file') {
    http_response_code(404);
    die('Documento non trovato.');
}

// solo i tipi che un browser sa mostrare nativamente hanno senso "inline";
// per gli altri (doc, xls, zip...) non c'è vantaggio, il browser scaricherebbe comunque
$mimeInline = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'txt' => 'text/plain; charset=utf-8',
];
$estensione = strtolower($doc['tipo_file']);
if (!isset($mimeInline[$estensione])) {
    http_response_code(415);
    die('Questo tipo di file non può essere visualizzato inline nel browser. Usa il pulsante di download.');
}

$percorsoFisico = realpath(__DIR__ . '/../' . $doc['percorso']);
$cartellaConsentita = realpath(__DIR__ . '/../uploads/documenti/');
if (!$percorsoFisico || !$cartellaConsentita || !str_starts_with($percorsoFisico, $cartellaConsentita) || !is_file($percorsoFisico)) {
    http_response_code(404);
    die('File non trovato sul server.');
}

header('Content-Type: ' . $mimeInline[$estensione]);
header('Content-Disposition: inline; filename="' . rawurlencode($doc['nome_file']) . '"');
header('Content-Length: ' . filesize($percorsoFisico));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($percorsoFisico);
exit;
