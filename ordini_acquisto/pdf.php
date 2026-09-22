<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_semplice.php';
require_login();
require_azienda();
require_sezione('ordini_acquisto');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT o.*, f.ragione_sociale forn_ragione_sociale, f.indirizzo forn_indirizzo, f.piva forn_piva,
                        f.telefono forn_telefono, f.email forn_email, f.referente forn_referente,
                        f.condizioni_pagamento forn_condizioni_pagamento, f.metodo_pagamento forn_metodo_pagamento,
                        f.iban forn_iban, f.bic_swift forn_bic_swift
                        FROM ordini_acquisto o JOIN fornitori f ON f.id = o.fornitore_id
                        WHERE o.id = ? AND o.azienda_id = ?");
$stmt->execute([$id, azienda_id()]);
$ordine = $stmt->fetch();
if (!$ordine) { die('Ordine non trovato.'); }

$stmtAz = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
$stmtAz->execute([azienda_id()]);
$azienda = $stmtAz->fetch();

$righe = $pdo->prepare("SELECT r.*, c.codice_interno, c.descrizione, um.codice um_codice
                         FROM ordini_acquisto_righe r JOIN componenti c ON c.id = r.componente_id
                         JOIN unita_misura um ON um.id = r.unita_misura_id WHERE r.ordine_id = ? ORDER BY r.id");
$righe->execute([$id]); $righe = $righe->fetchAll();

// segnala eventuali dati mancanti direttamente sul documento, invece di bloccare la
// generazione: è più utile vedere subito cosa completare in Anagrafica azienda/fornitore
$avvisiDatiMancanti = [];
if (empty($azienda['indirizzo']) || empty($azienda['piva'])) {
    $avvisiDatiMancanti[] = 'Dati azienda incompleti (indirizzo/P.IVA) - completali in Amministrazione > Dati azienda.';
}
if (empty($ordine['forn_indirizzo']) || empty($ordine['forn_piva'])) {
    $avvisiDatiMancanti[] = 'Dati fornitore incompleti (indirizzo/P.IVA) - completali nell\'anagrafica del fornitore.';
}

$pdf = new SimplePDF();
$margine = 40;
$larghezzaPagina = $pdf->larghezzaPagina();
$larghezzaUtile = $larghezzaPagina - 2 * $margine;

// ------------------------------------------------------------
// Intestazione: azienda (mittente) a sinistra, dati ordine a destra
// ------------------------------------------------------------
$y = $margine;
$xTesto = $margine;
$yMinimoDopoLogo = $margine + 90;
if (!empty($azienda['logo_jpeg'])) {
    $percorsoLogo = __DIR__ . '/../' . $azienda['logo_jpeg'];
    $infoLogo = @getimagesize($percorsoLogo);
    if ($infoLogo && $pdf->immagineJPEG($percorsoLogo, $margine, $y, 70)) {
        $xTesto = $margine + 85; // il testo dell'intestazione azienda parte dopo il logo
        $yMinimoDopoLogo = max($yMinimoDopoLogo, $margine + (70 * $infoLogo[1] / $infoLogo[0]) + 10);
    }
}
$pdf->testo($xTesto, $y, $azienda['ragione_sociale'] ?: '-', 13, true);
$y += 18;
if (!empty($azienda['indirizzo'])) { $y = $pdf->testoMultiriga($xTesto, $y, $azienda['indirizzo'], 260, 9); }
if (!empty($azienda['piva'])) { $pdf->testo($xTesto, $y, 'P.IVA: ' . $azienda['piva'], 9); $y += 13; }
if (!empty($azienda['telefono'])) { $pdf->testo($xTesto, $y, 'Tel: ' . $azienda['telefono'], 9); $y += 13; }
if (!empty($azienda['email'])) { $pdf->testo($xTesto, $y, 'Email: ' . $azienda['email'], 9); $y += 13; }

$xDx = $margine + 300; $largDx = $larghezzaUtile - 300;
$pdf->testo($xDx, $margine, 'ORDINE DI ACQUISTO', 15, true, 'right', $largDx);
$pdf->testo($xDx, $margine + 22, 'N. ' . $ordine['numero_ordine'], 12, true, 'right', $largDx);
$pdf->testo($xDx, $margine + 40, 'Data ordine: ' . date('d/m/Y', strtotime($ordine['data_ordine'])), 9, false, 'right', $largDx);
if ($ordine['data_consegna_prevista']) {
    $pdf->testo($xDx, $margine + 54, 'Consegna prevista: ' . date('d/m/Y', strtotime($ordine['data_consegna_prevista'])), 9, false, 'right', $largDx);
}
$pdf->testo($xDx, $margine + 68, 'Stato: ' . ucfirst($ordine['stato']), 9, false, 'right', $largDx);

$y = max($y, $yMinimoDopoLogo) + 10;
$pdf->linea($margine, $y, $margine + $larghezzaUtile, $y);
$y += 20;

// ------------------------------------------------------------
// Fornitore (destinatario)
// ------------------------------------------------------------
$pdf->testo($margine, $y, 'Fornitore', 10, true);
$y += 15;
$pdf->testo($margine, $y, $ordine['forn_ragione_sociale'], 10, true);
$y += 14;
if (!empty($ordine['forn_indirizzo'])) { $y = $pdf->testoMultiriga($margine, $y, $ordine['forn_indirizzo'], 300, 9); }
if (!empty($ordine['forn_piva'])) { $pdf->testo($margine, $y, 'P.IVA: ' . $ordine['forn_piva'], 9); $y += 13; }
if (!empty($ordine['forn_telefono'])) { $pdf->testo($margine, $y, 'Tel: ' . $ordine['forn_telefono'], 9); $y += 13; }
if (!empty($ordine['forn_email'])) { $pdf->testo($margine, $y, 'Email: ' . $ordine['forn_email'], 9); $y += 13; }
if (!empty($ordine['forn_referente'])) { $pdf->testo($margine, $y, 'Referente: ' . $ordine['forn_referente'], 9); $y += 13; }

if ($avvisiDatiMancanti) {
    $y += 8;
    foreach ($avvisiDatiMancanti as $avviso) {
        $pdf->testo($margine, $y, '(!) ' . $avviso, 8, false);
        $y += 12;
    }
}

$y += 15;

// ------------------------------------------------------------
// Tabella righe ordine
// ------------------------------------------------------------
// colonne: Riga | Codice | Descrizione | Cod.fornitore | Qtà | UM | Prezzo | Totale
$colX = ['riga' => $margine, 'codice' => $margine + 30, 'descrizione' => $margine + 100, 'codforn' => $margine + 250,
         'qta' => $margine + 330, 'um' => $margine + 375, 'prezzo' => $margine + 410, 'totale' => $margine + 465];
$colW = ['riga' => 25, 'codice' => 65, 'descrizione' => 145, 'codforn' => 75, 'qta' => 40, 'um' => 30, 'prezzo' => 50, 'totale' => 50];

$pdf->rettangoloRiempito($margine, $y, $larghezzaUtile, 18);
$pdf->testo($colX['riga'], $y + 13, '#', 9, true);
$pdf->testo($colX['codice'], $y + 13, 'Codice', 9, true);
$pdf->testo($colX['descrizione'], $y + 13, 'Descrizione', 9, true);
$pdf->testo($colX['codforn'], $y + 13, 'Cod. fornitore', 9, true);
$pdf->testo($colX['qta'], $y + 13, 'Q.tà', 9, true, 'right', $colW['qta']);
$pdf->testo($colX['um'], $y + 13, 'UM', 9, true);
$pdf->testo($colX['prezzo'], $y + 13, 'Prezzo', 9, true, 'right', $colW['prezzo']);
$pdf->testo($colX['totale'], $y + 13, 'Totale', 9, true, 'right', $colW['totale']);
$y += 22;

$totaleOrdine = 0;
$numRiga = 0;
foreach ($righe as $r) {
    $numRiga++;
    $totaleRiga = (float) $r['quantita_ordinata'] * (float) $r['prezzo_unitario'];
    $totaleOrdine += $totaleRiga;

    $righeDescrizione = $pdf->scomponiRighe($r['descrizione'], $colW['descrizione'], 9);
    $altezzaRiga = max(16, count($righeDescrizione) * 12 + 4);

    // nuova pagina se la riga non ci sta più (lascia spazio per il totale/note in fondo)
    if ($y + $altezzaRiga > $pdf->altezzaPagina() - 100) {
        $pdf->nuovaPagina();
        $y = $margine;
    }

    $pdf->testo($colX['riga'], $y + 10, (string) $numRiga, 9);
    $pdf->testo($colX['codice'], $y + 10, $r['codice_interno'], 9);
    $yDesc = $y + 10;
    foreach ($righeDescrizione as $rigaDesc) { $pdf->testo($colX['descrizione'], $yDesc, $rigaDesc, 9); $yDesc += 12; }
    $pdf->testo($colX['codforn'], $y + 10, $r['codice_fornitore'] ?: '-', 9);
    $pdf->testo($colX['qta'], $y + 10, number_format($r['quantita_ordinata'], 2, ',', '.'), 9, false, 'right', $colW['qta']);
    $pdf->testo($colX['um'], $y + 10, $r['um_codice'], 9);
    $pdf->testo($colX['prezzo'], $y + 10, number_format($r['prezzo_unitario'], 4, ',', '.'), 9, false, 'right', $colW['prezzo']);
    $pdf->testo($colX['totale'], $y + 10, number_format($totaleRiga, 2, ',', '.'), 9, false, 'right', $colW['totale']);

    $y += $altezzaRiga;
    $pdf->linea($margine, $y, $margine + $larghezzaUtile, $y, 0.3);
}

$y += 15;
if ($y > $pdf->altezzaPagina() - 100) { $pdf->nuovaPagina(); $y = $margine; }
$pdf->testo($colX['prezzo'], $y, 'Totale ordine', 10, true, 'right', $colW['prezzo']);
$pdf->testo($colX['totale'], $y, number_format($totaleOrdine, 2, ',', '.') . ' EUR', 10, true, 'right', $colW['totale'] + 20);

// ------------------------------------------------------------
// Modalità di pagamento (in calce)
// ------------------------------------------------------------
$y += 30;
if ($y > $pdf->altezzaPagina() - 90) { $pdf->nuovaPagina(); $y = $margine; }
$pdf->linea($margine, $y, $margine + $larghezzaUtile, $y, 0.3);
$y += 15;
$pdf->testo($margine, $y, 'Modalità di pagamento', 10, true);
$y += 14;
if (!empty($ordine['forn_condizioni_pagamento']) || !empty($ordine['forn_metodo_pagamento']) || !empty($ordine['forn_iban'])) {
    if (!empty($ordine['forn_condizioni_pagamento'])) { $pdf->testo($margine, $y, 'Condizioni: ' . $ordine['forn_condizioni_pagamento'], 9); $y += 13; }
    if (!empty($ordine['forn_metodo_pagamento'])) { $pdf->testo($margine, $y, 'Metodo: ' . $ordine['forn_metodo_pagamento'], 9); $y += 13; }
    if (!empty($ordine['forn_iban'])) { $pdf->testo($margine, $y, 'IBAN: ' . $ordine['forn_iban'], 9); $y += 13; }
    if (!empty($ordine['forn_bic_swift'])) { $pdf->testo($margine, $y, 'BIC/SWIFT: ' . $ordine['forn_bic_swift'], 9); $y += 13; }
} else {
    $pdf->testo($margine, $y, '(!) Condizioni e metodo di pagamento non compilati nell\'anagrafica del fornitore.', 8);
    $y += 13;
}

// ------------------------------------------------------------
// Note
// ------------------------------------------------------------
if (!empty($ordine['note'])) {
    $y += 16;
    if ($y > $pdf->altezzaPagina() - 60) { $pdf->nuovaPagina(); $y = $margine; }
    $pdf->testo($margine, $y, 'Note', 10, true);
    $y += 14;
    $pdf->testoMultiriga($margine, $y, $ordine['note'], $larghezzaUtile, 9);
}

$nomeFile = 'Ordine_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ordine['numero_ordine']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf->output();
