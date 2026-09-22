<?php
$titolo_pagina = 'Importa componenti da CSV';
require_once __DIR__ . '/../includes/header.php';
require_scrittura('componenti');

$COLONNE_ATTESE = ['codice_interno', 'sigla', 'descrizione', 'tipo_componente', 'categoria', 'tecnologia', 'um_base',
    'case_componente', 'prezzo_medio', 'valuta', 'scorta_minima', 'scorta_massima', 'punto_riordino',
    'lead_time_giorni', 'revisione_corrente', 'specifiche', 'note', 'attivo'];

$risultati = null; // popolato solo dopo un import eseguito

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_csv'])) {
    csrf_verify();

    if ($_FILES['file_csv']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file_csv']['tmp_name'])) {
        $errore = 'Caricamento del file non riuscito. Riprova.';
    } else {
        $handle = fopen($_FILES['file_csv']['tmp_name'], 'r');
        // rimuove un eventuale BOM UTF-8 in testa al file (Excel lo aggiunge spesso)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

        $intestazione = fgetcsv($handle, 0, ';');
        if (!$intestazione) {
            $errore = 'Il file sembra vuoto o non è un CSV valido.';
        } else {
            $intestazione = array_map('trim', $intestazione);
            $mancanti = array_diff(['codice_interno', 'descrizione', 'tipo_componente', 'um_base'], $intestazione);
            if ($mancanti) {
                $errore = 'Mancano colonne obbligatorie nell\'intestazione: ' . implode(', ', $mancanti);
            } else {
                // cache delle tabelle di lookup, per evitare una query per ogni riga del CSV
                $lookupTipo = []; $stmtL = $pdo->prepare("SELECT id, codice FROM tipi_componente WHERE azienda_id = ?"); $stmtL->execute([azienda_id()]);
                foreach ($stmtL->fetchAll() as $r) { $lookupTipo[strtoupper($r['codice'])] = $r['id']; }
                $lookupCategoria = []; $stmtL = $pdo->prepare("SELECT id, codice FROM categorie_componenti WHERE azienda_id = ? AND codice IS NOT NULL"); $stmtL->execute([azienda_id()]);
                foreach ($stmtL->fetchAll() as $r) { $lookupCategoria[strtoupper($r['codice'])] = $r['id']; }
                $lookupTecnologia = []; $stmtL = $pdo->prepare("SELECT id, codice FROM tecnologie WHERE azienda_id = ?"); $stmtL->execute([azienda_id()]);
                foreach ($stmtL->fetchAll() as $r) { $lookupTecnologia[strtoupper($r['codice'])] = $r['id']; }
                $lookupUm = []; $stmtL = $pdo->prepare("SELECT id, codice FROM unita_misura WHERE azienda_id = ?"); $stmtL->execute([azienda_id()]);
                foreach ($stmtL->fetchAll() as $r) { $lookupUm[strtoupper($r['codice'])] = $r['id']; }

                $stmtEsiste = $pdo->prepare("SELECT id FROM componenti WHERE azienda_id = ? AND codice_interno = ?");
                $stmtInsert = $pdo->prepare("INSERT INTO componenti (azienda_id, codice_interno, sigla, descrizione, tipo_componente_id, categoria_id, tecnologia_id, um_base_id,
                    case_componente, prezzo_medio, valuta, scorta_minima, scorta_massima, punto_riordino, lead_time_giorni, revisione_corrente, specifiche, note, attivo, utente_creazione_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmtUpdate = $pdo->prepare("UPDATE componenti SET sigla=?, descrizione=?, tipo_componente_id=?, categoria_id=?, tecnologia_id=?, um_base_id=?,
                    case_componente=?, prezzo_medio=?, valuta=?, scorta_minima=?, scorta_massima=?, punto_riordino=?, lead_time_giorni=?, revisione_corrente=?,
                    specifiche=?, note=?, attivo=?, utente_modifica_id=?, data_modifica=NOW() WHERE id=? AND azienda_id=?");

                $creati = 0; $aggiornati = 0; $errori = [];
                $numeroRiga = 1;
                while (($valori = fgetcsv($handle, 0, ';')) !== false) {
                    $numeroRiga++;
                    if (count(array_filter($valori, fn($v) => trim((string) $v) !== '')) === 0) { continue; } // riga vuota, salta

                    $valoriAllineati = array_slice(array_pad($valori, count($intestazione), null), 0, count($intestazione));
                    $r = array_combine($intestazione, $valoriAllineati);
                    $codiceInterno = trim($r['codice_interno'] ?? '');
                    $descrizione = trim($r['descrizione'] ?? '');
                    $tipoCodice = strtoupper(trim($r['tipo_componente'] ?? ''));
                    $umCodice = strtoupper(trim($r['um_base'] ?? ''));

                    if ($codiceInterno === '' || $descrizione === '') {
                        $errori[] = "Riga $numeroRiga: codice_interno o descrizione mancante.";
                        continue;
                    }
                    if (!isset($lookupTipo[$tipoCodice])) {
                        $errori[] = "Riga $numeroRiga ($codiceInterno): tipo componente \"$tipoCodice\" non trovato in Amministrazione > Tipi componente.";
                        continue;
                    }
                    if (!isset($lookupUm[$umCodice])) {
                        $errori[] = "Riga $numeroRiga ($codiceInterno): unità di misura \"$umCodice\" non trovata in Amministrazione > Unità di misura.";
                        continue;
                    }
                    $categoriaCodice = strtoupper(trim($r['categoria'] ?? ''));
                    $categoriaId = $categoriaCodice !== '' ? ($lookupCategoria[$categoriaCodice] ?? null) : null;
                    if ($categoriaCodice !== '' && $categoriaId === null) {
                        $errori[] = "Riga $numeroRiga ($codiceInterno): categoria \"$categoriaCodice\" non trovata, riga importata senza categoria.";
                    }
                    $tecnologiaCodice = strtoupper(trim($r['tecnologia'] ?? ''));
                    $tecnologiaId = $tecnologiaCodice !== '' ? ($lookupTecnologia[$tecnologiaCodice] ?? null) : null;
                    if ($tecnologiaCodice !== '' && $tecnologiaId === null) {
                        $errori[] = "Riga $numeroRiga ($codiceInterno): tecnologia \"$tecnologiaCodice\" non trovata, riga importata senza tecnologia.";
                    }

                    $sigla = trim($r['sigla'] ?? '') ?: null;
                    $caseComponente = trim($r['case_componente'] ?? '') ?: null;
                    $prezzoMedio = num_or($r['prezzo_medio'] ?? null, 0);
                    $valuta = trim($r['valuta'] ?? '') ?: 'EUR';
                    $scortaMin = num_or($r['scorta_minima'] ?? null, 0);
                    $scortaMax = num_or($r['scorta_massima'] ?? null, 0);
                    $puntoRiordino = num_or($r['punto_riordino'] ?? null, 0);
                    $leadTime = (int) ($r['lead_time_giorni'] ?? 0);
                    $revisione = trim($r['revisione_corrente'] ?? '') ?: 'A';
                    $specifiche = trim($r['specifiche'] ?? '');
                    $note = trim($r['note'] ?? '');
                    $attivoVal = isset($r['attivo']) && trim((string) $r['attivo']) !== '' ? ((int) $r['attivo'] ? 1 : 0) : 1;

                    try {
                        $stmtEsiste->execute([azienda_id(), $codiceInterno]);
                        $esistente = $stmtEsiste->fetch();

                        if ($esistente) {
                            $stmtUpdate->execute([
                                $sigla, $descrizione, $lookupTipo[$tipoCodice], $categoriaId, $tecnologiaId, $lookupUm[$umCodice],
                                $caseComponente, $prezzoMedio, $valuta, $scortaMin, $scortaMax, $puntoRiordino, $leadTime, $revisione,
                                $specifiche, $note, $attivoVal, $_SESSION['user_id'], $esistente['id'], azienda_id()
                            ]);
                            $aggiornati++;
                        } else {
                            $stmtInsert->execute([
                                azienda_id(), $codiceInterno, $sigla, $descrizione, $lookupTipo[$tipoCodice], $categoriaId, $tecnologiaId, $lookupUm[$umCodice],
                                $caseComponente, $prezzoMedio, $valuta, $scortaMin, $scortaMax, $puntoRiordino, $leadTime, $revisione,
                                $specifiche, $note, $attivoVal, $_SESSION['user_id']
                            ]);
                            // ogni componente nuovo ha bisogno anche della sua UM base come unità "di default"
                            $pdo->prepare("INSERT INTO componenti_unita_misura (componente_id, unita_misura_id, fattore_conversione, is_default) VALUES (?,?,1,1)")
                                ->execute([(int) $pdo->lastInsertId(), $lookupUm[$umCodice]]);
                            $creati++;
                        }
                    } catch (PDOException $e) {
                        $errori[] = "Riga $numeroRiga ($codiceInterno): errore database - " . $e->getMessage();
                    }
                }
                fclose($handle);

                log_attivita($pdo, 'importa_componenti_csv', 'componenti', null, "creati:$creati aggiornati:$aggiornati errori:" . count($errori));
                $risultati = ['creati' => $creati, 'aggiornati' => $aggiornati, 'errori' => $errori];
            }
        }
    }
}
?>
<h4><i class="bi bi-file-earmark-arrow-up"></i> Importa componenti da CSV</h4>

<?php if ($risultati): ?>
  <div class="alert alert-<?= $risultati['errori'] ? 'warning' : 'success' ?>">
    <strong><?= $risultati['creati'] ?></strong> componenti creati, <strong><?= $risultati['aggiornati'] ?></strong> aggiornati.
    <?php if ($risultati['errori']): ?><br><?= count($risultati['errori']) ?> riga/e con problemi (vedi sotto).<?php endif; ?>
  </div>
  <?php if ($risultati['errori']): ?>
    <div class="card p-3 mb-3">
      <h6>Dettaglio righe non importate/con avvisi</h6>
      <ul class="small mb-0">
        <?php foreach ($risultati['errori'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <a href="list.php" class="btn btn-primary">Vai all'elenco componenti</a>
  <a href="importa_csv.php" class="btn btn-outline-secondary">Importa un altro file</a>
<?php else: ?>

  <?php if (!empty($errore)): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

  <div class="card p-4">
    <p>Carica un file CSV (separatore <code>;</code>, codifica UTF-8) con la stessa struttura dell'<a href="esporta_csv.php">export</a>. Ogni riga con un <code>codice_interno</code> già esistente per la tua azienda <strong>aggiorna</strong> quel componente; un codice nuovo <strong>crea</strong> un componente.</p>
    <p class="small text-muted">Colonne attese (nell'ordine, con intestazione nella prima riga): <code><?= h(implode(';', $COLONNE_ATTESE)) ?></code>. Obbligatorie: <code>codice_interno</code>, <code>descrizione</code>, <code>tipo_componente</code>, <code>um_base</code> — questi ultimi due devono corrispondere a un <strong>codice</strong> già configurato in Amministrazione (non alla descrizione).</p>

    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <div class="col-md-8"><input type="file" name="file_csv" class="form-control" accept=".csv" required></div>
      <div class="col-md-4"><button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Importa</button></div>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
