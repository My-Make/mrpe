<?php
$titolo_pagina = 'Nuovo ECO';
require_once __DIR__ . '/../includes/header.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$eco = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM eco WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, azienda_id()]);
    $eco = $stmt->fetch();
    if (!$eco) { die('ECO non trovato.'); }
    if ($eco['stato'] !== 'bozza') { die('Questo ECO non è più in bozza: non è modificabile. Torna alla scheda per vedere lo stato attuale.'); }
}

// numero dell'ECO che stiamo effettivamente creando/modificando: se l'ECO esiste già, il suo
// numero definitivo; se è nuovo, un'anteprima (lo stesso numero che verrà assegnato al primo
// salvataggio, perché non viene incrementato nulla nel frattempo) - usato per comporre la
// proposta di revisione BOM così riflette sempre l'ECO corrente, mai uno precedente
$numeroEcoAttuale = $eco['numero_eco'] ?? genera_numero_eco($pdo);

// preselezione oggetto da query string (link "Crea ECO" da scheda componente/BOM)
$tipoOggettoPre = $_GET['tipo_oggetto'] ?? ($eco['tipo_oggetto'] ?? 'componente');
$oggettoIdPre = (int) ($_GET['oggetto_id'] ?? ($eco['oggetto_id'] ?? 0));
$oggettoPreDescrizione = '';
$revisioneSuggeritaPre = '';
if ($oggettoIdPre) {
    if ($tipoOggettoPre === 'bom') {
        $stmt = $pdo->prepare("SELECT b.id, b.componente_padre_id, c.codice_interno, c.descrizione, b.revisione FROM bom b JOIN componenti c ON c.id=b.componente_padre_id WHERE b.id=? AND b.azienda_id=?");
        $stmt->execute([$oggettoIdPre, azienda_id()]);
        $r = $stmt->fetch();
        if ($r) {
            $oggettoPreDescrizione = $r['codice_interno'] . ' - ' . $r['descrizione'] . ' (rev. ' . $r['revisione'] . ')';
            // revisione "base" stabile: la primissima mai creata per questo componente padre,
            // non quella attuale (che potrebbe già contenere il riferimento a un ECO precedente)
            $stmtPrima = $pdo->prepare("SELECT revisione FROM bom WHERE componente_padre_id = ? AND azienda_id = ? ORDER BY id ASC LIMIT 1");
            $stmtPrima->execute([$r['componente_padre_id'], azienda_id()]);
            $revisioneBasePre = $stmtPrima->fetch()['revisione'] ?? $r['revisione'];
            $revisioneSuggeritaPre = $revisioneBasePre . '-' . $numeroEcoAttuale;
        }
    } else {
        $stmt = $pdo->prepare("SELECT codice_interno, descrizione FROM componenti WHERE id=? AND azienda_id=?");
        $stmt->execute([$oggettoIdPre, azienda_id()]);
        $r = $stmt->fetch();
        if ($r) { $oggettoPreDescrizione = $r['codice_interno'] . ' - ' . $r['descrizione']; }
    }
}
// proposta di default per la nuova revisione: quella già salvata sull'ECO se esiste,
// altrimenti la proposta pulita calcolata sopra (comunque modificabile dall'utente)
$revisioneProposta = $eco['revisione_proposta'] ?? $revisioneSuggeritaPre;

$unitaMisura = $pdo->prepare("SELECT * FROM unita_misura WHERE azienda_id = ? ORDER BY descrizione");
$unitaMisura->execute([azienda_id()]); $unitaMisura = $unitaMisura->fetchAll();
$umDefaultId = null; foreach ($unitaMisura as $u) { if ($u['is_predefinita']) { $umDefaultId = $u['id']; break; } }

