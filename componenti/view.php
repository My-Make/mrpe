<?php
$titolo_pagina = 'Scheda componente';
require_once __DIR__ . '/../includes/header.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, um.codice um_codice, um.descrizione um_descrizione,
                        t.descrizione tipo_descrizione, t.ha_distinta_base,
                        tec.codice tecnologia_codice, tec.descrizione tecnologia_descrizione,
                        cat.codice categoria_codice, cat.nome categoria_nome, catp.nome categoria_padre_nome,
                        cr.nome utente_creazione_nome, cr.cognome utente_creazione_cognome,
                        um2.nome utente_modifica_nome, um2.cognome utente_modifica_cognome,
                        sost.codice_interno sostitutivo_codice, sost.descrizione sostitutivo_descrizione
                        FROM componenti c
                        JOIN unita_misura um ON um.id = c.um_base_id
                        JOIN tipi_componente t ON t.id = c.tipo_componente_id
                        LEFT JOIN tecnologie tec ON tec.id = c.tecnologia_id
                        LEFT JOIN categorie_componenti cat ON cat.id = c.categoria_id
                        LEFT JOIN categorie_componenti catp ON catp.id = cat.categoria_padre_id
                        LEFT JOIN utenti cr ON cr.id = c.utente_creazione_id
                        LEFT JOIN utenti um2 ON um2.id = c.utente_modifica_id
                        LEFT JOIN componenti sost ON sost.id = c.componente_sostitutivo_id
                        WHERE c.id = ? AND c.azienda_id = ?");
$stmt->execute([$id, azienda_id()]);
$c = $stmt->fetch();
if (!$c) { die('Componente non trovato.'); }

