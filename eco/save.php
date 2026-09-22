<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_azienda();
require_sezione('eco');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: list.php'); exit; }
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
$azione = $_POST['azione'] ?? '';
$nota = trim($_POST['nota'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM eco WHERE id = ? AND azienda_id = ?");
$stmt->execute([$id, azienda_id()]);
$eco = $stmt->fetch();
if (!$eco) { die('ECO non trovato.'); }

function transizione(PDO $pdo, array $eco, string $nuovoStato, ?string $nota, array $campiExtra = []): void {
    $set = ['stato = ?'];
    $valori = [$nuovoStato];
    foreach ($campiExtra as $campo => $valore) { $set[] = "$campo = ?"; $valori[] = $valore; }
    $valori[] = $eco['id'];
    $valori[] = azienda_id();
    $pdo->prepare("UPDATE eco SET " . implode(', ', $set) . " WHERE id = ? AND azienda_id = ?")->execute($valori);
    $pdo->prepare("INSERT INTO eco_storico (eco_id, stato_precedente, stato_nuovo, utente_id, nota) VALUES (?,?,?,?,?)")
        ->execute([$eco['id'], $eco['stato'], $nuovoStato, $_SESSION['user_id'], $nota]);
    log_attivita($pdo, 'cambia_stato_eco', 'eco', $eco['id'], "{$eco['stato']} -> $nuovoStato");
}

/**
 * Applica automaticamente le modifiche richieste da un ECO di tipo "bom" alla distinta
 * base collegata. Se la BOM è già attiva (o obsoleta), crea prima una nuova revisione in
 * bozza (copiando le righe attuali) e la attiva al termine, così una BOM già rilasciata
 * non viene mai alterata silenziosamente. Ritorna l'id della BOM su cui sono state
 * applicate le modifiche. Solleva un'eccezione se qualcosa non va (nessuna modifica
 * specificata, componente non più valido, riferimento circolare...), senza applicare
 * nulla di parziale.
 */
function applica_modifiche_bom_eco(PDO $pdo, array $eco): int {
    $stmt = $pdo->prepare("SELECT * FROM bom WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$eco['oggetto_id'], azienda_id()]);
    $bomOriginale = $stmt->fetch();
    if (!$bomOriginale) { throw new Exception('La distinta base collegata a questo ECO non è più disponibile.'); }

    $stmt = $pdo->prepare("SELECT * FROM eco_modifiche_bom WHERE eco_id = ? ORDER BY ordinamento, id");
    $stmt->execute([$eco['id']]);
    $modifiche = $stmt->fetchAll();
    if (!$modifiche) { throw new Exception('Nessuna modifica alla distinta base specificata per questo ECO: torna in modifica e aggiungine almeno una.'); }

    $nuovaRevisione = null;
    if ($bomOriginale['stato'] === 'bozza') {
        $bomTargetId = $bomOriginale['id'];
    } else {
        // la BOM originale è già attiva/obsoleta: crea una nuova revisione in bozza,
        // copiando le righe attuali, invece di alterarla direttamente. Usa la revisione
        // proposta e confermata dall'utente in fase di creazione dell'ECO; se per qualche
        // motivo è vuota, ricade sul vecchio schema automatico come rete di sicurezza.
        $nuovaRevisione = trim((string) ($eco['revisione_proposta'] ?? ''));
        if ($nuovaRevisione === '') {
            $stmtPrima = $pdo->prepare("SELECT revisione FROM bom WHERE componente_padre_id = ? AND azienda_id = ? ORDER BY id ASC LIMIT 1");
            $stmtPrima->execute([$bomOriginale['componente_padre_id'], azienda_id()]);
            $revisioneBase = $stmtPrima->fetch()['revisione'] ?? $bomOriginale['revisione'];
            $nuovaRevisione = $revisioneBase . '-' . $eco['numero_eco'];
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO bom (azienda_id, componente_padre_id, revisione, stato, descrizione, utente_id) VALUES (?,?,?,'bozza',?,?)");
            $stmt->execute([azienda_id(), $bomOriginale['componente_padre_id'], $nuovaRevisione, $bomOriginale['descrizione'], $_SESSION['user_id']]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new Exception('Esiste già una revisione "' . $nuovaRevisione . '" per questa distinta base. Torna in modifica sull\'ECO e cambia il campo "Nuova revisione proposta".');
            }
            throw $e;
        }
        $bomTargetId = (int) $pdo->lastInsertId();

        $righeOrig = $pdo->prepare("SELECT * FROM bom_righe WHERE bom_id = ?");
        $righeOrig->execute([$bomOriginale['id']]);
        $insRiga = $pdo->prepare("INSERT INTO bom_righe (bom_id, componente_id, quantita, unita_misura_id, designatore, note, ordinamento) VALUES (?,?,?,?,?,?,?)");
        foreach ($righeOrig->fetchAll() as $r) {
            $insRiga->execute([$bomTargetId, $r['componente_id'], $r['quantita'], $r['unita_misura_id'], $r['designatore'], $r['note'], $r['ordinamento']]);
        }
    }

    foreach ($modifiche as $m) {
        switch ($m['tipo_modifica']) {
            case 'aggiungi':
                if (!$m['componente_nuovo_id']) { throw new Exception('Riga "Aggiungi" senza componente specificato.'); }
                if (verifica_ciclo($pdo, $bomOriginale['componente_padre_id'], (int) $m['componente_nuovo_id'])) {
                    throw new Exception('La modifica "Aggiungi ' . $m['componente_nuovo_id'] . '" creerebbe un riferimento circolare nella distinta base: operazione annullata.');
                }
                $pdo->prepare("INSERT INTO bom_righe (bom_id, componente_id, quantita, unita_misura_id, designatore, note) VALUES (?,?,?,?,?,?)")
                    ->execute([$bomTargetId, $m['componente_nuovo_id'], $m['quantita'] ?: 1, $m['unita_misura_id'], $m['designatore'], $m['note']]);
                break;

            case 'rimuovi':
                if (!$m['componente_id']) { throw new Exception('Riga "Rimuovi" senza componente specificato.'); }
                $pdo->prepare("DELETE FROM bom_righe WHERE bom_id = ? AND componente_id = ?")->execute([$bomTargetId, $m['componente_id']]);
                break;

            case 'sostituisci':
                if (!$m['componente_id'] || !$m['componente_nuovo_id']) { throw new Exception('Riga "Sostituisci" incompleta.'); }
                if (verifica_ciclo($pdo, $bomOriginale['componente_padre_id'], (int) $m['componente_nuovo_id'])) {
                    throw new Exception('La sostituzione con il componente ' . $m['componente_nuovo_id'] . ' creerebbe un riferimento circolare nella distinta base: operazione annullata.');
                }
                $pdo->prepare("UPDATE bom_righe SET componente_id = ?, quantita = COALESCE(?, quantita), unita_misura_id = COALESCE(?, unita_misura_id), designatore = ? WHERE bom_id = ? AND componente_id = ?")
                    ->execute([$m['componente_nuovo_id'], $m['quantita'], $m['unita_misura_id'], $m['designatore'], $bomTargetId, $m['componente_id']]);
                break;

            case 'modifica_quantita':
                if (!$m['componente_id']) { throw new Exception('Riga "Modifica quantità / Designatore" incompleta: manca il componente esistente.'); }
                $pdo->prepare("UPDATE bom_righe SET quantita = COALESCE(?, quantita), unita_misura_id = COALESCE(?, unita_misura_id), designatore = ? WHERE bom_id = ? AND componente_id = ?")
                    ->execute([$m['quantita'], $m['unita_misura_id'], $m['designatore'], $bomTargetId, $m['componente_id']]);
                break;
        }
    }

    if ($nuovaRevisione !== null) {
        if ($bomOriginale['stato'] === 'attiva') {
            $pdo->prepare("UPDATE bom SET stato = 'obsoleta' WHERE id = ?")->execute([$bomOriginale['id']]);
        }
        $pdo->prepare("UPDATE bom SET stato = 'attiva' WHERE id = ?")->execute([$bomTargetId]);
        $pdo->prepare("UPDATE componenti SET revisione_corrente = ? WHERE id = ? AND azienda_id = ?")
            ->execute([$nuovaRevisione, $bomOriginale['componente_padre_id'], azienda_id()]);
    }

    return $bomTargetId;
}

switch ($azione) {
    case 'invia_revisione':
        if ($eco['stato'] === 'bozza') {
            transizione($pdo, $eco, 'in_revisione', $nota ?: null);
            $_SESSION['flash_msg'] = 'ECO inviato in revisione.'; $_SESSION['flash_type'] = 'success';
        }
        break;

    case 'approva':
        require_admin();
        if ($eco['stato'] === 'in_revisione') {
            if ($eco['tipo_oggetto'] === 'bom') {
                // implementazione automatica: tutto o niente, in transazione
                $pdo->beginTransaction();
                try {
                    $bomRisultanteId = applica_modifiche_bom_eco($pdo, $eco);
                    transizione($pdo, $eco, 'approvato', $nota ?: null, ['approvatore_id' => $_SESSION['user_id'], 'data_decisione' => date('Y-m-d H:i:s'), 'note_decisione' => $nota ?: null, 'bom_risultante_id' => $bomRisultanteId]);
                    // ricarico lo stato aggiornato per la transizione successiva (transizione() legge $eco['stato'] come "precedente")
                    $ecoAggiornato = $eco; $ecoAggiornato['stato'] = 'approvato';
                    transizione($pdo, $ecoAggiornato, 'implementato', 'Implementato automaticamente all\'approvazione.', ['data_implementazione' => date('Y-m-d H:i:s')]);
                    $pdo->commit();
                    $_SESSION['flash_msg'] = 'ECO approvato: le modifiche sono state applicate automaticamente alla distinta base.';
                    $_SESSION['flash_type'] = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['flash_msg'] = 'Approvazione annullata: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'danger';
                }
            } else {
                transizione($pdo, $eco, 'approvato', $nota ?: null, ['approvatore_id' => $_SESSION['user_id'], 'data_decisione' => date('Y-m-d H:i:s'), 'note_decisione' => $nota ?: null]);
                $_SESSION['flash_msg'] = 'ECO approvato.'; $_SESSION['flash_type'] = 'success';
            }
        }
        break;

    case 'rifiuta':
        require_admin();
        if ($eco['stato'] === 'in_revisione') {
            if ($nota === '') {
                $_SESSION['flash_msg'] = 'Specifica il motivo del rifiuto.'; $_SESSION['flash_type'] = 'danger';
            } else {
                transizione($pdo, $eco, 'rifiutato', $nota, ['approvatore_id' => $_SESSION['user_id'], 'data_decisione' => date('Y-m-d H:i:s'), 'note_decisione' => $nota]);
                $_SESSION['flash_msg'] = 'ECO rifiutato.'; $_SESSION['flash_type'] = 'warning';
            }
        }
        break;

    case 'riporta_bozza':
        if (in_array($eco['stato'], ['in_revisione', 'rifiutato'], true)) {
            $puoRiportare = is_admin() || $eco['stato'] === 'rifiutato';
            if ($puoRiportare) {
                transizione($pdo, $eco, 'bozza', $nota ?: null);
                $_SESSION['flash_msg'] = 'ECO riportato in bozza.'; $_SESSION['flash_type'] = 'success';
            }
        }
        break;

    case 'implementa':
        if ($eco['stato'] === 'approvato') {
            transizione($pdo, $eco, 'implementato', $nota ?: null, ['data_implementazione' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_msg'] = 'ECO segnato come implementato.'; $_SESSION['flash_type'] = 'success';
        }
        break;

    case 'annulla':
        if (!in_array($eco['stato'], ['implementato', 'annullato'], true)) {
            transizione($pdo, $eco, 'annullato', $nota ?: null);
            $_SESSION['flash_msg'] = 'ECO annullato.'; $_SESSION['flash_type'] = 'success';
        }
        break;
}

header('Location: view.php?id=' . $id);
