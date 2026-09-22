<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('bom');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: list.php'); exit; }
csrf_verify();

$componenteId = (int) $_POST['componente_id'];
$azione = $_POST['azione'] ?? '';

// Verifica che il componente appartenga all'azienda dell'utente collegato
$stmtVerifica = $pdo->prepare("SELECT id FROM componenti WHERE id = ? AND azienda_id = ?");
$stmtVerifica->execute([$componenteId, azienda_id()]);
if (!$stmtVerifica->fetch()) {
    http_response_code(403);
    die('Componente non trovato.');
}

function fine($componenteId, $bomId = null, $msg = null, $tipo = 'success') {
    global $pdo, $azione;
    if ($msg) { $_SESSION['flash_msg'] = $msg; $_SESSION['flash_type'] = $tipo; }
    if ($tipo === 'success' && $bomId && $azione !== 'crea_bom') {
        // qualunque operazione riuscita sulla BOM (righe, stato, validità...) conta come
        // modifica per il log "modificato da/il" (non applicabile dopo un'eliminazione
        // o alla creazione stessa, già coperta da "creato da")
        $pdo->prepare("UPDATE bom SET utente_modifica_id=?, data_modifica=NOW() WHERE id=? AND azienda_id=?")
            ->execute([$_SESSION['user_id'] ?? null, $bomId, azienda_id()]);
    }
    $url = 'editor.php?componente_id=' . $componenteId . ($bomId ? '&bom_id=' . $bomId : '');
    header('Location: ' . $url);
    exit;
}

