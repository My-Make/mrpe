<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('componenti');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: list.php'); exit; }
csrf_verify();

$componenteId = (int) $_POST['componente_id'];
$azione = $_POST['azione'] ?? '';

// Verifica che il componente appartenga all'azienda dell'utente collegato:
// blocca chiunque tenti di manipolare l'id per agire sui dati di un'altra azienda.
$stmtVerifica = $pdo->prepare("SELECT id FROM componenti WHERE id = ? AND azienda_id = ?");
$stmtVerifica->execute([$componenteId, azienda_id()]);
if (!$stmtVerifica->fetch()) {
    http_response_code(403);
    die('Componente non trovato.');
}

function torna($componenteId, $msg = null, $tipo = 'success') {
    global $pdo;
    if ($msg) { $_SESSION['flash_msg'] = $msg; $_SESSION['flash_type'] = $tipo; }
    if ($tipo === 'success') {
        // qualunque operazione riuscita sulla scheda (fornitori, UM, documenti, alternativi,
        // revisioni...) conta come modifica del componente per il log "modificato da/il"
        $pdo->prepare("UPDATE componenti SET utente_modifica_id=?, data_modifica=NOW() WHERE id=? AND azienda_id=?")
            ->execute([$_SESSION['user_id'] ?? null, $componenteId, azienda_id()]);
    }
    $tab = $_POST['tab'] ?? '';
    header('Location: view.php?id=' . $componenteId . ($tab ? '&tab=' . urlencode($tab) : ''));
    exit;
}

