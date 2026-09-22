<?php
$titolo_pagina = 'Componente';
require_once __DIR__ . '/../includes/header.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$componente = null;
$sostitutivoAttuale = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM componenti WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, azienda_id()]);
    $componente = $stmt->fetch();
    if (!$componente) { die('Componente non trovato.'); }
    if ($componente['componente_sostitutivo_id']) {
        $stmtSost = $pdo->prepare("SELECT id, codice_interno, descrizione FROM componenti WHERE id = ? AND azienda_id = ?");
        $stmtSost->execute([$componente['componente_sostitutivo_id'], azienda_id()]);
        $sostitutivoAttuale = $stmtSost->fetch();
    }
}

$unitaMisura = $pdo->prepare("SELECT * FROM unita_misura WHERE azienda_id = ? ORDER BY descrizione");
$unitaMisura->execute([azienda_id()]); $unitaMisura = $unitaMisura->fetchAll();
$categorie = $pdo->prepare("SELECT * FROM categorie_componenti WHERE azienda_id = ? ORDER BY codice IS NULL, codice, nome");
$categorie->execute([azienda_id()]); $categorie = $categorie->fetchAll();
$tipiComponente = $pdo->prepare("SELECT * FROM tipi_componente WHERE azienda_id = ? AND attivo = 1 ORDER BY ordinamento, descrizione");
$tipiComponente->execute([azienda_id()]); $tipiComponente = $tipiComponente->fetchAll();
$tecnologie = $pdo->prepare("SELECT * FROM tecnologie WHERE azienda_id = ? AND attivo = 1 ORDER BY ordinamento, descrizione");
$tecnologie->execute([azienda_id()]); $tecnologie = $tecnologie->fetchAll();
$nImmaginiEsistenti = $pdo->prepare("SELECT COUNT(*) c FROM (SELECT immagine FROM componenti WHERE azienda_id = ? AND immagine IS NOT NULL AND immagine != '' GROUP BY immagine) t");
$nImmaginiEsistenti->execute([azienda_id()]); $nImmaginiEsistenti = $nImmaginiEsistenti->fetch()['c'];
$errore = '';