try {
    switch ($azione) {

        case 'crea_bom':
            $stmt = $pdo->prepare("INSERT INTO bom (azienda_id, componente_padre_id, revisione, descrizione, data_inizio_validita, data_fine_validita, utente_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([azienda_id(), $componenteId, trim($_POST['revisione']), trim($_POST['descrizione']), $_POST['data_inizio_validita'] ?: null, $_POST['data_fine_validita'] ?: null, $_SESSION['user_id']]);
            $bomId = $pdo->lastInsertId();
            log_attivita($pdo, 'crea_bom', 'bom', $bomId);
            fine($componenteId, $bomId, 'Distinta base creata.');
            break;

        case 'modifica_validita':
            $bomId = (int) $_POST['bom_id'];
            $pdo->prepare("UPDATE bom SET data_inizio_validita=?, data_fine_validita=? WHERE id=? AND azienda_id=?")
                ->execute([$_POST['data_inizio_validita'] ?: null, $_POST['data_fine_validita'] ?: null, $bomId, azienda_id()]);
            log_attivita($pdo, 'modifica_validita_bom', 'bom', $bomId);
            fine($componenteId, $bomId, 'Validità aggiornata.');
            break;

        case 'aggiungi_riga':
            $bomId = (int) $_POST['bom_id'];
            // verifica che la bom sia in bozza e appartenga all'azienda corrente
            $stmt = $pdo->prepare("SELECT stato FROM bom WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$bomId, azienda_id()]);
            $rigaStato = $stmt->fetch();
            if (!$rigaStato || $rigaStato['stato'] !== 'bozza') { fine($componenteId, $bomId, 'Puoi modificare solo distinte in stato bozza.', 'danger'); }

            $figlioId = (int) $_POST['riga_componente_id'];
            $stmtFiglio = $pdo->prepare("SELECT id FROM componenti WHERE id = ? AND azienda_id = ?");
            $stmtFiglio->execute([$figlioId, azienda_id()]);
            if (!$stmtFiglio->fetch()) { fine($componenteId, $bomId, 'Componente non valido.', 'danger'); }
            // controllo anti-ricorsione: il figlio non può essere un antenato del padre nella catena BOM
            if (verifica_ciclo($pdo, $componenteId, $figlioId)) {
                fine($componenteId, $bomId, 'Operazione bloccata: creerebbe un riferimento circolare nella distinta base.', 'danger');
            }
            $stmt = $pdo->prepare("INSERT INTO bom_righe (bom_id, componente_id, quantita, unita_misura_id, designatore, ordinamento) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$bomId, $figlioId, num_or($_POST['quantita'] ?? null, 0), (int)$_POST['unita_misura_id'], trim($_POST['designatore']), (int) ($_POST['ordinamento'] ?? 0)]);
            fine($componenteId, $bomId, 'Riga aggiunta alla distinta base.');
            break;

        case 'elimina_riga':
            $bomId = (int) $_POST['bom_id'];
            $pdo->prepare("DELETE br FROM bom_righe br JOIN bom b ON b.id = br.bom_id
                           WHERE br.id = ? AND b.id = ? AND b.azienda_id = ? AND b.stato = 'bozza'")
                ->execute([(int)$_POST['riga_id'], $bomId, azienda_id()]);
            fine($componenteId, $bomId, 'Riga rimossa.');
            break;

        case 'modifica_riga':
            $bomId = (int) $_POST['bom_id'];
            $stmt = $pdo->prepare("SELECT stato FROM bom WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$bomId, azienda_id()]);
            $rigaStato = $stmt->fetch();
            if (!$rigaStato || $rigaStato['stato'] !== 'bozza') { fine($componenteId, $bomId, 'Puoi modificare solo distinte in stato bozza.', 'danger'); }

            $stmt = $pdo->prepare("UPDATE bom_righe br JOIN bom b ON b.id = br.bom_id
                                    SET br.quantita=?, br.unita_misura_id=?, br.designatore=?, br.ordinamento=?
                                    WHERE br.id=? AND br.bom_id=? AND b.azienda_id=?");
            $stmt->execute([
                num_or($_POST['quantita'] ?? null, 0), (int)$_POST['unita_misura_id'], trim($_POST['designatore']), (int) ($_POST['ordinamento'] ?? 0),
                (int)$_POST['riga_id'], $bomId, azienda_id()
            ]);
            fine($componenteId, $bomId, 'Riga aggiornata.');
            break;

        case 'cambia_stato':
            require_admin();
            $bomId = (int) $_POST['bom_id'];
            $nuovoStato = $_POST['stato'];
            if ($nuovoStato === 'attiva') {
                // disattiva eventuali altre revisioni attive dello stesso componente padre
                $pdo->prepare("UPDATE bom SET stato='obsoleta' WHERE componente_padre_id=? AND azienda_id=? AND stato='attiva'")->execute([$componenteId, azienda_id()]);
            }
            $pdo->prepare("UPDATE bom SET stato = ? WHERE id = ? AND azienda_id = ?")->execute([$nuovoStato, $bomId, azienda_id()]);
            log_attivita($pdo, 'cambia_stato_bom', 'bom', $bomId, $nuovoStato);
            fine($componenteId, $bomId, 'Stato aggiornato.');
            break;

        case 'duplica_revisione':
            $bomOrigineId = (int) $_POST['bom_id_origine'];
            $nuovaRev = trim($_POST['nuova_revisione']);
            $stmt = $pdo->prepare("SELECT * FROM bom WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$bomOrigineId, azienda_id()]);
            $origine = $stmt->fetch();
            if (!$origine) { fine($componenteId, null, 'BOM di origine non trovata.', 'danger'); }

            $stmt = $pdo->prepare("INSERT INTO bom (azienda_id, componente_padre_id, revisione, stato, descrizione, utente_id) VALUES (?,?,?, 'bozza', ?, ?)");
            $stmt->execute([azienda_id(), $componenteId, $nuovaRev, $origine['descrizione'], $_SESSION['user_id']]);
            $nuovaBomId = $pdo->lastInsertId();

            $righe = $pdo->prepare("SELECT * FROM bom_righe WHERE bom_id = ?");
            $righe->execute([$bomOrigineId]);
            $insert = $pdo->prepare("INSERT INTO bom_righe (bom_id, componente_id, quantita, unita_misura_id, designatore, note, ordinamento) VALUES (?,?,?,?,?,?,?)");
            foreach ($righe->fetchAll() as $r) {
                $insert->execute([$nuovaBomId, $r['componente_id'], $r['quantita'], $r['unita_misura_id'], $r['designatore'], $r['note'], $r['ordinamento']]);
            }
            $pdo->prepare("UPDATE componenti SET revisione_corrente = ? WHERE id = ? AND azienda_id = ?")->execute([$nuovaRev, $componenteId, azienda_id()]);
            log_attivita($pdo, 'duplica_bom', 'bom', $nuovaBomId, "da revisione {$origine['revisione']}");
            fine($componenteId, $nuovaBomId, "Nuova revisione $nuovaRev creata come bozza.");
            break;

        case 'disattiva_bom':
            require_admin();
            $bomId = (int) $_POST['bom_id'];
            $pdo->prepare("UPDATE bom SET attivo = 0 WHERE id = ? AND azienda_id = ?")->execute([$bomId, azienda_id()]);
            log_attivita($pdo, 'disattiva_bom', 'bom', $bomId);
            fine($componenteId, $bomId, 'Distinta base disattivata: resta consultabile ma non selezionabile per nuove esplosioni.', 'success');
            break;

        case 'riattiva_bom':
            require_admin();
            $bomId = (int) $_POST['bom_id'];
            $pdo->prepare("UPDATE bom SET attivo = 1 WHERE id = ? AND azienda_id = ?")->execute([$bomId, azienda_id()]);
            log_attivita($pdo, 'riattiva_bom', 'bom', $bomId);
            fine($componenteId, $bomId, 'Distinta base riattivata.', 'success');
            break;

        case 'elimina_bom':
            require_admin();
            $bomId = (int) $_POST['bom_id'];
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM bom_righe br JOIN bom b ON b.id=br.bom_id WHERE br.bom_id = ? AND b.azienda_id = ?");
            $stmt->execute([$bomId, azienda_id()]);
            if ($stmt->fetch()['c'] > 0) {
                fine($componenteId, $bomId, 'Impossibile eliminare: la distinta base contiene ancora delle righe. Rimuovile tutte prima di eliminare la BOM.', 'danger');
            }
            $pdo->prepare("DELETE FROM bom WHERE id = ? AND azienda_id = ?")->execute([$bomId, azienda_id()]);
            log_attivita($pdo, 'elimina_bom', 'bom', $bomId);
            fine($componenteId, null, 'Distinta base eliminata.', 'success');
            break;

        default:
            fine($componenteId, null, 'Azione non riconosciuta.', 'danger');
    }
} catch (PDOException $e) {
    fine($componenteId, $_POST['bom_id'] ?? null, 'Errore database: ' . $e->getMessage(), 'danger');
}

/**
 * verifica_ciclo() è ora definita in includes/functions.php (condivisa con eco/save.php,
 * che ne ha bisogno per lo stesso controllo quando applica automaticamente le modifiche).
 */