try {
    switch ($azione) {

        case 'aggiungi_um':
            $stmt = $pdo->prepare("INSERT INTO componenti_unita_misura (componente_id, unita_misura_id, fattore_conversione, is_default)
                                    VALUES (?,?,?,0)");
            $stmt->execute([$componenteId, (int)$_POST['unita_misura_id'], num_or($_POST['fattore_conversione'] ?? null, 1)]);
            torna($componenteId, 'Unità di misura aggiunta.');
            break;

        case 'elimina_um':
            $pdo->prepare("DELETE FROM componenti_unita_misura WHERE id=? AND componente_id=? AND is_default=0")
                ->execute([(int)$_POST['riga_id'], $componenteId]);
            torna($componenteId, 'Unità di misura rimossa.');
            break;

        case 'aggiungi_fornitore':
            $fornitoreId = (int) $_POST['fornitore_id'];
            $stmtF = $pdo->prepare("SELECT id FROM fornitori WHERE id=? AND azienda_id=?");
            $stmtF->execute([$fornitoreId, azienda_id()]);
            if (!$stmtF->fetch()) { torna($componenteId, 'Fornitore non valido.', 'danger'); }
            $stmt = $pdo->prepare("INSERT INTO componenti_fornitori
                (componente_id, fornitore_id, codice_fornitore, produttore, codice_produttore, url_prodotto, prezzo, valuta, lead_time_giorni, moq, multiplo_ordine, shipping_cost, preferito, note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $componenteId, $fornitoreId, trim($_POST['codice_fornitore']), trim($_POST['produttore'] ?? '') ?: null, trim($_POST['codice_produttore'] ?? '') ?: null, trim($_POST['url_prodotto'] ?? '') ?: null,
                num_or($_POST['prezzo'] ?? null, null), trim($_POST['valuta']) ?: 'EUR',
                (int)($_POST['lead_time_giorni'] ?? 0), num_or($_POST['moq'] ?? null, 1),
                num_or($_POST['multiplo_ordine'] ?? null, 1), num_or($_POST['shipping_cost'] ?? null, null), isset($_POST['preferito']) ? 1 : 0,
                trim($_POST['note'] ?? '')
            ]);
            torna($componenteId, 'Fornitore collegato al componente.');
            break;

        case 'elimina_fornitore':
            $pdo->prepare("DELETE FROM componenti_fornitori WHERE id=? AND componente_id=?")
                ->execute([(int)$_POST['riga_id'], $componenteId]);
            torna($componenteId, 'Collegamento fornitore rimosso.');
            break;

        case 'modifica_riga_fornitore':
            $stmt = $pdo->prepare("UPDATE componenti_fornitori SET
                    codice_fornitore=?, produttore=?, codice_produttore=?, url_prodotto=?, prezzo=?, valuta=?, lead_time_giorni=?,
                    moq=?, multiplo_ordine=?, shipping_cost=?, preferito=?, note=?
                    WHERE id=? AND componente_id=?");
            $stmt->execute([
                trim($_POST['codice_fornitore']), trim($_POST['produttore'] ?? '') ?: null, trim($_POST['codice_produttore'] ?? '') ?: null, trim($_POST['url_prodotto'] ?? '') ?: null,
                num_or($_POST['prezzo'] ?? null, null), trim($_POST['valuta']) ?: 'EUR', (int)($_POST['lead_time_giorni'] ?? 0),
                num_or($_POST['moq'] ?? null, 1), num_or($_POST['multiplo_ordine'] ?? null, 1), num_or($_POST['shipping_cost'] ?? null, null),
                isset($_POST['preferito']) ? 1 : 0, trim($_POST['note'] ?? ''),
                (int)$_POST['riga_id'], $componenteId
            ]);
            torna($componenteId, 'Dati fornitore aggiornati.');
            break;

        case 'aggiungi_alternativo':
            $altId = (int) $_POST['componente_alternativo_id'];
            if ($altId === $componenteId) { torna($componenteId, 'Un componente non può essere alternativo di se stesso.', 'danger'); }
            $stmtA = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
            $stmtA->execute([$altId, azienda_id()]);
            if (!$stmtA->fetch()) { torna($componenteId, 'Componente alternativo non valido.', 'danger'); }
            $stmt = $pdo->prepare("INSERT IGNORE INTO componenti_alternativi (componente_id, componente_alternativo_id, compatibilita, note)
                                    VALUES (?,?,?,?)");
            $stmt->execute([$componenteId, $altId, $_POST['compatibilita'], trim($_POST['note'] ?? '')]);
            // relazione reciproca
            $pdo->prepare("INSERT IGNORE INTO componenti_alternativi (componente_id, componente_alternativo_id, compatibilita, note)
                            VALUES (?,?,?,?)")->execute([$altId, $componenteId, $_POST['compatibilita'], trim($_POST['note'] ?? '')]);
            torna($componenteId, 'Componente alternativo aggiunto.');
            break;

        case 'elimina_alternativo':
            $riga = $pdo->prepare("SELECT * FROM componenti_alternativi WHERE id=? AND componente_id=?");
            $riga->execute([(int)$_POST['riga_id'], $componenteId]);
            $r = $riga->fetch();
            if ($r) {
                $pdo->prepare("DELETE FROM componenti_alternativi WHERE id=?")->execute([$r['id']]);
                $pdo->prepare("DELETE FROM componenti_alternativi WHERE componente_id=? AND componente_alternativo_id=?")
                    ->execute([$r['componente_alternativo_id'], $r['componente_id']]);
            }
            torna($componenteId, 'Componente alternativo rimosso.');
            break;

        case 'carica_documento':
            if (empty($_FILES['file']['name'])) { torna($componenteId, 'Nessun file selezionato.', 'danger'); }
            $ris = gestisci_upload($_FILES['file']);
            if (!$ris['ok']) { torna($componenteId, $ris['errore'], 'danger'); }
            $stmt = $pdo->prepare("INSERT INTO componenti_documenti (componente_id, tipo_allegato, nome_file, percorso, tipo_file, descrizione, versione, utente_id)
                                    VALUES (?,'file',?,?,?,?,?,?)");
            $stmt->execute([$componenteId, $ris['nome_file'], $ris['percorso'], $ris['tipo'], trim($_POST['descrizione'] ?? ''), trim($_POST['versione']) ?: '1.0', $_SESSION['user_id']]);
            torna($componenteId, 'Documento caricato.');
            break;

        case 'aggiungi_link':
            $url = trim($_POST['url'] ?? '');
            $etichetta = trim($_POST['etichetta'] ?? '');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                torna($componenteId, 'Inserisci un indirizzo web valido (deve iniziare con http:// o https://).', 'danger');
            }
            if ($etichetta === '') { $etichetta = parse_url($url, PHP_URL_HOST) ?: $url; }
            $stmt = $pdo->prepare("INSERT INTO componenti_documenti (componente_id, tipo_allegato, nome_file, percorso, tipo_file, descrizione, utente_id)
                                    VALUES (?,'link',?,?,'link',?,?)");
            $stmt->execute([$componenteId, $etichetta, $url, trim($_POST['descrizione'] ?? ''), $_SESSION['user_id']]);
            torna($componenteId, 'Link aggiunto.');
            break;

        case 'elimina_documento':
            $stmt = $pdo->prepare("SELECT * FROM componenti_documenti WHERE id=? AND componente_id=?");
            $stmt->execute([(int)$_POST['riga_id'], $componenteId]);
            $doc = $stmt->fetch();
            if ($doc) {
                if ($doc['tipo_allegato'] === 'file') {
                    $percorsoFisico = __DIR__ . '/../' . $doc['percorso'];
                    if (is_file($percorsoFisico)) { @unlink($percorsoFisico); }
                }
                $pdo->prepare("DELETE FROM componenti_documenti WHERE id=?")->execute([$doc['id']]);
            }
            torna($componenteId, $doc && $doc['tipo_allegato'] === 'link' ? 'Link rimosso.' : 'Documento eliminato.');
            break;

        case 'nuova_revisione':
            $numRev = trim($_POST['numero_revisione']);
            $stmt = $pdo->prepare("INSERT INTO revisioni (azienda_id, tipo_oggetto, oggetto_id, numero_revisione, descrizione_modifica, utente_id)
                                    VALUES (?, 'componente', ?, ?, ?, ?)");
            $stmt->execute([azienda_id(), $componenteId, $numRev, trim($_POST['descrizione_modifica']), $_SESSION['user_id']]);
            $pdo->prepare("UPDATE componenti SET revisione_corrente=? WHERE id=?")->execute([$numRev, $componenteId]);
            torna($componenteId, 'Nuova revisione registrata.');
            break;

        case 'disattiva_componente':
            require_admin();
            $pdo->prepare("UPDATE componenti SET attivo=0 WHERE id=?")->execute([$componenteId]);
            log_attivita($pdo, 'disattiva_componente', 'componenti', $componenteId);
            torna($componenteId, 'Componente disattivato: non comparirà più negli elenchi attivi, ma i suoi dati storici restano intatti.');
            break;

        case 'riattiva_componente':
            require_admin();
            $pdo->prepare("UPDATE componenti SET attivo=1 WHERE id=?")->execute([$componenteId]);
            log_attivita($pdo, 'riattiva_componente', 'componenti', $componenteId);
            torna($componenteId, 'Componente riattivato.');
            break;

        case 'elimina_componente':
            require_admin();
            $giacenza = giacenza_totale($pdo, $componenteId);
            if (abs($giacenza) > 0.0001) {
                torna($componenteId, 'Impossibile eliminare: la giacenza non è a zero (' . number_format($giacenza, 2, ',', '.') . ' unità presenti). Azzera prima la giacenza con un movimento di scarico o una rettifica.', 'danger');
            }
            try {
                $pdo->prepare("DELETE FROM componenti WHERE id=? AND azienda_id=?")->execute([$componenteId, azienda_id()]);
                log_attivita($pdo, 'elimina_componente', 'componenti', $componenteId);
                $_SESSION['flash_msg'] = 'Componente eliminato definitivamente.';
                $_SESSION['flash_type'] = 'success';
                header('Location: list.php');
                exit;
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                    torna($componenteId, 'Impossibile eliminare: il componente è ancora referenziato altrove (distinta base, lotti, movimenti storici o ordini di acquisto). Puoi disattivarlo per nasconderlo dagli elenchi mantenendo lo storico.', 'warning');
                }
                torna($componenteId, 'Errore database: ' . $e->getMessage(), 'danger');
            }
            break;

        case 'duplica_componente':
            $nuovoCodice = trim($_POST['nuovo_codice_interno'] ?? '');
            if ($nuovoCodice === '') { torna($componenteId, 'Specifica il codice interno per il nuovo componente.', 'danger'); }

            $stmtOrig = $pdo->prepare("SELECT * FROM componenti WHERE id = ? AND azienda_id = ?");
            $stmtOrig->execute([$componenteId, azienda_id()]);
            $orig = $stmtOrig->fetch();
            if (!$orig) { torna($componenteId, 'Componente originale non trovato.', 'danger'); }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO componenti (azienda_id, codice_interno, sigla, descrizione, tipo_componente_id, categoria_id, tecnologia_id, um_base_id,
                    immagine, prezzo_medio, valuta, scorta_minima, scorta_massima, punto_riordino, lead_time_giorni, specifiche, case_componente, data_inizio_validita, data_fine_validita, note, utente_creazione_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    azienda_id(), $nuovoCodice, $orig['sigla'], $orig['descrizione'], $orig['tipo_componente_id'], $orig['categoria_id'], $orig['tecnologia_id'], $orig['um_base_id'],
                    $orig['immagine'], $orig['prezzo_medio'], $orig['valuta'], $orig['scorta_minima'], $orig['scorta_massima'], $orig['punto_riordino'], $orig['lead_time_giorni'],
                    $orig['specifiche'], $orig['case_componente'], $orig['data_inizio_validita'], $orig['data_fine_validita'], $orig['note'], $_SESSION['user_id']
                ]);
                $nuovoId = $pdo->lastInsertId();

                // UM base come UM di default (stesso comportamento della creazione normale da form.php)
                $pdo->prepare("INSERT INTO componenti_unita_misura (componente_id, unita_misura_id, fattore_conversione, is_default) VALUES (?,?,1,1)")
                    ->execute([$nuovoId, $orig['um_base_id']]);

                // altre unità di misura aggiuntive (non quella di default, già inserita sopra)
                $umAgg = $pdo->prepare("SELECT unita_misura_id, fattore_conversione FROM componenti_unita_misura WHERE componente_id = ? AND is_default = 0");
                $umAgg->execute([$componenteId]);
                $insUm = $pdo->prepare("INSERT INTO componenti_unita_misura (componente_id, unita_misura_id, fattore_conversione, is_default) VALUES (?,?,?,0)");
                foreach ($umAgg->fetchAll() as $u) {
                    $insUm->execute([$nuovoId, $u['unita_misura_id'], $u['fattore_conversione']]);
                }

                // fornitori collegati (senza dati "live" da API, che vanno riaggiornati per il nuovo componente)
                $forn = $pdo->prepare("SELECT fornitore_id, codice_fornitore, produttore, prezzo, valuta, lead_time_giorni, moq, multiplo_ordine, shipping_cost, preferito, note FROM componenti_fornitori WHERE componente_id = ?");
                $forn->execute([$componenteId]);
                $insForn = $pdo->prepare("INSERT INTO componenti_fornitori (componente_id, fornitore_id, codice_fornitore, produttore, prezzo, valuta, lead_time_giorni, moq, multiplo_ordine, shipping_cost, preferito, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($forn->fetchAll() as $f) {
                    $insForn->execute([$nuovoId, $f['fornitore_id'], $f['codice_fornitore'], $f['produttore'], $f['prezzo'], $f['valuta'], $f['lead_time_giorni'], $f['moq'], $f['multiplo_ordine'], $f['shipping_cost'], $f['preferito'], $f['note']]);
                }

                log_attivita($pdo, 'duplica_componente', 'componenti', $nuovoId, "da componente {$orig['codice_interno']}");
                $pdo->commit();
                $_SESSION['flash_msg'] = "Componente duplicato come \"$nuovoCodice\". Giacenze partite da zero; distinta base, documenti e alternativi NON sono stati copiati: verifica/aggiorna i dati.";
                $_SESSION['flash_type'] = 'success';
                header('Location: view.php?id=' . $nuovoId);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Codice interno già esistente in questa azienda.' : 'Errore database: ' . $e->getMessage();
                torna($componenteId, $msg, 'danger');
            }
            break;

        case 'aggiungi_omologazione':
            $produttoreOm = trim($_POST['produttore'] ?? '');
            $codiceOm = trim($_POST['codice_produttore'] ?? '');
            if ($produttoreOm === '' || $codiceOm === '') { torna($componenteId, 'Produttore e codice sono obbligatori.', 'danger'); }
            try {
                $pdo->prepare("INSERT INTO omologazioni (azienda_id, componente_id, produttore, codice_produttore, note, utente_creazione_id) VALUES (?,?,?,?,?,?)")
                    ->execute([azienda_id(), $componenteId, $produttoreOm, $codiceOm, trim($_POST['note'] ?? ''), $_SESSION['user_id']]);
                log_attivita($pdo, 'aggiungi_omologazione', 'omologazioni', (int) $pdo->lastInsertId());
                torna($componenteId, 'Codice aggiunto (stato iniziale: non omologato).', 'success');
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Questo produttore/codice è già presente per questo componente.' : 'Errore database: ' . $e->getMessage();
                torna($componenteId, $msg, 'danger');
            }
            break;

        case 'elimina_omologazione':
            $pdo->prepare("DELETE FROM omologazioni WHERE id=? AND componente_id=? AND azienda_id=?")
                ->execute([(int) $_POST['riga_id'], $componenteId, azienda_id()]);
            torna($componenteId, 'Codice rimosso.', 'success');
            break;

        case 'richiedi_cambio_stato_omologazione':
            $omologazioneId = (int) $_POST['riga_id'];
            $nuovoStato = $_POST['nuovo_stato'] ?? '';
            $motivazione = trim($_POST['motivazione'] ?? '');
            if (!in_array($nuovoStato, ['non_omologato', 'omologato', 'accettato_in_deroga'], true)) {
                torna($componenteId, 'Stato richiesto non valido.', 'danger');
            }
            $stmt = $pdo->prepare("SELECT * FROM omologazioni WHERE id=? AND componente_id=? AND azienda_id=?");
            $stmt->execute([$omologazioneId, $componenteId, azienda_id()]);
            $om = $stmt->fetch();
            if (!$om) { torna($componenteId, 'Codice non trovato.', 'danger'); }
            if ($om['stato'] === $nuovoStato) { torna($componenteId, 'Il codice è già in questo stato.', 'warning'); }
            $stmt = $pdo->prepare("SELECT id FROM omologazioni_richieste WHERE omologazione_id=? AND stato_richiesta='in_attesa'");
            $stmt->execute([$omologazioneId]);
            if ($stmt->fetch()) { torna($componenteId, "C'è già una richiesta di cambio stato in attesa per questo codice.", 'warning'); }
            if ($motivazione === '') { torna($componenteId, 'Specifica una motivazione per la richiesta.', 'danger'); }

            $pdo->prepare("INSERT INTO omologazioni_richieste (omologazione_id, stato_precedente, stato_richiesto, motivazione, richiedente_id) VALUES (?,?,?,?,?)")
                ->execute([$omologazioneId, $om['stato'], $nuovoStato, $motivazione, $_SESSION['user_id']]);
            log_attivita($pdo, 'richiedi_cambio_stato_omologazione', 'omologazioni', $omologazioneId, "{$om['stato']} -> $nuovoStato");
            torna($componenteId, 'Richiesta di cambio stato inviata, in attesa di approvazione.', 'success');
            break;

        case 'approva_richiesta_omologazione':
            require_admin();
            $richiestaId = (int) $_POST['richiesta_id'];
            $stmt = $pdo->prepare("SELECT r.*, o.componente_id FROM omologazioni_richieste r JOIN omologazioni o ON o.id = r.omologazione_id
                                    WHERE r.id=? AND o.componente_id=? AND o.azienda_id=? AND r.stato_richiesta='in_attesa'");
            $stmt->execute([$richiestaId, $componenteId, azienda_id()]);
            $richiesta = $stmt->fetch();
            if (!$richiesta) { torna($componenteId, 'Richiesta non trovata o già decisa.', 'danger'); }

            $pdo->prepare("UPDATE omologazioni_richieste SET stato_richiesta='approvata', approvatore_id=?, data_decisione=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $richiestaId]);
            $pdo->prepare("UPDATE omologazioni SET stato=? WHERE id=?")->execute([$richiesta['stato_richiesto'], $richiesta['omologazione_id']]);
            log_attivita($pdo, 'approva_richiesta_omologazione', 'omologazioni', $richiesta['omologazione_id']);
            torna($componenteId, 'Richiesta approvata: stato aggiornato.', 'success');
            break;

        case 'rifiuta_richiesta_omologazione':
            require_admin();
            $richiestaId = (int) $_POST['richiesta_id'];
            $notaRifiuto = trim($_POST['nota_rifiuto'] ?? '');
            if ($notaRifiuto === '') { torna($componenteId, 'Specifica il motivo del rifiuto.', 'danger'); }
            $stmt = $pdo->prepare("SELECT r.id FROM omologazioni_richieste r JOIN omologazioni o ON o.id = r.omologazione_id
                                    WHERE r.id=? AND o.componente_id=? AND o.azienda_id=? AND r.stato_richiesta='in_attesa'");
            $stmt->execute([$richiestaId, $componenteId, azienda_id()]);
            if (!$stmt->fetch()) { torna($componenteId, 'Richiesta non trovata o già decisa.', 'danger'); }

            $pdo->prepare("UPDATE omologazioni_richieste SET stato_richiesta='rifiutata', approvatore_id=?, data_decisione=NOW(), note_decisione=? WHERE id=?")
                ->execute([$_SESSION['user_id'], $notaRifiuto, $richiestaId]);
            log_attivita($pdo, 'rifiuta_richiesta_omologazione', 'omologazioni', $richiestaId);
            torna($componenteId, 'Richiesta rifiutata.', 'success');
            break;

        case 'aggiorna_documenti_omologazione':
            $omologazioneId = (int) $_POST['riga_id'];
            $stmt = $pdo->prepare("SELECT id FROM omologazioni WHERE id=? AND componente_id=? AND azienda_id=?");
            $stmt->execute([$omologazioneId, $componenteId, azienda_id()]);
            if (!$stmt->fetch()) { torna($componenteId, 'Codice omologazione non trovato.', 'danger'); }

            $documentiScelti = array_map('intval', $_POST['documenti'] ?? []);
            // verifica che ogni documento scelto appartenga davvero a questo componente
            $stmtVerificaDoc = $pdo->prepare("SELECT id FROM componenti_documenti WHERE id=? AND componente_id=?");

            $pdo->prepare("DELETE FROM omologazioni_documenti WHERE omologazione_id=?")->execute([$omologazioneId]);
            $insDoc = $pdo->prepare("INSERT INTO omologazioni_documenti (omologazione_id, documento_id) VALUES (?,?)");
            foreach ($documentiScelti as $docId) {
                $stmtVerificaDoc->execute([$docId, $componenteId]);
                if ($stmtVerificaDoc->fetch()) { $insDoc->execute([$omologazioneId, $docId]); }
            }
            log_attivita($pdo, 'aggiorna_documenti_omologazione', 'omologazioni', $omologazioneId);
            torna($componenteId, 'Documenti collegati aggiornati.', 'success');
            break;

        default:
            torna($componenteId, 'Azione non riconosciuta.', 'danger');
    }
} catch (PDOException $e) {
    torna($componenteId, 'Errore database: ' . $e->getMessage(), 'danger');
}
