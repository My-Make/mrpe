<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_azienda();
require_sezione('componenti');

// tutte le colonne esportabili, con l'espressione/alias usato nella query e l'etichetta in CSV
$COLONNE_DISPONIBILI = ['codice_interno', 'sigla', 'descrizione', 'tipo_componente', 'categoria', 'tecnologia', 'um_base',
    'case_componente', 'prezzo_medio', 'valuta', 'scorta_minima', 'scorta_massima', 'punto_riordino',
    'lead_time_giorni', 'revisione_corrente', 'specifiche', 'note', 'attivo'];

// colonne scelte dall'utente (se non specificate, es. link diretto senza passare dalla finestra
// di scelta, esporta tutte); codice_interno resta sempre incluso perché è la chiave usata dall'import
$colonneRichieste = $_GET['colonne'] ?? $COLONNE_DISPONIBILI;
$colonneSelezionate = array_values(array_intersect($COLONNE_DISPONIBILI, $colonneRichieste));
if (!in_array('codice_interno', $colonneSelezionate, true)) { array_unshift($colonneSelezionate, 'codice_interno'); }
if (!$colonneSelezionate) { $colonneSelezionate = $COLONNE_DISPONIBILI; }

$ricerca = trim($_GET['q'] ?? '');
$tipoFiltro = (int) ($_GET['tipo'] ?? 0);
$mostraDisattivati = isset($_GET['disattivati']);

$sql = "SELECT c.codice_interno, c.sigla, c.descrizione, t.codice tipo_codice, cat.codice categoria_codice,
        tec.codice tecnologia_codice, um.codice um_codice, c.case_componente, c.prezzo_medio, c.valuta,
        c.scorta_minima, c.scorta_massima, c.punto_riordino, c.lead_time_giorni, c.revisione_corrente,
        c.specifiche, c.note, c.attivo
        FROM componenti c
        JOIN unita_misura um ON um.id = c.um_base_id
        JOIN tipi_componente t ON t.id = c.tipo_componente_id
        LEFT JOIN tecnologie tec ON tec.id = c.tecnologia_id
        LEFT JOIN categorie_componenti cat ON cat.id = c.categoria_id
        WHERE c.azienda_id = ? AND " . ($mostraDisattivati ? "1=1" : "c.attivo = 1");
$params = [azienda_id()];
if ($ricerca !== '') {
    $sql .= " AND (c.codice_interno LIKE ? OR c.sigla LIKE ? OR c.descrizione LIKE ?)";
    $params[] = "%$ricerca%"; $params[] = "%$ricerca%"; $params[] = "%$ricerca%";
}
if ($tipoFiltro) {
    $sql .= " AND c.tipo_componente_id = ?";
    $params[] = $tipoFiltro;
}
$sql .= " ORDER BY c.codice_interno";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="componenti_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, perché Excel su Windows riconosca correttamente gli accenti

// mappa colonna esportabile -> chiave nella riga restituita dalla query
$mappaChiavi = [
    'codice_interno' => 'codice_interno', 'sigla' => 'sigla', 'descrizione' => 'descrizione',
    'tipo_componente' => 'tipo_codice', 'categoria' => 'categoria_codice', 'tecnologia' => 'tecnologia_codice',
    'um_base' => 'um_codice', 'case_componente' => 'case_componente', 'prezzo_medio' => 'prezzo_medio',
    'valuta' => 'valuta', 'scorta_minima' => 'scorta_minima', 'scorta_massima' => 'scorta_massima',
    'punto_riordino' => 'punto_riordino', 'lead_time_giorni' => 'lead_time_giorni', 'revisione_corrente' => 'revisione_corrente',
    'specifiche' => 'specifiche', 'note' => 'note', 'attivo' => 'attivo',
];

fputcsv($out, $colonneSelezionate, ';');

while ($riga = $stmt->fetch()) {
    $rigaCsv = [];
    foreach ($colonneSelezionate as $col) { $rigaCsv[] = $riga[$mappaChiavi[$col]]; }
    fputcsv($out, $rigaCsv, ';');
}
fclose($out);