$umAggiuntive = $pdo->prepare("SELECT cu.*, um.codice, um.descrizione FROM componenti_unita_misura cu
                                JOIN unita_misura um ON um.id = cu.unita_misura_id WHERE cu.componente_id = ? ORDER BY cu.is_default DESC");
$umAggiuntive->execute([$id]); $umAggiuntive = $umAggiuntive->fetchAll();

$tutteUM = $pdo->prepare("SELECT * FROM unita_misura WHERE azienda_id = ? ORDER BY descrizione");
$tutteUM->execute([azienda_id()]); $tutteUM = $tutteUM->fetchAll();

$fornitoriCollegati = $pdo->prepare("SELECT cf.*, f.ragione_sociale, f.is_distributore, dac.nome_distributore, dac.store_id,
                                      om.stato omologazione_stato
                                      FROM componenti_fornitori cf
                                      JOIN fornitori f ON f.id = cf.fornitore_id
                                      LEFT JOIN distributori_api_config dac ON dac.fornitore_id = f.id AND dac.attivo = 1
                                      LEFT JOIN omologazioni om ON om.componente_id = cf.componente_id
                                          AND LOWER(TRIM(om.produttore)) = LOWER(TRIM(cf.produttore))
                                          AND LOWER(TRIM(om.codice_produttore)) = LOWER(TRIM(cf.codice_produttore))
                                          AND cf.produttore IS NOT NULL AND cf.produttore != '' AND cf.codice_produttore IS NOT NULL AND cf.codice_produttore != ''
                                      WHERE cf.componente_id = ? ORDER BY cf.preferito DESC, f.ragione_sociale");
$fornitoriCollegati->execute([$id]); $fornitoriCollegati = $fornitoriCollegati->fetchAll();

$tuttiFornitori = $pdo->prepare("SELECT * FROM fornitori WHERE azienda_id = ? AND attivo=1 ORDER BY ragione_sociale");
$tuttiFornitori->execute([azienda_id()]); $tuttiFornitori = $tuttiFornitori->fetchAll();

$alternativi = $pdo->prepare("SELECT ca.*, c2.codice_interno, c2.descrizione FROM componenti_alternativi ca
                               JOIN componenti c2 ON c2.id = ca.componente_alternativo_id WHERE ca.componente_id = ?");
$alternativi->execute([$id]); $alternativi = $alternativi->fetchAll();

$documenti = $pdo->prepare("SELECT * FROM componenti_documenti WHERE componente_id = ? ORDER BY data_upload DESC");
$documenti->execute([$id]); $documenti = $documenti->fetchAll();

$giacenze = $pdo->prepare("SELECT g.*, m.descrizione magazzino_desc, u.codice ubicazione_codice, l.codice_lotto_interno
                            FROM giacenze g JOIN magazzini m ON m.id = g.magazzino_id
                            LEFT JOIN ubicazioni u ON u.id = g.ubicazione_id
                            LEFT JOIN lotti l ON l.id = g.lotto_id
                            WHERE g.componente_id = ? AND g.quantita != 0 ORDER BY m.descrizione");
$giacenze->execute([$id]); $giacenze = $giacenze->fetchAll();
$giacenzaTot = giacenza_totale($pdo, $id);

$revisioni = $pdo->prepare("SELECT r.*, u.nome, u.cognome FROM revisioni r LEFT JOIN utenti u ON u.id = r.utente_id
                             WHERE r.tipo_oggetto='componente' AND r.oggetto_id = ? ORDER BY r.data_revisione DESC");
$revisioni->execute([$id]); $revisioni = $revisioni->fetchAll();

$doveUsato = $pdo->prepare("SELECT DISTINCT c2.id, c2.codice_interno, c2.descrizione FROM bom_righe br
                             JOIN bom b ON b.id = br.bom_id JOIN componenti c2 ON c2.id = b.componente_padre_id
                             WHERE br.componente_id = ?");
$doveUsato->execute([$id]); $doveUsato = $doveUsato->fetchAll();

$omologazioni = $pdo->prepare("SELECT o.*,
        r.id richiesta_id, r.stato_richiesto, r.motivazione richiesta_motivazione, r.data_richiesta richiesta_data,
        ur.nome richiedente_nome, ur.cognome richiedente_cognome
        FROM omologazioni o
        LEFT JOIN omologazioni_richieste r ON r.omologazione_id = o.id AND r.stato_richiesta = 'in_attesa'
        LEFT JOIN utenti ur ON ur.id = r.richiedente_id
        WHERE o.componente_id = ? AND o.azienda_id = ?
        ORDER BY o.produttore, o.codice_produttore");
$omologazioni->execute([$id, azienda_id()]); $omologazioni = $omologazioni->fetchAll();

// documenti collegati a ciascuna omologazione, raggruppati per omologazione_id
$documentiOmologazioni = [];
if ($omologazioni) {
    $stmt = $pdo->prepare("SELECT od.omologazione_id, cd.id, cd.nome_file, cd.tipo_allegato, cd.percorso
                            FROM omologazioni_documenti od
                            JOIN componenti_documenti cd ON cd.id = od.documento_id
                            JOIN omologazioni o ON o.id = od.omologazione_id
                            WHERE o.componente_id = ?");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $riga) {
        $documentiOmologazioni[$riga['omologazione_id']][] = $riga;
    }
}

// storico completo (tutte le richieste, decise o in attesa) di tutte le omologazioni di questo componente
$storicoOmologazioni = $pdo->prepare("SELECT r.*, o.produttore, o.codice_produttore,
        ur.nome richiedente_nome, ur.cognome richiedente_cognome,
        ua.nome approvatore_nome, ua.cognome approvatore_cognome
        FROM omologazioni_richieste r
        JOIN omologazioni o ON o.id = r.omologazione_id
        LEFT JOIN utenti ur ON ur.id = r.richiedente_id
        LEFT JOIN utenti ua ON ua.id = r.approvatore_id
        WHERE o.componente_id = ?
        ORDER BY r.data_richiesta DESC");
$storicoOmologazioni->execute([$id]); $storicoOmologazioni = $storicoOmologazioni->fetchAll();

// tab attiva dopo un'operazione (torna() in gestisci.php la passa come parametro): validata
// contro un elenco fisso per sicurezza, decisa qui lato server (niente dipendenze da JS/hash).
$tabsValide = ['tab-anagrafica','tab-um','tab-fornitori','tab-alternativi','tab-documenti','tab-giacenze','tab-revisioni','tab-doveusato','tab-omologazione'];
$tabAttiva = $_GET['tab'] ?? 'tab-anagrafica';
if (!in_array($tabAttiva, $tabsValide, true)) { $tabAttiva = 'tab-anagrafica'; }
function tab_nav_class(string $tabId, string $tabAttiva): string { return 'nav-link' . ($tabAttiva === $tabId ? ' active' : ''); }
function tab_pane_class(string $tabId, string $tabAttiva): string { return 'tab-pane fade' . ($tabAttiva === $tabId ? ' show active' : ''); }
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div class="d-flex gap-3 align-items-start">
    <?php if (!empty($c['immagine'])): ?>
      <img src="../<?= h($c['immagine']) ?>" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
    <?php else: ?>
      <div class="d-flex align-items-center justify-content-center bg-light" style="width:90px;height:90px;border-radius:8px;border:1px solid #ddd;">
        <i class="bi bi-image text-muted fs-3"></i>
      </div>
    <?php endif; ?>
    <div>
      <h4 class="mb-0"><?= h($c['codice_interno']) ?> <?php if (!empty($c['sigla'])): ?><span class="text-muted">(<?= h($c['sigla']) ?>)</span><?php endif; ?> <span class="badge bg-secondary"><?= h($c['tipo_descrizione']) ?></span> <?php if (!empty($c['categoria_nome'])): ?><span class="badge bg-light text-dark border"><i class="bi bi-folder2"></i> <?= h($c['categoria_nome']) ?></span><?php endif; ?> <?php if (!empty($c['tecnologia_codice'])): ?><span class="badge bg-dark" title="<?= h($c['tecnologia_descrizione']) ?>"><?= h($c['tecnologia_codice']) ?></span><?php endif; ?> <?php
        $badgeCicloVita = ['nrnd' => ['warning text-dark', 'NRND'], 'eol' => ['danger', 'EOL'], 'obsoleto' => ['dark', 'OBSOLETO']];
        if (isset($badgeCicloVita[$c['stato_ciclo_vita']])): [$classeBadge, $etichetta] = $badgeCicloVita[$c['stato_ciclo_vita']]; ?>
          <span class="badge bg-<?= $classeBadge ?>" title="Ciclo di vita: <?= $etichetta ?>"><i class="bi bi-exclamation-triangle"></i> <?= $etichetta ?></span>
        <?php endif; ?> <?php if (!$c['attivo']): ?><span class="badge bg-warning text-dark">Disattivato</span><?php endif; ?></h4>
      <p class="text-muted mb-0"><?= h($c['descrizione']) ?></p>
    </div>
  </div>
  <div>
    <?php if (!is_sola_lettura()): ?><a href="form.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Modifica anagrafica</a><?php endif; ?>
    <?php if (!is_sola_lettura()): ?><button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalDuplica"><i class="bi bi-files"></i> Duplica</button><?php endif; ?>
    <?php if (utente_ha_accesso_sezione('eco') && !is_sola_lettura('eco')): ?><a href="../eco/form.php?tipo_oggetto=componente&oggetto_id=<?= $id ?>" class="btn btn-outline-warning"><i class="bi bi-pencil-square"></i> Crea ECO</a><?php endif; ?>
    <a href="list.php" class="btn btn-outline-secondary">Torna all'elenco</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Giacenza totale</div><div class="fs-4 fw-bold"><?= number_format($giacenzaTot,2,',','.') ?></div><div class="small"><?= h($c['um_codice']) ?></div></div></div>
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Prezzo medio</div><div class="fs-5 fw-bold"><?= number_format($c['prezzo_medio'],4,',','.') ?></div><div class="small"><?= h($c['valuta']) ?></div></div></div>
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Lead time</div><div class="fs-5 fw-bold"><?= (int)$c['lead_time_giorni'] ?> gg</div></div></div>
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Punto riordino</div><div class="fs-5 fw-bold"><?= number_format($c['punto_riordino'],2,',','.') ?></div></div></div>
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Scorta min/max</div><div class="fs-6 fw-bold"><?= number_format($c['scorta_minima'],2,',','.') ?> / <?= number_format($c['scorta_massima'],2,',','.') ?></div></div></div>
  <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Revisione corrente</div><div class="fs-4 fw-bold"><?= h($c['revisione_corrente']) ?></div></div></div>
</div>

<ul class="nav nav-tabs" id="tabMenu">
  <li class="nav-item"><a class="<?= tab_nav_class('tab-anagrafica', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-anagrafica">Anagrafica</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-um', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-um">Unità di misura</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-fornitori', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-fornitori">Fornitori</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-alternativi', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-alternativi">Componenti alternativi</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-documenti', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-documenti">Documentazione</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-giacenze', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-giacenze">Giacenze e lotti</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-revisioni', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-revisioni">Revisioni</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-doveusato', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-doveusato">Dove è usato</a></li>
  <li class="nav-item"><a class="<?= tab_nav_class('tab-omologazione', $tabAttiva) ?>" data-bs-toggle="tab" href="#tab-omologazione">Omologazione</a></li>
</ul>

<div class="tab-content card p-4 border-top-0">

  <!-- ANAGRAFICA -->
  <div class="<?= tab_pane_class('tab-anagrafica', $tabAttiva) ?>" id="tab-anagrafica">
    <table class="table table-sm">
      <tbody>
        <tr><th style="width:220px;">Codice interno</th><td><?= h($c['codice_interno']) ?></td></tr>
        <tr><th>Sigla</th><td><?= h($c['sigla']) ?: '-' ?></td></tr>
        <tr><th>Descrizione</th><td><?= h($c['descrizione']) ?></td></tr>
        <tr><th>Tipo</th><td><?= h($c['tipo_descrizione']) ?><?= $c['ha_distinta_base'] ? ' (con distinta base)' : '' ?></td></tr>
        <tr><th>Categoria</th><td><?php
          if ($c['categoria_nome']) {
              $percorso = $c['categoria_padre_nome'] ? h($c['categoria_padre_nome']) . '\\' . h($c['categoria_nome']) : h($c['categoria_nome']);
              echo ($c['categoria_codice'] ? h($c['categoria_codice']) . ' - ' : '') . $percorso;
          } else {
              echo '-';
          }
        ?></td></tr>
        <tr><th>Tecnologia</th><td><?= $c['tecnologia_descrizione'] ? h($c['tecnologia_codice']) . ' - ' . h($c['tecnologia_descrizione']) : '-' ?></td></tr>
        <tr><th>Case</th><td><?= h($c['case_componente']) ?: '-' ?></td></tr>
        <tr><th>Inizio validità</th><td><?= $c['data_inizio_validita'] ? date('d/m/Y', strtotime($c['data_inizio_validita'])) : '-' ?></td></tr>
        <tr><th>Fine Validità</th><td><?= $c['data_fine_validita'] ? date('d/m/Y', strtotime($c['data_fine_validita'])) : '-' ?></td></tr>
        <tr><th>Ciclo di vita</th><td>
          <?php $etichetteCicloVita = ['attivo' => 'Attivo', 'nrnd' => 'NRND (non raccomandato per nuovi progetti)', 'eol' => 'EOL (fine vita annunciata)', 'obsoleto' => 'Obsoleto (non più disponibile)']; ?>
          <?= h($etichetteCicloVita[$c['stato_ciclo_vita']] ?? 'Attivo') ?>
        </td></tr>
        <?php if ($c['stato_ciclo_vita'] !== 'attivo'): ?>
        <tr><th>Data annuncio EOL</th><td><?= $c['data_annuncio_eol'] ? date('d/m/Y', strtotime($c['data_annuncio_eol'])) : '-' ?></td></tr>
        <tr><th>Ultimo ordine (LTB)</th><td><?= $c['data_ultimo_ordine'] ? date('d/m/Y', strtotime($c['data_ultimo_ordine'])) : '-' ?></td></tr>
        <tr><th>Ultima consegna</th><td><?= $c['data_ultima_consegna'] ? date('d/m/Y', strtotime($c['data_ultima_consegna'])) : '-' ?></td></tr>
        <tr><th>Componente sostitutivo</th><td><?php if (!empty($c['componente_sostitutivo_id'])): ?><a href="view.php?id=<?= (int) $c['componente_sostitutivo_id'] ?>"><?= h($c['sostitutivo_codice']) ?> - <?= h($c['sostitutivo_descrizione']) ?></a><?php else: ?>-<?php endif; ?></td></tr>
        <tr><th>Note EOL</th><td style="white-space:pre-line;"><?= h($c['note_eol']) ?: '-' ?></td></tr>
        <?php endif; ?>
        <tr><th>Unità di misura base</th><td><?= h($c['um_codice']) ?> - <?= h($c['um_descrizione']) ?></td></tr>
        <tr><th>Prezzo medio</th><td><?= number_format($c['prezzo_medio'],4,',','.') ?> <?= h($c['valuta']) ?></td></tr>
        <tr><th>Scorta minima</th><td><?= number_format($c['scorta_minima'],2,',','.') ?></td></tr>
        <tr><th>Scorta massima</th><td><?= number_format($c['scorta_massima'],2,',','.') ?></td></tr>
        <tr><th>Punto di riordino</th><td><?= number_format($c['punto_riordino'],2,',','.') ?></td></tr>
        <tr><th>Lead time</th><td><?= (int) $c['lead_time_giorni'] ?> giorni</td></tr>
        <tr><th>Revisione corrente</th><td><span class="badge bg-primary"><?= h($c['revisione_corrente']) ?></span></td></tr>
        <tr><th>Specifiche</th><td style="white-space:pre-line;"><?= h($c['specifiche']) ?: '-' ?></td></tr>
        <tr><th>Note</th><td style="white-space:pre-line;"><?= h($c['note']) ?: '-' ?></td></tr>
        <tr><th>Stato</th><td><?= $c['attivo'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-warning text-dark">Disattivato</span>' ?></td></tr>
        <tr><th>Creato il</th><td><?= date('d/m/Y H:i', strtotime($c['data_creazione'])) ?><?= $c['utente_creazione_nome'] ? ' da ' . h($c['utente_creazione_nome'] . ' ' . $c['utente_creazione_cognome']) : '' ?></td></tr>
        <tr><th>Ultima modifica</th><td><?= $c['data_modifica'] ? date('d/m/Y H:i', strtotime($c['data_modifica'])) . ($c['utente_modifica_nome'] ? ' da ' . h($c['utente_modifica_nome'] . ' ' . $c['utente_modifica_cognome']) : '') : '-' ?></td></tr>
      </tbody>
    </table>
  </div>

  <!-- UNITA' DI MISURA -->
  <div class="<?= tab_pane_class('tab-um', $tabAttiva) ?>" id="tab-um">
    <table class="table table-sm">
      <thead><tr><th>UM</th><th>Descrizione</th><th>Fattore conversione verso base (<?= h($c['um_codice']) ?>)</th><th>Default</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($umAggiuntive as $u): ?>
        <tr>
          <td><?= h($u['codice']) ?></td><td><?= h($u['descrizione']) ?></td>
          <td>1 <?= h($u['codice']) ?> = <?= number_format($u['fattore_conversione'],4,',','.') ?> <?= h($c['um_codice']) ?></td>
          <td><?= $u['is_default'] ? '<span class="badge bg-primary">Base</span>' : '' ?></td>
          <td>
            <?php if (!$u['is_default'] && !is_sola_lettura()): ?>
            <form method="post" action="gestisci.php" onsubmit="return confirm('Rimuovere questa unità di misura?');">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="tab-um">
              <input type="hidden" name="azione" value="elimina_um">
              <input type="hidden" name="componente_id" value="<?= $id ?>">
              <input type="hidden" name="riga_id" value="<?= $u['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="tab-um">
      <input type="hidden" name="azione" value="aggiungi_um">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <div class="col-md-3">
        <label class="form-label small">Unità di misura</label>
        <select name="unita_misura_id" class="form-select" required>
          <?php foreach ($tutteUM as $um): ?><option value="<?= $um['id'] ?>"><?= h($um['codice']) ?> - <?= h($um['descrizione']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small">1 unità di misura = quanti <?= h($c['um_codice']) ?> (base)</label>
        <input type="text" name="fattore_conversione" class="form-control" placeholder="es. 100 (per un rotolo da 100 pezzi)" required>
      </div>
      <div class="col-md-3"><button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Aggiungi UM</button></div>
    </form>
    <?php endif; ?>
  </div>

  <!-- FORNITORI -->
  <div class="<?= tab_pane_class('tab-fornitori', $tabAttiva) ?>" id="tab-fornitori">
    <?php
    // link di fallback alla pagina di ricerca del distributore quando l'API non ha
    // restituito un link diretto al prodotto (Mouser e DigiKey lo forniscono, gli altri no)
    function link_ricerca_distributore(?string $nomeDistributore, string $codiceFornitore, ?string $storeId): ?string {
        return match ($nomeDistributore) {
            'mouser' => 'https://www.mouser.com/c/?q=' . urlencode($codiceFornitore),
            'digikey' => 'https://www.digikey.com/en/products/result?keywords=' . urlencode($codiceFornitore),
            'farnell' => 'https://' . ($storeId ?: 'it.farnell.com') . '/search?st=' . urlencode($codiceFornitore),
            'arrow' => 'https://www.arrow.com/en/products/search?q=' . urlencode($codiceFornitore),
            'tme' => 'https://www.tme.eu/en/katalog/?search=' . urlencode($codiceFornitore),
            default => null,
        };
    }
    $modificaFornitoreId = (int) ($_GET['modifica_fornitore'] ?? 0);
    $fornitoreInModifica = null;
    foreach ($fornitoriCollegati as $fc) { if ($fc['id'] == $modificaFornitoreId) { $fornitoreInModifica = $fc; break; } }
    $haFornitoriConApi = (bool) array_filter($fornitoriCollegati, fn($f) => !empty($f['nome_distributore']));
    $fornitoriNonOmologati = array_filter($fornitoriCollegati, function ($f) {
        return !empty($f['produttore']) && !empty($f['codice_produttore']) && $f['omologazione_stato'] !== 'omologato';
    });
    ?>
    <?php if ($fornitoriNonOmologati): ?>
    <div class="alert alert-warning py-2">
      <i class="bi bi-exclamation-triangle"></i>
      <?= count($fornitoriNonOmologati) ?> fornitore/i con produttore/codice non risultante come "Omologato" nella tab Omologazione (vedi colonna sottostante).
    </div>
    <?php endif; ?>
    <?php if ($haFornitoriConApi && !is_sola_lettura()): ?>
    <div class="mb-2 text-end">
      <a href="../api/distributori.php?componente_id=<?= $id ?>&tutti=1" class="btn btn-outline-info btn-sm" onclick="return confirm('Aggiornare prezzo, disponibilità, MOQ e produttore da tutti i distributori collegati a questo componente?');">
        <i class="bi bi-arrow-repeat"></i> Aggiorna tutti dai distributori
      </a>
    </div>
    <?php endif; ?>
    <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Fornitore</th><th>Cod. fornitore</th><th>Produttore</th><th>Cod. produttore</th><th>Omologazione</th><th class="text-end">Prezzo</th><th class="text-end">Lead</th><th class="text-end">MOQ</th><th class="text-end">Prezzo &times; MOQ</th><th class="text-end">Shipping</th><th class="text-end">Disp.</th><th></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fornitoriCollegati as $f): ?>
        <tr class="<?= $modificaFornitoreId === $f['id'] ? 'table-warning' : '' ?>">
          <td><?= h($f['ragione_sociale']) ?> <?= $f['is_distributore'] ? '<span class="badge bg-info text-dark">distributore</span>' : '' ?> <?= $f['preferito'] ? '<span class="badge bg-success">preferito</span>' : '' ?></td>
          <td><?= h($f['codice_fornitore']) ?></td>
          <td><?= h($f['produttore']) ?: '-' ?></td>
          <td><?= h($f['codice_produttore']) ?: '-' ?></td>
          <td>
            <?php if (empty($f['produttore']) || empty($f['codice_produttore'])): ?>
              <span class="text-muted">-</span>
            <?php elseif ($f['omologazione_stato'] === 'omologato'): ?>
              <span class="badge bg-success" title="Produttore/codice trovato in Omologazione, stato Omologato"><i class="bi bi-check-lg"></i> Omologato</span>
            <?php elseif ($f['omologazione_stato'] === 'accettato_in_deroga'): ?>
              <span class="badge bg-warning text-dark" title="Produttore/codice trovato in Omologazione, stato Accettato in deroga"><i class="bi bi-exclamation-triangle"></i> Accettato in deroga</span>
            <?php elseif ($f['omologazione_stato'] === 'non_omologato'): ?>
              <span class="badge bg-danger" title="Produttore/codice trovato in Omologazione, ma NON omologato"><i class="bi bi-exclamation-triangle"></i> Non omologato</span>
            <?php else: ?>
              <span class="badge bg-danger" title="Nessuna corrispondenza produttore/codice trovata nella tab Omologazione di questo componente"><i class="bi bi-exclamation-triangle"></i> Non in Omologazione</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= number_format($f['prezzo'],4,',','.') ?> <?= h($f['valuta']) ?></td>
          <td class="text-end"><?= (int)$f['lead_time_giorni'] ?> gg</td>
          <td class="text-end"><?= number_format($f['moq'],2,',','.') ?></td>
          <td class="text-end"><?= ($f['prezzo'] !== null && $f['moq'] !== null) ? number_format($f['prezzo'] * $f['moq'], 2, ',', '.') . ' ' . h($f['valuta']) : '-' ?></td>
          <td class="text-end"><?= $f['shipping_cost'] !== null ? number_format($f['shipping_cost'],2,',','.') . ' ' . h($f['valuta']) : '-' ?></td>
          <td class="text-end">
            <?= $f['disponibilita_real_time'] !== null ? number_format($f['disponibilita_real_time'],0,',','.') : '<span class="text-muted">n/d</span>' ?>
            <?php if ($f['ultimo_aggiornamento_api']): ?><div class="small text-muted"><?= date('d/m H:i', strtotime($f['ultimo_aggiornamento_api'])) ?></div><?php endif; ?>
          </td>
          <td>
            <?php
            $linkProdotto = $f['url_prodotto'] ?: ($f['is_distributore'] ? link_ricerca_distributore($f['nome_distributore'], $f['codice_fornitore'], $f['store_id']) : null);
            if ($linkProdotto): ?>
              <a href="<?= h($linkProdotto) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Apri pagina prodotto"><i class="bi bi-box-arrow-up-right"></i></a>
            <?php endif; ?>
            <?php if ($f['is_distributore'] && !is_sola_lettura()): ?><a href="../api/distributori.php?componente_fornitore_id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-info" title="Aggiorna da API distributore"><i class="bi bi-arrow-repeat"></i></a><?php endif; ?>
          </td>
          <td>
            <?php if (!is_sola_lettura()): ?>
            <a href="view.php?id=<?= $id ?>&tab=tab-fornitori&modifica_fornitore=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifica"><i class="bi bi-pencil"></i></a>
            <form method="post" action="gestisci.php" class="d-inline" onsubmit="return confirm('Rimuovere questo collegamento fornitore?');">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="tab-fornitori">
              <input type="hidden" name="azione" value="elimina_fornitore">
              <input type="hidden" name="componente_id" value="<?= $id ?>">
              <input type="hidden" name="riga_id" value="<?= $f['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fornitoriCollegati): ?><tr><td colspan="13" class="text-center text-muted py-3">Nessun fornitore collegato.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>

    <?php if ($fornitoreInModifica && !is_sola_lettura()): ?>
    <div class="alert alert-warning">
      <strong>Modifica fornitore: <?= h($fornitoreInModifica['ragione_sociale']) ?></strong>
      <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="tab-fornitori">
        <input type="hidden" name="azione" value="modifica_riga_fornitore">
        <input type="hidden" name="componente_id" value="<?= $id ?>">
        <input type="hidden" name="riga_id" value="<?= $fornitoreInModifica['id'] ?>">
        <div class="col-md-2"><label class="form-label small">Codice fornitore</label><input type="text" name="codice_fornitore" class="form-control" required value="<?= h($fornitoreInModifica['codice_fornitore']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Produttore</label><input type="text" name="produttore" class="form-control" value="<?= h($fornitoreInModifica['produttore']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Cod. produttore</label><input type="text" name="codice_produttore" class="form-control" value="<?= h($fornitoreInModifica['codice_produttore']) ?>"></div>
        <div class="col-md-3"><label class="form-label small">Link pagina prodotto</label><input type="url" name="url_prodotto" class="form-control" value="<?= h($fornitoreInModifica['url_prodotto']) ?>" placeholder="https://..."></div>
        <div class="col-md-1"><label class="form-label small">Prezzo</label><input type="text" name="prezzo" class="form-control" value="<?= h((string)($fornitoreInModifica['prezzo'] ?? '')) ?>"></div>
        <div class="col-md-1"><label class="form-label small">Valuta</label><input type="text" name="valuta" class="form-control" value="<?= h($fornitoreInModifica['valuta']) ?>"></div>
        <div class="col-md-1"><label class="form-label small">Lead(gg)</label><input type="number" name="lead_time_giorni" class="form-control" value="<?= h((string)$fornitoreInModifica['lead_time_giorni']) ?>"></div>
        <div class="col-md-1"><label class="form-label small">MOQ</label><input type="text" name="moq" class="form-control" value="<?= h((string)($fornitoreInModifica['moq'] ?? '')) ?>"></div>
        <div class="col-md-1"><label class="form-label small">Multiplo</label><input type="text" name="multiplo_ordine" class="form-control" value="<?= h((string)($fornitoreInModifica['multiplo_ordine'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Shipping cost</label><input type="text" name="shipping_cost" class="form-control" value="<?= h((string)($fornitoreInModifica['shipping_cost'] ?? '')) ?>"></div>
        <div class="col-md-9"><label class="form-label small">Note</label><input type="text" name="note" class="form-control" value="<?= h($fornitoreInModifica['note']) ?>"></div>
        <div class="col-md-2 form-check mt-4"><input type="checkbox" name="preferito" class="form-check-input" id="prefMod" <?= $fornitoreInModifica['preferito'] ? 'checked' : '' ?>><label class="form-check-label small" for="prefMod">Preferito</label></div>
        <div class="col-md-1 d-flex gap-1">
          <button class="btn btn-primary flex-fill" title="Salva"><i class="bi bi-save"></i></button>
        </div>
        <div class="col-12"><a href="view.php?id=<?= $id ?>&tab=tab-fornitori" class="small">Annulla modifica</a></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="tab-fornitori">
      <input type="hidden" name="azione" value="aggiungi_fornitore">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <div class="col-md-3">
        <label class="form-label small">Fornitore</label>
        <select name="fornitore_id" class="form-select" required>
          <?php foreach ($tuttiFornitori as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['ragione_sociale']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small">Codice fornitore</label><input type="text" name="codice_fornitore" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label small">Produttore</label><input type="text" name="produttore" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small">Cod. produttore</label><input type="text" name="codice_produttore" class="form-control"></div>
      <div class="col-md-3"><label class="form-label small">Link pagina prodotto</label><input type="url" name="url_prodotto" class="form-control" placeholder="https://..."></div>
      <div class="col-md-1"><label class="form-label small">Prezzo</label><input type="text" name="prezzo" class="form-control"></div>
      <div class="col-md-1"><label class="form-label small">Valuta</label><input type="text" name="valuta" class="form-control" value="EUR"></div>
      <div class="col-md-1"><label class="form-label small">Lead(gg)</label><input type="number" name="lead_time_giorni" class="form-control" value="0"></div>
      <div class="col-md-1"><label class="form-label small">MOQ</label><input type="text" name="moq" class="form-control" value="1"></div>
      <div class="col-md-1"><label class="form-label small">Multiplo</label><input type="text" name="multiplo_ordine" class="form-control" value="1"></div>
      <div class="col-md-2"><label class="form-label small">Shipping cost</label><input type="text" name="shipping_cost" class="form-control"></div>
      <div class="col-md-2 form-check mt-4"><input type="checkbox" name="preferito" class="form-check-input" id="pref"><label class="form-check-label small" for="pref">Preferito</label></div>
      <div class="col-md-1"><button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i></button></div>
    </form>
    <?php endif; ?>
    <p class="text-muted small mt-2"><i class="bi bi-info-circle"></i> Per i fornitori marcati come "distributore" (Mouser, Digikey, Arrow, Avnet, RS...) puoi aggiornare prezzo e disponibilità in tempo reale con il pulsante <i class="bi bi-arrow-repeat"></i> — vedi modulo <code>api/distributori.php</code>.</p>
  </div>

  <!-- ALTERNATIVI -->
  <div class="<?= tab_pane_class('tab-alternativi', $tabAttiva) ?>" id="tab-alternativi">
    <table class="table table-sm">
      <thead><tr><th>Codice</th><th>Descrizione</th><th>Compatibilità</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($alternativi as $a): ?>
        <tr>
          <td><a href="view.php?id=<?= $a['componente_alternativo_id'] ?>"><?= h($a['codice_interno']) ?></a></td>
          <td><?= h($a['descrizione']) ?></td>
          <td><span class="badge bg-<?= $a['compatibilita']==='totale'?'success':'warning' ?>"><?= h($a['compatibilita']) ?></span></td>
          <td><?= h($a['note']) ?></td>
          <td>
            <?php if (!is_sola_lettura()): ?>
            <form method="post" action="gestisci.php" onsubmit="return confirm('Rimuovere questa alternativa?');">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="tab-alternativi">
              <input type="hidden" name="azione" value="elimina_alternativo">
              <input type="hidden" name="componente_id" value="<?= $id ?>">
              <input type="hidden" name="riga_id" value="<?= $a['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="tab-alternativi">
      <input type="hidden" name="azione" value="aggiungi_alternativo">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <div class="col-md-5">
        <label class="form-label small">Componente alternativo</label>
        <div id="alternativoContainer"></div>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Compatibilità</label>
        <select name="compatibilita" class="form-select"><option value="totale">Totale</option><option value="parziale">Parziale</option></select>
      </div>
      <div class="col-md-3"><label class="form-label small">Note</label><input type="text" name="note" class="form-control"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Aggiungi</button></div>
    </form>
    <?php endif; ?>
  </div>

  <!-- DOCUMENTI -->
  <div class="<?= tab_pane_class('tab-documenti', $tabAttiva) ?>" id="tab-documenti">
    <div class="row g-3">
    <?php
    $iconePerTipo = [
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'doc' => 'bi-file-earmark-word text-primary', 'docx' => 'bi-file-earmark-word text-primary',
        'xls' => 'bi-file-earmark-excel text-success', 'xlsx' => 'bi-file-earmark-excel text-success',
        'ppt' => 'bi-file-earmark-ppt text-warning', 'pptx' => 'bi-file-earmark-ppt text-warning',
        'zip' => 'bi-file-earmark-zip text-secondary',
        'txt' => 'bi-file-earmark-text text-secondary',
        'dwg' => 'bi-file-earmark-ruled text-secondary', 'step' => 'bi-file-earmark-ruled text-secondary', 'stp' => 'bi-file-earmark-ruled text-secondary',
    ];
    $tipiVisualizzabiliInline = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt'];
    ?>
    <?php foreach ($documenti as $d): ?>
      <div class="col-md-3">
        <div class="card p-2 text-center">
          <?php if ($d['tipo_allegato'] === 'link'): ?>
            <a href="<?= h($d['percorso']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
              <i class="bi bi-link-45deg text-primary" style="font-size:3rem;"></i>
            </a>
          <?php elseif (in_array($d['tipo_file'], ['jpg','jpeg','png','webp'])): ?>
            <img src="../<?= h($d['percorso']) ?>" class="img-fluid mb-2" style="max-height:100px;object-fit:contain;">
          <?php else: ?>
            <i class="bi <?= $iconePerTipo[$d['tipo_file']] ?? 'bi-file-earmark' ?>" style="font-size:3rem;"></i>
          <?php endif; ?>
          <div class="small fw-bold text-truncate" title="<?= h($d['nome_file']) ?>"><?= h($d['nome_file']) ?></div>
          <div class="small text-muted text-truncate" title="<?= h($d['descrizione']) ?>">
            <?= $d['tipo_allegato'] === 'link' ? 'Link esterno' : 'v' . h($d['versione']) ?> <?= $d['descrizione'] ? '- ' . h($d['descrizione']) : '' ?>
          </div>
          <div class="d-flex justify-content-center gap-1 mt-2">
            <?php if ($d['tipo_allegato'] === 'link'): ?>
              <a href="<?= h($d['percorso']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> Apri</a>
            <?php else: ?>
              <?php if (in_array($d['tipo_file'], $tipiVisualizzabiliInline)): ?>
                <a href="visualizza_documento.php?id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Apri nel browser senza scaricare"><i class="bi bi-eye"></i> Visualizza</a>
              <?php endif; ?>
              <a href="../<?= h($d['percorso']) ?>" download class="btn btn-sm btn-outline-secondary" title="Scarica"><i class="bi bi-download"></i></a>
            <?php endif; ?>
            <?php if (!is_sola_lettura()): ?>
            <form method="post" action="gestisci.php" onsubmit="return confirm('<?= $d['tipo_allegato']==='link' ? 'Rimuovere il link?' : 'Eliminare il documento?' ?>');">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="tab-documenti">
              <input type="hidden" name="azione" value="elimina_documento">
              <input type="hidden" name="componente_id" value="<?= $id ?>">
              <input type="hidden" name="riga_id" value="<?= $d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!is_sola_lettura()): ?>
    <div class="row g-4 mt-1">
      <div class="col-md-6">
        <h6 class="small text-muted">Carica un file</h6>
        <form method="post" action="gestisci.php" enctype="multipart/form-data" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="tab" value="tab-documenti">
          <input type="hidden" name="azione" value="carica_documento">
          <input type="hidden" name="componente_id" value="<?= $id ?>">
          <div class="col-md-12"><label class="form-label small">File (PDF, DOC, XLS, PPT, TXT, ZIP, JPG, PNG - max 10MB)</label><input type="file" name="file" class="form-control" required></div>
          <div class="col-md-7"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" placeholder="es. Datasheet"></div>
          <div class="col-md-3"><label class="form-label small">Versione</label><input type="text" name="versione" class="form-control" value="1.0"></div>
          <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Carica</button></div>
        </form>
      </div>
      <div class="col-md-6">
        <h6 class="small text-muted">Aggiungi un link</h6>
        <form method="post" action="gestisci.php" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="tab" value="tab-documenti">
          <input type="hidden" name="azione" value="aggiungi_link">
          <input type="hidden" name="componente_id" value="<?= $id ?>">
          <div class="col-md-12"><label class="form-label small">Indirizzo web</label><input type="url" name="url" class="form-control" placeholder="https://..." required></div>
          <div class="col-md-7"><label class="form-label small">Etichetta</label><input type="text" name="etichetta" class="form-control" placeholder="es. Pagina prodotto fornitore"></div>
          <div class="col-md-3"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control"></div>
          <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-link-45deg"></i> Aggiungi</button></div>
        </form>
        <div class="form-text">Puoi aggiungere più link: ripeti l'operazione per ognuno.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- GIACENZE -->
  <div class="<?= tab_pane_class('tab-giacenze', $tabAttiva) ?>" id="tab-giacenze">
    <table class="table table-sm">
      <thead><tr><th>Magazzino</th><th>Ubicazione</th><th>Lotto</th><th class="text-end">Quantità</th></tr></thead>
      <tbody>
      <?php foreach ($giacenze as $g): ?>
        <tr>
          <td><?= h($g['magazzino_desc']) ?></td>
          <td><?= h($g['ubicazione_codice'] ?? '-') ?></td>
          <td><?= $g['codice_lotto_interno'] ? '<a href="../lotti/list.php?q='.h($g['codice_lotto_interno']).'">'.h($g['codice_lotto_interno']).'</a>' : '-' ?></td>
          <td class="text-end"><?= number_format($g['quantita'],2,',','.') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$giacenze): ?><tr><td colspan="4" class="text-center text-muted">Nessuna giacenza presente.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if (!is_sola_lettura()): ?>
    <a href="../giacenze/movimento.php?componente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right"></i> Registra movimento di magazzino</a>
    <a href="../lotti/form.php?componente_id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-plus-lg"></i> Nuovo lotto</a>
    <?php endif; ?>
  </div>

  <!-- REVISIONI -->
  <div class="<?= tab_pane_class('tab-revisioni', $tabAttiva) ?>" id="tab-revisioni">
    <table class="table table-sm">
      <thead><tr><th>Revisione</th><th>Descrizione modifica</th><th>Utente</th><th>Data</th></tr></thead>
      <tbody>
      <?php foreach ($revisioni as $r): ?>
        <tr>
          <td><span class="badge bg-primary"><?= h($r['numero_revisione']) ?></span></td>
          <td><?= h($r['descrizione_modifica']) ?></td>
          <td><?= h(trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''))) ?></td>
          <td><?= date('d/m/Y H:i', strtotime($r['data_revisione'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="tab-revisioni">
      <input type="hidden" name="azione" value="nuova_revisione">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <div class="col-md-2"><label class="form-label small">Nuova revisione</label><input type="text" name="numero_revisione" class="form-control" placeholder="es. B" required></div>
      <div class="col-md-7"><label class="form-label small">Descrizione modifica</label><input type="text" name="descrizione_modifica" class="form-control" required></div>
      <div class="col-md-3"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Registra revisione</button></div>
    </form>
    <?php endif; ?>
  </div>

  <!-- DOVE E' USATO -->
  <div class="<?= tab_pane_class('tab-doveusato', $tabAttiva) ?>" id="tab-doveusato">
    <table class="table table-sm">
      <thead><tr><th>Codice</th><th>Descrizione</th></tr></thead>
      <tbody>
      <?php foreach ($doveUsato as $du): ?>
        <tr><td><a href="../bom/editor.php?componente_id=<?= $du['id'] ?>"><?= h($du['codice_interno']) ?></a></td><td><?= h($du['descrizione']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$doveUsato): ?><tr><td colspan="2" class="text-center text-muted">Questo componente non è utilizzato in nessuna distinta base.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="<?= tab_pane_class('tab-omologazione', $tabAttiva) ?>" id="tab-omologazione">
    <?php
    $etichetteStatoOm = ['non_omologato' => 'Non omologato', 'omologato' => 'Omologato', 'accettato_in_deroga' => 'Accettato in deroga'];
    $coloriStatoOm = ['non_omologato' => 'secondary', 'omologato' => 'success', 'accettato_in_deroga' => 'warning text-dark'];
    ?>
    <table class="table table-sm">
      <thead><tr><th>Produttore</th><th>Codice</th><th>Stato</th><th>Documenti</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($omologazioni as $om): ?>
        <tr>
          <td><?= h($om['produttore']) ?></td>
          <td><?= h($om['codice_produttore']) ?></td>
          <td>
            <span class="badge bg-<?= $coloriStatoOm[$om['stato']] ?>"><?= h($etichetteStatoOm[$om['stato']]) ?></span>
            <?php if ($om['richiesta_id']): ?>
              <div class="small text-muted mt-1">
                <i class="bi bi-hourglass-split"></i> Richiesta: <?= h($etichetteStatoOm[$om['stato_richiesto']] ?? $om['stato_richiesto']) ?>
                da <?= h(trim(($om['richiedente_nome'] ?? '') . ' ' . ($om['richiedente_cognome'] ?? ''))) ?: '-' ?>
                il <?= date('d/m/Y', strtotime($om['richiesta_data'])) ?>
                <?php if ($om['richiesta_motivazione']): ?> — "<?= h($om['richiesta_motivazione']) ?>"<?php endif; ?>
              </div>
              <?php if (is_admin() && !is_sola_lettura()): ?>
                <div class="d-flex gap-1 mt-1">
                  <form method="post" action="gestisci.php" onsubmit="return confirm('Approvare questa richiesta?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab" value="tab-omologazione">
                    <input type="hidden" name="azione" value="approva_richiesta_omologazione">
                    <input type="hidden" name="componente_id" value="<?= $id ?>">
                    <input type="hidden" name="richiesta_id" value="<?= $om['richiesta_id'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i> Approva</button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalRifiutaOm<?= $om['id'] ?>"><i class="bi bi-x-lg"></i> Rifiuta</button>
                </div>
                <div class="modal fade" id="modalRifiutaOm<?= $om['id'] ?>" tabindex="-1">
                  <div class="modal-dialog"><div class="modal-content">
                    <form method="post" action="gestisci.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="tab" value="tab-omologazione">
                      <input type="hidden" name="azione" value="rifiuta_richiesta_omologazione">
                      <input type="hidden" name="componente_id" value="<?= $id ?>">
                      <input type="hidden" name="richiesta_id" value="<?= $om['richiesta_id'] ?>">
                      <div class="modal-header"><h6 class="modal-title">Rifiuta richiesta</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                      <div class="modal-body">
                        <label class="form-label small">Motivo del rifiuto *</label>
                        <textarea name="nota_rifiuto" class="form-control" rows="3" required></textarea>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button class="btn btn-danger">Rifiuta</button>
                      </div>
                    </form>
                  </div></div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php $docCollegati = $documentiOmologazioni[$om['id']] ?? []; ?>
            <?php foreach ($docCollegati as $dc): ?>
              <div>
                <?php if ($dc['tipo_allegato'] === 'link'): ?>
                  <a href="<?= h($dc['percorso']) ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i> <?= h($dc['nome_file']) ?></a>
                <?php else: ?>
                  <a href="../<?= h($dc['percorso']) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i> <?= h($dc['nome_file']) ?></a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$docCollegati): ?><span class="text-muted">-</span><?php endif; ?>
            <?php if (!is_sola_lettura() && $documenti): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-bs-toggle="modal" data-bs-target="#modalDocumentiOm<?= $om['id'] ?>"><i class="bi bi-paperclip"></i> Gestisci</button>
              <div class="modal fade" id="modalDocumentiOm<?= $om['id'] ?>" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                  <form method="post" action="gestisci.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab" value="tab-omologazione">
                    <input type="hidden" name="azione" value="aggiorna_documenti_omologazione">
                    <input type="hidden" name="componente_id" value="<?= $id ?>">
                    <input type="hidden" name="riga_id" value="<?= $om['id'] ?>">
                    <div class="modal-header"><h6 class="modal-title">Documenti collegati — <?= h($om['produttore']) ?> <?= h($om['codice_produttore']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                      <?php $idDocCollegati = array_column($docCollegati, 'id'); ?>
                      <?php foreach ($documenti as $doc): ?>
                        <div class="form-check">
                          <input type="checkbox" name="documenti[]" value="<?= $doc['id'] ?>" class="form-check-input" id="docOm<?= $om['id'] ?>_<?= $doc['id'] ?>" <?= in_array($doc['id'], $idDocCollegati, true) ? 'checked' : '' ?>>
                          <label class="form-check-label" for="docOm<?= $om['id'] ?>_<?= $doc['id'] ?>"><?= h($doc['nome_file']) ?> <?php if ($doc['descrizione']): ?><span class="text-muted small">(<?= h($doc['descrizione']) ?>)</span><?php endif; ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                      <button class="btn btn-primary">Salva</button>
                    </div>
                  </form>
                </div></div>
              </div>
            <?php endif; ?>
          </td>
          <td class="small"><?= h($om['note']) ?: '-' ?></td>
          <td>
            <?php if (!is_sola_lettura()): ?>
              <?php if (!$om['richiesta_id']): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCambioStato<?= $om['id'] ?>"><i class="bi bi-arrow-repeat"></i> Richiedi cambio stato</button>
              <?php endif; ?>
              <form method="post" action="gestisci.php" class="d-inline" onsubmit="return confirm('Rimuovere questo codice omologazione?');">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="tab-omologazione">
                <input type="hidden" name="azione" value="elimina_omologazione">
                <input type="hidden" name="componente_id" value="<?= $id ?>">
                <input type="hidden" name="riga_id" value="<?= $om['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (!is_sola_lettura() && !$om['richiesta_id']): ?>
        <div class="modal fade" id="modalCambioStato<?= $om['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content">
            <form method="post" action="gestisci.php">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="tab-omologazione">
              <input type="hidden" name="azione" value="richiedi_cambio_stato_omologazione">
              <input type="hidden" name="componente_id" value="<?= $id ?>">
              <input type="hidden" name="riga_id" value="<?= $om['id'] ?>">
              <div class="modal-header"><h6 class="modal-title">Richiedi cambio stato — <?= h($om['produttore']) ?> <?= h($om['codice_produttore']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body">
                <label class="form-label small">Nuovo stato richiesto *</label>
                <select name="nuovo_stato" class="form-select mb-3" required>
                  <?php foreach ($etichetteStatoOm as $val => $lbl): if ($val === $om['stato']) continue; ?>
                    <option value="<?= $val ?>"><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="form-label small">Motivazione *</label>
                <textarea name="motivazione" class="form-control" rows="3" required placeholder="Perché richiedi questo cambio di stato (es. esito test di qualifica, riferimento a deroga concessa dal cliente, ecc.)"></textarea>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button class="btn btn-primary">Invia richiesta</button>
              </div>
            </form>
          </div></div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$omologazioni): ?><tr><td colspan="6" class="text-center text-muted py-3">Nessun codice omologazione registrato.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <?php if (!is_sola_lettura()): ?>
    <form method="post" action="gestisci.php" class="row g-2 align-items-end mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="tab-omologazione">
      <input type="hidden" name="azione" value="aggiungi_omologazione">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <div class="col-md-3"><label class="form-label small">Produttore</label><input type="text" name="produttore" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label small">Codice</label><input type="text" name="codice_produttore" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label small">Note</label><input type="text" name="note" class="form-control"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Aggiungi</button></div>
    </form>
    <p class="small text-muted mt-2">Un nuovo codice parte sempre come "Non omologato". Ogni cambio verso un altro stato richiede una richiesta approvata da un amministratore.</p>
    <?php endif; ?>

    <?php if ($storicoOmologazioni): ?>
    <h6 class="mt-4"><i class="bi bi-clock-history"></i> Storico richieste</h6>
    <table class="table table-sm">
      <thead><tr><th>Data</th><th>Codice</th><th>Da</th><th>A</th><th>Esito</th><th>Richiedente</th><th>Decisione</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($storicoOmologazioni as $sr): ?>
        <tr>
          <td class="small"><?= date('d/m/Y H:i', strtotime($sr['data_richiesta'])) ?></td>
          <td class="small"><?= h($sr['produttore']) ?> <?= h($sr['codice_produttore']) ?></td>
          <td><?= h($etichetteStatoOm[$sr['stato_precedente']] ?? $sr['stato_precedente']) ?></td>
          <td><?= h($etichetteStatoOm[$sr['stato_richiesto']] ?? $sr['stato_richiesto']) ?></td>
          <td>
            <?php $coloriEsito = ['in_attesa' => 'info', 'approvata' => 'success', 'rifiutata' => 'danger']; ?>
            <span class="badge bg-<?= $coloriEsito[$sr['stato_richiesta']] ?>"><?= ucfirst(str_replace('_', ' ', $sr['stato_richiesta'])) ?></span>
          </td>
          <td class="small"><?= h(trim(($sr['richiedente_nome'] ?? '') . ' ' . ($sr['richiedente_cognome'] ?? ''))) ?: '-' ?></td>
          <td class="small">
            <?php if ($sr['data_decisione']): ?>
              <?= h(trim(($sr['approvatore_nome'] ?? '') . ' ' . ($sr['approvatore_cognome'] ?? ''))) ?: '-' ?> — <?= date('d/m/Y H:i', strtotime($sr['data_decisione'])) ?>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td class="small"><?= h($sr['motivazione']) ?: '' ?><?php if ($sr['note_decisione']): ?><br><em><?= h($sr['note_decisione']) ?></em><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<?php if (is_admin()): ?>
<div class="card p-4 mt-3 border-danger-subtle">
  <h6 class="text-danger"><i class="bi bi-exclamation-triangle"></i> Gestione componente</h6>
  <p class="small text-muted mb-3">
    Giacenza attuale: <strong><?= number_format($giacenzaTot, 2, ',', '.') ?> <?= h($c['um_codice']) ?></strong>.
    L'eliminazione definitiva è possibile solo se la giacenza è a zero; se il componente è comunque referenziato altrove (distinta base, lotti, movimenti storici, ordini di acquisto) l'eliminazione verrà comunque bloccata a tutela dello storico — in quel caso usa la disattivazione.
  </p>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($c['attivo']): ?>
    <form method="post" action="gestisci.php" onsubmit="return confirm('Disattivare questo componente? Non comparirà più negli elenchi attivi, ma tutti i dati restano.');">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="disattiva_componente">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <button class="btn btn-outline-warning"><i class="bi bi-eye-slash"></i> Disattiva componente</button>
    </form>
    <?php else: ?>
    <form method="post" action="gestisci.php">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="riattiva_componente">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <button class="btn btn-outline-success"><i class="bi bi-eye"></i> Riattiva componente</button>
    </form>
    <?php endif; ?>

    <form method="post" action="gestisci.php" onsubmit="return confirm('Eliminare DEFINITIVAMENTE questo componente? L\'operazione non è reversibile.');">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="elimina_componente">
      <input type="hidden" name="componente_id" value="<?= $id ?>">
      <button class="btn btn-outline-danger" <?= abs($giacenzaTot) > 0.0001 ? 'disabled title="Giacenza non a zero"' : '' ?>><i class="bi bi-trash"></i> Elimina definitivamente</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!is_sola_lettura()): ?>
<div class="modal fade" id="modalDuplica" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="gestisci.php">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="duplica_componente">
        <input type="hidden" name="componente_id" value="<?= $id ?>">
        <div class="modal-header">
          <h6 class="modal-title"><i class="bi bi-files"></i> Duplica componente</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">
            Verranno copiati i dati anagrafici, le unità di misura aggiuntive e i fornitori collegati.
            <strong>Non</strong> vengono copiati: giacenze (partono da zero), distinta base, documenti allegati e componenti alternativi.
          </p>
          <label class="form-label small">Nuovo codice interno *</label>
          <input type="text" name="nuovo_codice_interno" class="form-control" required placeholder="es. <?= h($c['codice_interno']) ?>-B" autofocus>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button class="btn btn-primary"><i class="bi bi-files"></i> Duplica</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
<?php if (!is_sola_lettura()): ?>
creaSelettoreComponente(document.getElementById('alternativoContainer'), {
  nomeCampo: 'componente_alternativo_id',
  escludiId: <?= (int) $id ?>
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