$modificheEsistenti = [];
if ($eco) {
    $stmt = $pdo->prepare("SELECT m.*, ce.codice_interno ce_codice, ce.descrizione ce_descrizione,
                            cn.codice_interno cn_codice, cn.descrizione cn_descrizione, um.codice um_codice
                            FROM eco_modifiche_bom m
                            LEFT JOIN componenti ce ON ce.id = m.componente_id
                            LEFT JOIN componenti cn ON cn.id = m.componente_nuovo_id
                            LEFT JOIN unita_misura um ON um.id = m.unita_misura_id
                            WHERE m.eco_id = ? ORDER BY m.ordinamento, m.id");
    $stmt->execute([$eco['id']]);
    $modificheEsistenti = $stmt->fetchAll();
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $titolo = trim($_POST['titolo']);
    $descrizione = trim($_POST['descrizione']);
    $motivazione = trim($_POST['motivazione']);
    $tipoOggetto = $_POST['tipo_oggetto'] === 'bom' ? 'bom' : 'componente';
    $oggettoId = (int) $_POST['oggetto_id'];
    $priorita = in_array($_POST['priorita'] ?? '', ['bassa','media','alta','urgente'], true) ? $_POST['priorita'] : 'media';
    $impattoCosti = trim($_POST['impatto_costi'] ?? '');
    $impattoDisponibilita = trim($_POST['impatto_disponibilita'] ?? '');
    $revisioneProposta = $tipoOggetto === 'bom' ? trim($_POST['revisione_proposta'] ?? '') : null;

    // verifica che l'oggetto scelto (componente o bom) appartenga all'azienda corrente
    $oggettoValido = false;
    if ($tipoOggetto === 'componente') {
        $stmtV = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
        $stmtV->execute([$oggettoId, azienda_id()]);
        $oggettoValido = (bool) $stmtV->fetch();
    } else {
        $stmtV = $pdo->prepare("SELECT id FROM bom WHERE id=? AND azienda_id=?");
        $stmtV->execute([$oggettoId, azienda_id()]);
        $oggettoValido = (bool) $stmtV->fetch();
    }

    if ($titolo === '' || !$oggettoValido) {
        $errore = 'Titolo obbligatorio e componente/BOM di riferimento non valido.';
    } else {
        if ($eco) {
            $pdo->prepare("UPDATE eco SET titolo=?, descrizione=?, motivazione=?, tipo_oggetto=?, oggetto_id=?, priorita=?, impatto_costi=?, impatto_disponibilita=?, revisione_proposta=? WHERE id=? AND azienda_id=?")
                ->execute([$titolo, $descrizione, $motivazione, $tipoOggetto, $oggettoId, $priorita, $impattoCosti, $impattoDisponibilita, $revisioneProposta, $eco['id'], azienda_id()]);
            log_attivita($pdo, 'modifica_eco', 'eco', $eco['id']);
            $idFinale = $eco['id'];
        } else {
            $numeroEco = $numeroEcoAttuale;
            $stmt = $pdo->prepare("INSERT INTO eco (azienda_id, numero_eco, titolo, descrizione, motivazione, tipo_oggetto, oggetto_id, priorita, impatto_costi, impatto_disponibilita, revisione_proposta, richiedente_id)
                                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([azienda_id(), $numeroEco, $titolo, $descrizione, $motivazione, $tipoOggetto, $oggettoId, $priorita, $impattoCosti, $impattoDisponibilita, $revisioneProposta, $_SESSION['user_id']]);
            $idFinale = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO eco_storico (eco_id, stato_precedente, stato_nuovo, utente_id, nota) VALUES (?, NULL, 'bozza', ?, 'ECO creato')")
                ->execute([$idFinale, $_SESSION['user_id']]);
            log_attivita($pdo, 'crea_eco', 'eco', $idFinale, $numeroEco);
        }

        // righe di modifica BOM (solo per ECO di tipo "bom"): sostituisce sempre l'intero
        // elenco, così la modifica di un ECO in bozza è semplice da gestire
        $pdo->prepare("DELETE FROM eco_modifiche_bom WHERE eco_id = ?")->execute([$idFinale]);
        if ($tipoOggetto === 'bom') {
            $tipiModifica = $_POST['mod_tipo'] ?? [];
            $componenteId = $_POST['mod_componente_id'] ?? [];
            $componenteNuovoId = $_POST['mod_componente_nuovo_id'] ?? [];
            $quantitaMod = $_POST['mod_quantita'] ?? [];
            $umMod = $_POST['mod_um'] ?? [];
            $designatoreMod = $_POST['mod_designatore'] ?? [];

            $insertMod = $pdo->prepare("INSERT INTO eco_modifiche_bom (eco_id, tipo_modifica, componente_id, componente_nuovo_id, quantita, unita_misura_id, designatore, ordinamento) VALUES (?,?,?,?,?,?,?,?)");
            $stmtVerificaComp = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
            $ordinamento = 0;
            foreach ($tipiModifica as $i => $tipo) {
                if (!in_array($tipo, ['aggiungi','rimuovi','sostituisci','modifica_quantita'], true)) { continue; }
                $compId = (int) ($componenteId[$i] ?? 0) ?: null;
                $compNuovoId = (int) ($componenteNuovoId[$i] ?? 0) ?: null;
                if ($compId) { $stmtVerificaComp->execute([$compId, azienda_id()]); if (!$stmtVerificaComp->fetch()) { $compId = null; } }
                if ($compNuovoId) { $stmtVerificaComp->execute([$compNuovoId, azienda_id()]); if (!$stmtVerificaComp->fetch()) { $compNuovoId = null; } }
                // scarta righe incomplete in base al tipo (evita righe vuote lasciate dall'utente)
                if ($tipo === 'aggiungi' && !$compNuovoId) { continue; }
                if (in_array($tipo, ['rimuovi','sostituisci','modifica_quantita'], true) && !$compId) { continue; }
                if ($tipo === 'sostituisci' && !$compNuovoId) { continue; }
                $insertMod->execute([
                    $idFinale, $tipo, $compId, $compNuovoId,
                    num_or($quantitaMod[$i] ?? null, null), (int) ($umMod[$i] ?? 0) ?: null,
                    trim($designatoreMod[$i] ?? ''), $ordinamento++
                ]);
            }
        }
        header('Location: view.php?id=' . $idFinale); exit;
    }
}
?>
<h4><i class="bi bi-pencil-square"></i> <?= $eco ? 'Modifica ECO ' . h($eco['numero_eco']) : 'Nuovo ECO' ?></h4>
<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<form method="post" class="card p-4 mt-3">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label">Titolo *</label><input type="text" name="titolo" class="form-control" required value="<?= h($eco['titolo'] ?? '') ?>"></div>
    <div class="col-md-4">
      <label class="form-label">Priorità</label>
      <?php $prioritaAttuale = $eco['priorita'] ?? 'media'; ?>
      <select name="priorita" class="form-select">
        <?php foreach (['bassa'=>'Bassa','media'=>'Media','alta'=>'Alta','urgente'=>'Urgente'] as $val=>$lbl): ?>
        <option value="<?= $val ?>" <?= $prioritaAttuale===$val?'selected':'' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Oggetto della modifica</label>
      <select name="tipo_oggetto" id="tipoOggettoSelect" class="form-select">
        <option value="componente" <?= $tipoOggettoPre==='componente'?'selected':'' ?>>Componente</option>
        <option value="bom" <?= $tipoOggettoPre==='bom'?'selected':'' ?>>Distinta base (BOM)</option>
      </select>
    </div>
    <div class="col-md-9">
      <label class="form-label">&nbsp;</label>
      <div id="oggettoContainer"></div>
      <div class="form-text">Per una distinta base, cerca per codice/descrizione del componente padre.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Descrizione della modifica proposta</label>
      <textarea name="descrizione" class="form-control" rows="4"><?= h($eco['descrizione'] ?? '') ?></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">Motivazione</label>
      <textarea name="motivazione" class="form-control" rows="4" placeholder="Perché si richiede questa modifica (es. componente EOL, difetto, riduzione costi, richiesta cliente...)"><?= h($eco['motivazione'] ?? '') ?></textarea>
    </div>

    <div class="col-md-6">
      <label class="form-label">Impatto sui costi</label>
      <textarea name="impatto_costi" class="form-control" rows="2"><?= h($eco['impatto_costi'] ?? '') ?></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">Impatto sulla disponibilità/produzione</label>
      <textarea name="impatto_disponibilita" class="form-control" rows="2"><?= h($eco['impatto_disponibilita'] ?? '') ?></textarea>
    </div>
  </div>

  <div id="sezioneModificheBom" class="mt-4" style="display:none;">
    <hr>
    <h6 class="text-primary">Modifiche da applicare automaticamente all'approvazione</h6>
    <p class="small text-muted">Se la BOM collegata è già attiva, verrà creata automaticamente una nuova revisione con queste modifiche applicate; se è ancora in bozza, verrà modificata direttamente.</p>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Nuova revisione proposta</label>
        <input type="text" name="revisione_proposta" id="revisioneProposta" class="form-control" value="<?= h($revisioneProposta) ?>" placeholder="es. A-ECO">
        <div class="form-text">Proposta automaticamente in base alla revisione attuale della BOM; modificabile. Usata solo se la BOM collegata risulterà già attiva al momento dell'approvazione.</div>
      </div>
    </div>

    <div id="corpoModifiche"></div>
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="aggiungiRigaModifica()"><i class="bi bi-plus-lg"></i> Aggiungi riga di modifica</button>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> Salva come bozza</button>
    <a href="<?= $eco ? 'view.php?id=' . $eco['id'] : 'list.php' ?>" class="btn btn-outline-secondary">Annulla</a>
  </div>
</form>

<script>
const numeroEcoCorrente = <?= json_encode($numeroEcoAttuale) ?>;
let selettoreOggetto;

function creaSelettoreOggetto(tipo, idPreselezionato, descrizionePreselezionata) {
  document.getElementById('oggettoContainer').innerHTML = '';
  if (tipo === 'bom') {
    selettoreOggetto = creaSelettoreComponente(document.getElementById('oggettoContainer'), {
      nomeCampo: '__componente_per_bom',
      soloConBom: true,
      placeholder: 'Cerca componente padre della distinta base...',
      onSelezione: function(c) {
        // recupera l'id (e la revisione attuale) della BOM attiva/più recente di questo componente
        fetch(window.BASE_URL_APP + 'bom/bom_id_ajax.php?componente_id=' + c.id)
          .then(r => r.json())
          .then(dati => {
            document.getElementById('oggettoIdInput').value = dati.bom_id || '';
            if (!dati.bom_id) { alert('Questo componente non ha ancora una distinta base creata.'); }
            const campoRevisione = document.getElementById('revisioneProposta');
            if (campoRevisione && dati.revisione_base) { campoRevisione.value = dati.revisione_base + '-' + numeroEcoCorrente; }
          });
      }
    });
  } else {
    selettoreOggetto = creaSelettoreComponente(document.getElementById('oggettoContainer'), {
      nomeCampo: '__componente_diretto',
      placeholder: 'Cerca componente...',
      onSelezione: function(c) { document.getElementById('oggettoIdInput').value = c.id; }
    });
  }
  // campo reale inviato al server (i widget sopra sono solo di ricerca/UX)
  const inputReale = document.createElement('input');
  inputReale.type = 'hidden';
  inputReale.name = 'oggetto_id';
  inputReale.id = 'oggettoIdInput';
  inputReale.value = idPreselezionato || '';
  document.getElementById('oggettoContainer').appendChild(inputReale);

  if (idPreselezionato && descrizionePreselezionata) {
    selettoreOggetto.querySelector('.sc-testo').value = descrizionePreselezionata;
  }
}

document.getElementById('tipoOggettoSelect').addEventListener('change', function() {
  creaSelettoreOggetto(this.value, null, '');
  document.getElementById('sezioneModificheBom').style.display = this.value === 'bom' ? 'block' : 'none';
});
creaSelettoreOggetto(<?= json_encode($tipoOggettoPre) ?>, <?= json_encode($oggettoIdPre ?: null) ?>, <?= json_encode($oggettoPreDescrizione) ?>);
document.getElementById('sezioneModificheBom').style.display = <?= json_encode($tipoOggettoPre) ?> === 'bom' ? 'block' : 'none';

// ------------------------------------------------------------
// Builder modifiche BOM: ogni riga è una card i cui campi ed etichette
// cambiano in base al tipo di modifica scelto.
// ------------------------------------------------------------
const unitaMisuraEco = <?= json_encode($unitaMisura) ?>;
const umDefaultIdEco = <?= json_encode($umDefaultId) ?>;
const modificheEsistentiEco = <?= json_encode($modificheEsistenti) ?>;
let contatoreModifiche = 0;

const OPZIONI_TIPO = [
  { valore: 'aggiungi', etichetta: 'Aggiungi componente' },
  { valore: 'rimuovi', etichetta: 'Rimuovi componente' },
  { valore: 'sostituisci', etichetta: 'Sostituisci componente' },
  { valore: 'modifica_quantita', etichetta: 'Modifica quantità / Designatore' },
];

// campi (e relative etichette) da mostrare per ciascun tipo di modifica, nell'ordine richiesto
const CAMPI_PER_TIPO = {
  aggiungi: [
    { campo: 'nuovo', etichetta: 'Nuovo componente', larghezza: 'col-md-4' },
    { campo: 'quantita', etichetta: 'Quantità', larghezza: 'col-md-2' },
    { campo: 'um', etichetta: 'UM', larghezza: 'col-md-2' },
    { campo: 'designatore', etichetta: 'Designatore', larghezza: 'col-md-2' },
  ],
  rimuovi: [
    { campo: 'esistente', etichetta: 'Componente esistente', larghezza: 'col-md-6' },
  ],
  sostituisci: [
    { campo: 'esistente', etichetta: 'Componente esistente', larghezza: 'col-md-3' },
    { campo: 'nuovo', etichetta: 'Sostituto', larghezza: 'col-md-3' },
    { campo: 'quantita', etichetta: 'Quantità', larghezza: 'col-md-2' },
    { campo: 'um', etichetta: 'UM', larghezza: 'col-md-2' },
    { campo: 'designatore', etichetta: 'Designatore', larghezza: 'col-md-2' },
  ],
  modifica_quantita: [
    { campo: 'esistente', etichetta: 'Componente esistente', larghezza: 'col-md-4' },
    { campo: 'quantita', etichetta: 'Quantità', larghezza: 'col-md-2' },
    { campo: 'um', etichetta: 'UM', larghezza: 'col-md-2' },
    { campo: 'designatore', etichetta: 'Designatore', larghezza: 'col-md-3' },
  ],
};

function bomIdCorrente() {
  const el = document.getElementById('oggettoIdInput');
  return el ? el.value : null;
}

// recupera dalla BOM i valori attuali (quantità/UM/designatore) del componente scelto come
// "esistente" e li usa come default nei campi Quantità/UM/Designatore della stessa riga,
// per i tipi che lo richiedono (sostituisci, modifica_quantita)
function precompilaDaComponenteEsistente(cardEl, componenteId) {
  const bomId = bomIdCorrente();
  if (!bomId || !componenteId) return;
  fetch(window.BASE_URL_APP + 'bom/riga_ajax.php?bom_id=' + bomId + '&componente_id=' + componenteId)
    .then(r => r.json())
    .then(riga => {
      if (!riga) return;
      const campoQ = cardEl.querySelector('[name="mod_quantita[]"]');
      const campoU = cardEl.querySelector('[name="mod_um[]"]');
      const campoD = cardEl.querySelector('[name="mod_designatore[]"]');
      if (campoQ) { campoQ.value = riga.quantita; }
      if (campoU) { campoU.value = riga.unita_misura_id; }
      if (campoD) { campoD.value = riga.designatore || ''; }
    });
}

function costruisciCampo(cardEl, spec, dati) {
  const col = document.createElement('div');
  col.className = spec.larghezza;

  if (spec.campo === 'esistente' || spec.campo === 'nuovo') {
    col.innerHTML = `<label class="form-label small">${spec.etichetta}</label><div class="campo-${spec.campo}"></div>`;
    cardEl.querySelector('.row-campi').appendChild(col);
    const contenitore = col.querySelector('.campo-' + spec.campo);

    if (spec.campo === 'esistente') {
      const sel = creaSelettoreComponente(contenitore, {
        nomeCampo: 'mod_componente_id[]',
        placeholder: 'Cerca componente (seleziona prima la BOM sopra)...',
        bomId: bomIdCorrente,
        onSelezione: function (c) { precompilaDaComponenteEsistente(cardEl, c.id); }
      });
      if (dati.componente_id) {
        sel.querySelector('.sc-hidden').value = dati.componente_id;
        sel.querySelector('.sc-testo').value = dati.ce_codice + ' - ' + dati.ce_descrizione;
      }
    } else {
      const tipoRiga = cardEl.querySelector('.mod-tipo-select').value;
      const sel = creaSelettoreComponente(contenitore, {
        nomeCampo: 'mod_componente_nuovo_id[]',
        placeholder: 'Cerca componente...',
        onSelezione: function (c) {
          if (tipoRiga === 'aggiungi' && c.um_base_id) {
            const campoU = cardEl.querySelector('[name="mod_um[]"]');
            if (campoU) { campoU.value = c.um_base_id; }
          }
        }
      });
      if (dati.componente_nuovo_id) {
        sel.querySelector('.sc-hidden').value = dati.componente_nuovo_id;
        sel.querySelector('.sc-testo').value = dati.cn_codice + ' - ' + dati.cn_descrizione;
      }
    }
  } else if (spec.campo === 'quantita') {
    col.innerHTML = `<label class="form-label small">${spec.etichetta}</label><input type="text" name="mod_quantita[]" class="form-control form-control-sm" value="${dati.quantita ?? ''}">`;
    cardEl.querySelector('.row-campi').appendChild(col);
  } else if (spec.campo === 'um') {
    const umSelezionata = dati.unita_misura_id ?? umDefaultIdEco;
    col.innerHTML = `<label class="form-label small">${spec.etichetta}</label><select name="mod_um[]" class="form-select form-select-sm">${unitaMisuraEco.map(u => `<option value="${u.id}" ${u.id === umSelezionata ? 'selected' : ''}>${u.codice}</option>`).join('')}</select>`;
    cardEl.querySelector('.row-campi').appendChild(col);
  } else if (spec.campo === 'designatore') {
    col.innerHTML = `<label class="form-label small">${spec.etichetta}</label><input type="text" name="mod_designatore[]" class="form-control form-control-sm" value="${dati.designatore ?? ''}">`;
    cardEl.querySelector('.row-campi').appendChild(col);
  }
}

function ricostruisciCampiRiga(cardEl, dati) {
  dati = dati || {};
  const tipo = cardEl.querySelector('.mod-tipo-select').value;
  const rigaCampi = cardEl.querySelector('.row-campi');
  rigaCampi.innerHTML = '';
  CAMPI_PER_TIPO[tipo].forEach(spec => costruisciCampo(cardEl, spec, dati));
}

function aggiungiRigaModifica(dati) {
  dati = dati || {};
  contatoreModifiche++;
  const contenitore = document.getElementById('corpoModifiche');
  const card = document.createElement('div');
  card.className = 'card p-3 mb-2';
  card.innerHTML = `
    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-3">
        <label class="form-label small">Tipo modifica</label>
        <select name="mod_tipo[]" class="form-select form-select-sm mod-tipo-select">
          ${OPZIONI_TIPO.map(o => `<option value="${o.valore}" ${dati.tipo_modifica === o.valore ? 'selected' : ''}>${o.etichetta}</option>`).join('')}
        </select>
      </div>
      <div class="col-auto ms-auto">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.card').remove()"><i class="bi bi-trash"></i> Rimuovi riga</button>
      </div>
    </div>
    <div class="row g-2 row-campi"></div>`;
  contenitore.appendChild(card);

  ricostruisciCampiRiga(card, dati);
  card.querySelector('.mod-tipo-select').addEventListener('change', function () { ricostruisciCampiRiga(card); });
}

if (modificheEsistentiEco.length) {
  modificheEsistentiEco.forEach(m => aggiungiRigaModifica(m));
} else if (<?= json_encode($tipoOggettoPre) ?> === 'bom') {
  aggiungiRigaModifica();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