// valori di default proposti per un nuovo componente (configurati in Amministrazione)
$tipoDefaultId = null; foreach ($tipiComponente as $t) { if ($t['is_predefinito']) { $tipoDefaultId = $t['id']; break; } }
$categoriaDefaultId = null; foreach ($categorie as $c) { if ($c['is_predefinita']) { $categoriaDefaultId = $c['id']; break; } }
$umDefaultId = null; foreach ($unitaMisura as $u) { if ($u['is_predefinita']) { $umDefaultId = $u['id']; break; } }
$tecnologiaDefaultId = null; foreach ($tecnologie as $tec) { if ($tec['is_predefinita']) { $tecnologiaDefaultId = $tec['id']; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $codice = trim($_POST['codice_interno']);
    $sigla = trim($_POST['sigla']);
    $descrizione = trim($_POST['descrizione']);
    $tipoComponenteId = (int) $_POST['tipo_componente_id'];
    $umBase = (int) $_POST['um_base_id'];
    $categoriaId = $_POST['categoria_id'] ?: null;
    $tecnologiaId = $_POST['tecnologia_id'] ?: null;
    $prezzoMedio = num_or($_POST['prezzo_medio'] ?? null, 0);
    $valuta = trim($_POST['valuta']) ?: 'EUR';
    $scortaMin = num_or($_POST['scorta_minima'] ?? null, 0);
    $scortaMax = num_or($_POST['scorta_massima'] ?? null, 0);
    $puntoRiordino = num_or($_POST['punto_riordino'] ?? null, 0);
    $leadTime = (int) $_POST['lead_time_giorni'];
    $note = trim($_POST['note']);
    $specifiche = trim($_POST['specifiche']);
    $caseComponente = trim($_POST['case_componente']);
    $dataInizioValidita = $_POST['data_inizio_validita'] ?: null;
    $dataFineValidita = $_POST['data_fine_validita'] ?: null;
    $statoCicloVita = in_array($_POST['stato_ciclo_vita'] ?? '', ['attivo','nrnd','eol','obsoleto'], true) ? $_POST['stato_ciclo_vita'] : 'attivo';
    $dataAnnuncioEol = $_POST['data_annuncio_eol'] ?: null;
    $dataUltimoOrdine = $_POST['data_ultimo_ordine'] ?: null;
    $dataUltimaConsegna = $_POST['data_ultima_consegna'] ?: null;
    $noteEol = trim($_POST['note_eol'] ?? '');
    $componenteSostitutivoId = (int) ($_POST['componente_sostitutivo_id'] ?? 0) ?: null;
    if ($componenteSostitutivoId) {
        // deve appartenere alla stessa azienda e non può essere il componente stesso
        $stmtSost = $pdo->prepare("SELECT id FROM componenti WHERE id=? AND azienda_id=?");
        $stmtSost->execute([$componenteSostitutivoId, azienda_id()]);
        if (!$stmtSost->fetch() || ($componente && $componenteSostitutivoId === (int) $componente['id'])) {
            $componenteSostitutivoId = null;
        }
    }
    $rimuoviImmagine = isset($_POST['rimuovi_immagine']);

    if ($codice === '' || $descrizione === '') {
        $errore = 'Codice e descrizione sono obbligatori.';
    } else {
        try {
            // gestione immagine principale: nuovo upload, riutilizzo di un'immagine già
            // caricata per un altro componente, o rimozione (in ordine di priorità)
            $percorsoImmagine = $componente['immagine'] ?? null;
            $immagineEsistenteScelta = trim($_POST['immagine_esistente'] ?? '');

            if (!empty($_FILES['immagine']['name'])) {
                $ris = gestisci_upload($_FILES['immagine'], IMG_UPLOAD_DIR, IMG_UPLOAD_URL, ALLOWED_IMG_EXT);
                if (!$ris['ok']) {
                    throw new Exception($ris['errore']);
                }
                if ($componente && !empty($componente['immagine']) && $componente['immagine'] !== $ris['percorso']) {
                    elimina_immagine_se_non_condivisa($pdo, $componente['immagine'], azienda_id(), $componente['id']);
                }
                $percorsoImmagine = $ris['percorso'];
            } elseif ($immagineEsistenteScelta !== '') {
                // verifica che il percorso scelto sia davvero un'immagine già usata dalla stessa azienda
                $stmtImg = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE azienda_id = ? AND immagine = ?");
                $stmtImg->execute([azienda_id(), $immagineEsistenteScelta]);
                if ($stmtImg->fetch()['c'] > 0) {
                    if ($componente && !empty($componente['immagine']) && $componente['immagine'] !== $immagineEsistenteScelta) {
                        elimina_immagine_se_non_condivisa($pdo, $componente['immagine'], azienda_id(), $componente['id']);
                    }
                    $percorsoImmagine = $immagineEsistenteScelta;
                }
            } elseif ($rimuoviImmagine && $percorsoImmagine) {
                if ($componente) {
                    elimina_immagine_se_non_condivisa($pdo, $percorsoImmagine, azienda_id(), $componente['id']);
                }
                $percorsoImmagine = null;
            }

            if ($componente) {
                $stmt = $pdo->prepare("UPDATE componenti SET codice_interno=?, sigla=?, descrizione=?, tipo_componente_id=?, categoria_id=?, tecnologia_id=?, um_base_id=?,
                    immagine=?, prezzo_medio=?, valuta=?, scorta_minima=?, scorta_massima=?, punto_riordino=?, lead_time_giorni=?, specifiche=?, case_componente=?, data_inizio_validita=?, data_fine_validita=?,
                    stato_ciclo_vita=?, data_annuncio_eol=?, data_ultimo_ordine=?, data_ultima_consegna=?, componente_sostitutivo_id=?, note_eol=?, note=?, utente_modifica_id=?, data_modifica=NOW() WHERE id=? AND azienda_id=?");
                $stmt->execute([$codice,$sigla,$descrizione,$tipoComponenteId,$categoriaId,$tecnologiaId,$umBase,$percorsoImmagine,$prezzoMedio,$valuta,$scortaMin,$scortaMax,$puntoRiordino,$leadTime,$specifiche,$caseComponente,$dataInizioValidita,$dataFineValidita,
                    $statoCicloVita,$dataAnnuncioEol,$dataUltimoOrdine,$dataUltimaConsegna,$componenteSostitutivoId,$noteEol,$note,$_SESSION['user_id'],$componente['id'],azienda_id()]);
                log_attivita($pdo, 'modifica_componente', 'componenti', $componente['id']);
                $idFinale = $componente['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO componenti (azienda_id, codice_interno, sigla, descrizione, tipo_componente_id, categoria_id, tecnologia_id, um_base_id,
                    immagine, prezzo_medio, valuta, scorta_minima, scorta_massima, punto_riordino, lead_time_giorni, specifiche, case_componente, data_inizio_validita, data_fine_validita,
                    stato_ciclo_vita, data_annuncio_eol, data_ultimo_ordine, data_ultima_consegna, componente_sostitutivo_id, note_eol, note, utente_creazione_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([azienda_id(),$codice,$sigla,$descrizione,$tipoComponenteId,$categoriaId,$tecnologiaId,$umBase,$percorsoImmagine,$prezzoMedio,$valuta,$scortaMin,$scortaMax,$puntoRiordino,$leadTime,$specifiche,$caseComponente,$dataInizioValidita,$dataFineValidita,
                    $statoCicloVita,$dataAnnuncioEol,$dataUltimoOrdine,$dataUltimaConsegna,$componenteSostitutivoId,$noteEol,$note,$_SESSION['user_id']]);
                $idFinale = $pdo->lastInsertId();
                // registra automaticamente la UM base come UM disponibile di default
                $pdo->prepare("INSERT INTO componenti_unita_misura (componente_id, unita_misura_id, fattore_conversione, is_default) VALUES (?,?,1,1)")
                    ->execute([$idFinale, $umBase]);
                log_attivita($pdo, 'crea_componente', 'componenti', $idFinale);
            }
            $_SESSION['flash_msg'] = 'Componente salvato correttamente.';
            $_SESSION['flash_type'] = 'success';
            header('Location: view.php?id=' . $idFinale);
            exit;
        } catch (Exception $e) {
            $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice interno già esistente.' : 'Errore: ' . $e->getMessage();
        }
    }
}
?>
<h4><i class="bi bi-box-seam"></i> <?= $componente ? h(t('componenti.modifica')) : h(t('componenti.nuovo')) ?></h4>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>
<?php if (!$tipiComponente): ?>
  <div class="alert alert-warning">Non è ancora stato configurato nessun tipo di componente. Vai in <a href="../admin/tipi_componente.php">Amministrazione &rarr; Tipi componente</a> per crearne almeno uno.</div>
<?php endif; ?>

<form method="post" class="card p-4 mt-3" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label"><?= h(t('componenti.form.codice_interno')) ?> *</label>
      <input type="text" name="codice_interno" class="form-control" required
             value="<?= h($componente['codice_interno'] ?? '') ?>" <?= $componente ? '' : 'placeholder="es. RES-0001"' ?>>
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.sigla')) ?></label>
      <input type="text" name="sigla" class="form-control" maxlength="50"
             value="<?= h($componente['sigla'] ?? '') ?>" placeholder="es. R1">
    </div>
    <div class="col-md-6">
      <label class="form-label"><?= h(t('componenti.form.descrizione')) ?> *</label>
      <input type="text" name="descrizione" class="form-control" required value="<?= h($componente['descrizione'] ?? '') ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.tipo_componente')) ?> *</label>
      <select name="tipo_componente_id" class="form-select" required>
        <?php foreach ($tipiComponente as $t):
          $selezionato = $componente ? ($componente['tipo_componente_id'] == $t['id']) : ($tipoDefaultId == $t['id']); ?>
        <option value="<?= $t['id'] ?>" <?= $selezionato ? 'selected' : '' ?>><?= h($t['descrizione']) ?><?= $t['ha_distinta_base'] ? ' (con distinta base)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Gestisci i tipi disponibili in <a href="../admin/tipi_componente.php">Amministrazione</a>.</div>
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.categoria')) ?></label>
      <select name="categoria_id" class="form-select">
        <option value="">-- Nessuna --</option>
        <?php foreach ($categorie as $cat):
          $selezionato = $componente ? ($componente['categoria_id'] == $cat['id']) : ($categoriaDefaultId == $cat['id']); ?>
        <option value="<?= $cat['id'] ?>" <?= $selezionato ? 'selected' : '' ?>><?= $cat['codice'] ? h($cat['codice']) . ' - ' : '' ?><?= h($cat['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.tecnologia')) ?></label>
      <select name="tecnologia_id" class="form-select">
        <option value="">-- Nessuna --</option>
        <?php foreach ($tecnologie as $tec):
          $selezionato = $componente ? ($componente['tecnologia_id'] == $tec['id']) : ($tecnologiaDefaultId == $tec['id']); ?>
        <option value="<?= $tec['id'] ?>" <?= $selezionato ? 'selected' : '' ?>><?= h($tec['codice']) ?> - <?= h($tec['descrizione']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Gestisci le tecnologie in <a href="../admin/tecnologie.php">Amministrazione</a>.</div>
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.case')) ?></label>
      <input type="text" name="case_componente" class="form-control" maxlength="50"
             value="<?= h($componente['case_componente'] ?? '') ?>" placeholder="es. SOT-23, 0805">
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.um_base')) ?> *</label>
      <select name="um_base_id" class="form-select" <?= $componente ? 'disabled' : 'required' ?>>
        <?php foreach ($unitaMisura as $um):
          $selezionato = $componente ? ($componente['um_base_id'] == $um['id']) : ($umDefaultId == $um['id']); ?>
        <option value="<?= $um['id'] ?>" <?= $selezionato ? 'selected' : '' ?>><?= h($um['codice']) ?> - <?= h($um['descrizione']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($componente): ?><input type="hidden" name="um_base_id" value="<?= $componente['um_base_id'] ?>">
      <div class="form-text">Non modificabile dopo la creazione (gestisci le UM aggiuntive dalla scheda componente).</div><?php endif; ?>
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.revisione_corrente')) ?></label>
      <input type="text" class="form-control" value="<?= h($componente['revisione_corrente'] ?? 'A') ?>" disabled>
      <div class="form-text">Gestita dalla sezione Revisioni</div>
    </div>

    <div class="col-md-3">
      <label class="form-label"><?= h(t('componenti.form.prezzo_medio')) ?></label>
      <input type="text" name="prezzo_medio" class="form-control" value="<?= h((string)($componente['prezzo_medio'] ?? '0')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.valuta')) ?></label>
      <input type="text" name="valuta" class="form-control" value="<?= h($componente['valuta'] ?? 'EUR') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.lead_time')) ?></label>
      <input type="number" name="lead_time_giorni" class="form-control" value="<?= h((string)($componente['lead_time_giorni'] ?? '0')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.scorta_minima')) ?></label>
      <input type="text" name="scorta_minima" class="form-control" value="<?= h((string)($componente['scorta_minima'] ?? '0')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.scorta_massima')) ?></label>
      <input type="text" name="scorta_massima" class="form-control" value="<?= h((string)($componente['scorta_massima'] ?? '0')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.punto_riordino')) ?></label>
      <input type="text" name="punto_riordino" class="form-control" value="<?= h((string)($componente['punto_riordino'] ?? '0')) ?>">
    </div>

    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.inizio_validita')) ?></label>
      <input type="date" name="data_inizio_validita" class="form-control" value="<?= h($componente['data_inizio_validita'] ?? date('Y') . '-01-01') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.fine_validita')) ?></label>
      <input type="date" name="data_fine_validita" class="form-control" value="<?= h($componente['data_fine_validita'] ?? '2099-12-31') ?>">
    </div>

    <div class="col-12"><hr class="my-2"><h6 class="text-primary"><?= h(t('componenti.form.ciclo_vita')) ?></h6></div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.stato_ciclo_vita')) ?></label>
      <?php $statoAttuale = $componente['stato_ciclo_vita'] ?? 'attivo'; ?>
      <select name="stato_ciclo_vita" class="form-select">
        <option value="attivo" <?= $statoAttuale==='attivo'?'selected':'' ?>>Attivo</option>
        <option value="nrnd" <?= $statoAttuale==='nrnd'?'selected':'' ?>>NRND (non raccomandato per nuovi progetti)</option>
        <option value="eol" <?= $statoAttuale==='eol'?'selected':'' ?>>EOL (fine vita annunciata)</option>
        <option value="obsoleto" <?= $statoAttuale==='obsoleto'?'selected':'' ?>>Obsoleto (non più disponibile)</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.data_annuncio_eol')) ?></label>
      <input type="date" name="data_annuncio_eol" class="form-control" value="<?= h($componente['data_annuncio_eol'] ?? '') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.ultimo_ordine_ltb')) ?></label>
      <input type="date" name="data_ultimo_ordine" class="form-control" value="<?= h($componente['data_ultimo_ordine'] ?? '') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label"><?= h(t('componenti.form.ultima_consegna')) ?></label>
      <input type="date" name="data_ultima_consegna" class="form-control" value="<?= h($componente['data_ultima_consegna'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label"><?= h(t('componenti.form.componente_sostitutivo')) ?></label>
      <div id="sostitutivoContainer"></div>
    </div>
    <div class="col-md-12">
      <label class="form-label"><?= h(t('componenti.form.note_eol')) ?></label>
      <textarea name="note_eol" class="form-control" rows="2" placeholder="Es. riferimento al bollettino EOL del produttore, condizioni del last time buy, ecc."><?= h($componente['note_eol'] ?? '') ?></textarea>
    </div>

    <div class="col-12"><hr class="my-2"></div>

    <div class="col-md-6">
      <label class="form-label"><?= h(t('componenti.form.specifiche')) ?></label>
      <textarea name="specifiche" class="form-control" rows="3" placeholder="Caratteristiche tecniche, dimensioni, tolleranze, ecc."><?= h($componente['specifiche'] ?? '') ?></textarea>
    </div>

    <div class="col-md-6">
      <label class="form-label"><?= h(t('componenti.form.note')) ?></label>
      <textarea name="note" class="form-control" rows="3"><?= h($componente['note'] ?? '') ?></textarea>
    </div>

    <div class="col-md-6">
      <label class="form-label"><?= h(t('componenti.form.immagine')) ?></label>
      <div id="anteprimaImmagine" class="d-flex align-items-center gap-3 mb-2" <?= empty($componente['immagine']) ? 'style="display:none;"' : '' ?>>
        <img id="anteprimaImmagineTag" src="<?= !empty($componente['immagine']) ? '../' . h($componente['immagine']) : '' ?>" style="max-height:90px; border-radius:6px;" alt="">
        <div class="form-check">
          <input type="checkbox" name="rimuovi_immagine" id="rimImg" class="form-check-input" value="1" <?= empty($componente['immagine']) ? 'disabled' : '' ?>>
          <label class="form-check-label small" for="rimImg"><?= h(t('componenti.form.rimuovi_immagine')) ?></label>
        </div>
      </div>
      <div class="d-flex gap-2 align-items-start">
        <div class="flex-fill">
          <input type="file" name="immagine" id="inputFileImmagine" class="form-control" accept=".jpg,.jpeg,.png,.webp">
          <div class="form-text">JPG, PNG o WEBP. Un nuovo file caricato ha sempre la priorità sull'immagine eventualmente scelta dalla libreria.</div>
        </div>
        <?php if ($nImmaginiEsistenti > 0): ?>
        <button type="button" class="btn btn-outline-secondary text-nowrap" data-bs-toggle="modal" data-bs-target="#modalLibreriaImmagini">
          <i class="bi bi-images"></i> Libreria (<?= $nImmaginiEsistenti ?>)
        </button>
        <?php endif; ?>
      </div>
      <input type="hidden" name="immagine_esistente" id="immagineEsistenteInput" value="">
    </div>
  </div>

  <?php if ($nImmaginiEsistenti > 0): ?>
  <div class="modal fade" id="modalLibreriaImmagini" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title"><i class="bi bi-images"></i> Scegli un'immagine già caricata</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" id="ricercaImmagini" class="form-control mb-3" placeholder="Cerca per codice o descrizione componente...">
          <div id="risultatiImmagini" class="row g-2"></div>
          <div class="text-center mt-3">
            <button type="button" id="btnCaricaAltreImmagini" class="btn btn-outline-secondary btn-sm" style="display:none;">Carica altre</button>
            <div id="nessunRisultatoImmagini" class="text-muted small" style="display:none;">Nessuna immagine trovata.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
  (function() {
    let paginaCorrente = 1;
    let ultimaRicerca = '';
    let timeoutRicerca = null;

    function caricaImmagini(ricerca, pagina, sostituisci) {
      fetch('immagini_ajax.php?q=' + encodeURIComponent(ricerca) + '&pagina=' + pagina)
        .then(r => r.json())
        .then(dati => {
          const contenitore = document.getElementById('risultatiImmagini');
          if (sostituisci) contenitore.innerHTML = '';
          document.getElementById('nessunRisultatoImmagini').style.display = (dati.totale === 0) ? 'block' : 'none';

          dati.immagini.forEach(function(img) {
            const col = document.createElement('div');
            col.className = 'col-3 text-center';
            col.innerHTML = `
              <img src="../${img.immagine}" data-percorso="${img.immagine}" class="img-libreria"
                   style="width:100%;height:70px;object-fit:cover;border-radius:4px;cursor:pointer;border:2px solid transparent;">
              <div class="small text-muted text-truncate" title="Usata da ${img.n_componenti} componente/i, es. ${img.codice_esempio}">${img.codice_esempio}${img.n_componenti > 1 ? ' (+' + (img.n_componenti - 1) + ')' : ''}</div>`;
            col.querySelector('img').addEventListener('click', function() {
              document.getElementById('immagineEsistenteInput').value = this.dataset.percorso;
              document.getElementById('inputFileImmagine').value = '';
              document.getElementById('rimImg').checked = false;
              document.getElementById('rimImg').disabled = false;
              document.getElementById('anteprimaImmagineTag').src = '../' + this.dataset.percorso;
              document.getElementById('anteprimaImmagine').style.display = 'flex';
              bootstrap.Modal.getInstance(document.getElementById('modalLibreriaImmagini')).hide();
            });
            contenitore.appendChild(col);
          });

          const caricate = pagina * dati.per_pagina;
          document.getElementById('btnCaricaAltreImmagini').style.display = (caricate < dati.totale) ? 'inline-block' : 'none';
        });
    }

    document.getElementById('modalLibreriaImmagini').addEventListener('show.bs.modal', function() {
      paginaCorrente = 1;
      ultimaRicerca = document.getElementById('ricercaImmagini').value;
      caricaImmagini(ultimaRicerca, paginaCorrente, true);
    });

    document.getElementById('ricercaImmagini').addEventListener('input', function() {
      clearTimeout(timeoutRicerca);
      const valore = this.value;
      timeoutRicerca = setTimeout(function() {
        paginaCorrente = 1;
        ultimaRicerca = valore;
        caricaImmagini(ultimaRicerca, paginaCorrente, true);
      }, 350);
    });

    document.getElementById('btnCaricaAltreImmagini').addEventListener('click', function() {
      paginaCorrente++;
      caricaImmagini(ultimaRicerca, paginaCorrente, false);
    });

    // se si carica un nuovo file manualmente, annulla l'eventuale selezione dalla libreria
    document.getElementById('inputFileImmagine').addEventListener('change', function() {
      if (this.value) { document.getElementById('immagineEsistenteInput').value = ''; }
    });
  })();
  </script>
  <?php endif; ?>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> <?= h(t('azione.salva')) ?></button>
    <a href="<?= $componente ? 'view.php?id='.$componente['id'] : 'list.php' ?>" class="btn btn-outline-secondary"><?= h(t('azione.annulla')) ?></a>
  </div>
</form>

<script>
const selettoreSostitutivo = creaSelettoreComponente(document.getElementById('sostitutivoContainer'), {
  nomeCampo: 'componente_sostitutivo_id',
  placeholder: 'Cerca componente sostitutivo (opzionale)...',
  <?php if ($componente): ?>escludiId: <?= (int) $componente['id'] ?>,<?php endif; ?>
});
<?php if ($sostitutivoAttuale): ?>
selettoreSostitutivo.querySelector('.sc-hidden').value = <?= json_encode($sostitutivoAttuale['id']) ?>;
selettoreSostitutivo.querySelector('.sc-testo').value = <?= json_encode($sostitutivoAttuale['codice_interno'] . ' - ' . $sostitutivoAttuale['descrizione']) ?>;
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
