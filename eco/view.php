<?php
$titolo_pagina = 'Dettaglio ECO';
require_once __DIR__ . '/../includes/header.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT e.*, r.nome richiedente_nome, r.cognome richiedente_cognome,
                        a.nome approvatore_nome, a.cognome approvatore_cognome
                        FROM eco e
                        LEFT JOIN utenti r ON r.id = e.richiedente_id
                        LEFT JOIN utenti a ON a.id = e.approvatore_id
                        WHERE e.id = ? AND e.azienda_id = ?");
$stmt->execute([$id, azienda_id()]);
$eco = $stmt->fetch();
if (!$eco) { die('ECO non trovato.'); }

// dati dell'oggetto collegato (componente o bom)
if ($eco['tipo_oggetto'] === 'componente') {
    $stmt = $pdo->prepare("SELECT id, codice_interno, descrizione FROM componenti WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$eco['oggetto_id'], azienda_id()]);
    $oggetto = $stmt->fetch();
    $linkOggetto = $oggetto ? '../componenti/view.php?id=' . $oggetto['id'] : null;
    $etichettaOggetto = $oggetto ? $oggetto['codice_interno'] . ' - ' . $oggetto['descrizione'] : '(componente non trovato)';
} else {
    $stmt = $pdo->prepare("SELECT b.id, b.revisione, c.id componente_id, c.codice_interno, c.descrizione FROM bom b JOIN componenti c ON c.id=b.componente_padre_id WHERE b.id = ? AND b.azienda_id = ?");
    $stmt->execute([$eco['oggetto_id'], azienda_id()]);
    $oggetto = $stmt->fetch();
    $linkOggetto = $oggetto ? '../bom/editor.php?componente_id=' . $oggetto['componente_id'] . '&bom_id=' . $oggetto['id'] : null;
    $etichettaOggetto = $oggetto ? 'BOM di ' . $oggetto['codice_interno'] . ' - ' . $oggetto['descrizione'] . ' (rev. ' . $oggetto['revisione'] . ')' : '(BOM non trovata)';
}

$storico = $pdo->prepare("SELECT s.*, u.nome, u.cognome FROM eco_storico s LEFT JOIN utenti u ON u.id = s.utente_id WHERE s.eco_id = ? ORDER BY s.data_evento DESC");
$storico->execute([$id]);
$storico = $storico->fetchAll();

$modifiche = [];
$bomRisultante = null;
if ($eco['tipo_oggetto'] === 'bom') {
    $stmt = $pdo->prepare("SELECT m.*, ce.codice_interno ce_codice, ce.descrizione ce_descrizione,
                            cn.codice_interno cn_codice, cn.descrizione cn_descrizione, um.codice um_codice
                            FROM eco_modifiche_bom m
                            LEFT JOIN componenti ce ON ce.id = m.componente_id
                            LEFT JOIN componenti cn ON cn.id = m.componente_nuovo_id
                            LEFT JOIN unita_misura um ON um.id = m.unita_misura_id
                            WHERE m.eco_id = ? ORDER BY m.ordinamento, m.id");
    $stmt->execute([$id]);
    $modifiche = $stmt->fetchAll();

    if ($eco['bom_risultante_id']) {
        $stmt = $pdo->prepare("SELECT b.id, b.revisione, c.id componente_id, c.codice_interno FROM bom b JOIN componenti c ON c.id=b.componente_padre_id WHERE b.id = ? AND b.azienda_id = ?");
        $stmt->execute([$eco['bom_risultante_id'], azienda_id()]);
        $bomRisultante = $stmt->fetch();
    }
}

$etichetteStato = ['bozza'=>'Bozza','in_revisione'=>'In revisione','approvato'=>'Approvato','rifiutato'=>'Rifiutato','implementato'=>'Implementato','annullato'=>'Annullato'];
$coloriStato = ['bozza'=>'secondary','in_revisione'=>'info','approvato'=>'primary','rifiutato'=>'danger','implementato'=>'success','annullato'=>'dark'];
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h4><i class="bi bi-pencil-square"></i> <?= h($eco['numero_eco']) ?> <span class="badge bg-<?= $coloriStato[$eco['stato']] ?>"><?= h($etichetteStato[$eco['stato']]) ?></span></h4>
    <p class="mb-0"><?= h($eco['titolo']) ?></p>
  </div>
  <div class="text-end">
    <?php if ($eco['stato'] === 'bozza' && !is_sola_lettura()): ?><a href="form.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Modifica</a><?php endif; ?>
    <a href="list.php" class="btn btn-outline-secondary btn-sm">Elenco ECO</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_msg'])): ?>
  <div class="alert alert-<?= h($_SESSION['flash_type'] ?? 'info') ?>"><?= h($_SESSION['flash_msg']) ?></div>
  <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-8">
    <div class="card p-4">
      <table class="table table-sm mb-0">
        <tr><th style="width:220px;">Oggetto della modifica</th><td><?= $linkOggetto ? '<a href="' . h($linkOggetto) . '">' . h($etichettaOggetto) . '</a>' : h($etichettaOggetto) ?></td></tr>
        <tr><th>Priorità</th><td><?= h(ucfirst($eco['priorita'])) ?></td></tr>
        <?php if ($eco['tipo_oggetto'] === 'bom' && !$bomRisultante): ?>
        <tr><th>Nuova revisione proposta</th><td><?= h($eco['revisione_proposta']) ?: '<span class="text-muted">(calcolata automaticamente se non specificata)</span>' ?></td></tr>
        <?php endif; ?>
        <tr><th>Descrizione modifica</th><td style="white-space:pre-line;"><?= h($eco['descrizione']) ?: '-' ?></td></tr>
        <tr><th>Motivazione</th><td style="white-space:pre-line;"><?= h($eco['motivazione']) ?: '-' ?></td></tr>
        <tr><th>Impatto sui costi</th><td style="white-space:pre-line;"><?= h($eco['impatto_costi']) ?: '-' ?></td></tr>
        <tr><th>Impatto su disponibilità/produzione</th><td style="white-space:pre-line;"><?= h($eco['impatto_disponibilita']) ?: '-' ?></td></tr>
        <tr><th>Richiedente</th><td><?= h(trim(($eco['richiedente_nome'] ?? '') . ' ' . ($eco['richiedente_cognome'] ?? ''))) ?: '-' ?> — <?= date('d/m/Y H:i', strtotime($eco['data_richiesta'])) ?></td></tr>
        <?php if ($eco['data_decisione']): ?>
        <tr><th>Decisione</th><td><?= h(trim(($eco['approvatore_nome'] ?? '') . ' ' . ($eco['approvatore_cognome'] ?? ''))) ?: '-' ?> — <?= date('d/m/Y H:i', strtotime($eco['data_decisione'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($eco['note_decisione']): ?>
        <tr><th>Note decisione</th><td style="white-space:pre-line;"><?= h($eco['note_decisione']) ?></td></tr>
        <?php endif; ?>
        <?php if ($eco['data_implementazione']): ?>
        <tr><th>Implementato il</th><td><?= date('d/m/Y H:i', strtotime($eco['data_implementazione'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($bomRisultante): ?>
        <tr><th>Nuova revisione BOM creata</th><td><a href="../bom/editor.php?componente_id=<?= $bomRisultante['componente_id'] ?>&bom_id=<?= $bomRisultante['id'] ?>"><?= h($bomRisultante['codice_interno']) ?> - rev. <?= h($bomRisultante['revisione']) ?></a></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <?php if ($eco['tipo_oggetto'] === 'bom'): ?>
    <div class="card p-4 mt-3">
      <h6><i class="bi bi-list-check"></i> Modifiche <?= in_array($eco['stato'], ['implementato'], true) ? 'applicate' : 'proposte' ?></h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Tipo</th><th>Componente</th><th>Dettaglio</th></tr></thead>
        <tbody>
        <?php $etichetteTipoMod = ['aggiungi'=>'Aggiungi','rimuovi'=>'Rimuovi','sostituisci'=>'Sostituisci','modifica_quantita'=>'Modifica quantità/Designatore']; ?>
        <?php foreach ($modifiche as $m): ?>
          <tr>
            <td><span class="badge bg-secondary"><?= h($etichetteTipoMod[$m['tipo_modifica']] ?? $m['tipo_modifica']) ?></span></td>
            <td>
              <?php if ($m['tipo_modifica'] === 'aggiungi'): ?>
                <?= h($m['cn_codice']) ?> - <?= h($m['cn_descrizione']) ?>
              <?php elseif ($m['tipo_modifica'] === 'sostituisci'): ?>
                <?= h($m['ce_codice']) ?> → <?= h($m['cn_codice']) ?>
              <?php else: ?>
                <?= h($m['ce_codice']) ?> - <?= h($m['ce_descrizione']) ?>
              <?php endif; ?>
            </td>
            <td class="small text-muted">
              <?php if (in_array($m['tipo_modifica'], ['aggiungi','sostituisci','modifica_quantita'], true) && $m['quantita'] !== null): ?>
                Quantità <?= number_format($m['quantita'], 3, ',', '.') ?> <?= h($m['um_codice']) ?>
              <?php endif; ?>
              <?php if ($m['designatore']): ?>, designatore <?= h($m['designatore']) ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$modifiche): ?><tr><td colspan="3" class="text-center text-muted py-2">Nessuna modifica specificata.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="card p-4 mt-3">
      <h6><i class="bi bi-clock-history"></i> Storico</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Data</th><th>Da</th><th>A</th><th>Utente</th><th>Nota</th></tr></thead>
        <tbody>
        <?php foreach ($storico as $s): ?>
          <tr>
            <td class="small"><?= date('d/m/Y H:i', strtotime($s['data_evento'])) ?></td>
            <td><?= $s['stato_precedente'] ? h($etichetteStato[$s['stato_precedente']] ?? $s['stato_precedente']) : '-' ?></td>
            <td><?= h($etichetteStato[$s['stato_nuovo']] ?? $s['stato_nuovo']) ?></td>
            <td><?= h(trim(($s['nome'] ?? '') . ' ' . ($s['cognome'] ?? ''))) ?: '-' ?></td>
            <td><?= h($s['nota']) ?: '-' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-md-4">
    <?php if (!is_sola_lettura()): ?>
    <div class="card p-4">
      <h6>Azioni</h6>

      <?php if ($eco['stato'] === 'bozza'): ?>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="invia_revisione">
          <button class="btn btn-info w-100"><i class="bi bi-send"></i> Invia in revisione</button>
        </form>
      <?php endif; ?>

      <?php if ($eco['stato'] === 'in_revisione' && is_admin()): ?>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="approva">
          <button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= $eco['tipo_oggetto']==='bom' ? 'Approva e implementa' : 'Approva' ?></button>
        </form>
        <?php if ($eco['tipo_oggetto']==='bom'): ?><p class="small text-muted">Le modifiche verranno applicate automaticamente alla distinta base al momento dell'approvazione.</p><?php endif; ?>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="rifiuta">
          <label class="form-label small">Motivo del rifiuto</label>
          <textarea name="nota" class="form-control form-control-sm mb-2" rows="2" required></textarea>
          <button class="btn btn-outline-danger w-100"><i class="bi bi-x-lg"></i> Rifiuta</button>
        </form>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="riporta_bozza">
          <button class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise"></i> Riporta in bozza</button>
        </form>
      <?php endif; ?>

      <?php if ($eco['stato'] === 'rifiutato'): ?>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="riporta_bozza">
          <button class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise"></i> Riporta in bozza per correggere</button>
        </form>
      <?php endif; ?>

      <?php if ($eco['stato'] === 'approvato'): ?>
        <form method="post" action="save.php" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="implementa">
          <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> Segna come implementato</button>
        </form>
      <?php endif; ?>

      <?php if (!in_array($eco['stato'], ['implementato','annullato'], true)): ?>
        <form method="post" action="save.php" onsubmit="return confirm('Annullare questo ECO?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="annulla">
          <button class="btn btn-outline-dark w-100 btn-sm"><i class="bi bi-slash-circle"></i> Annulla ECO</button>
        </form>
      <?php endif; ?>

      <?php if ($eco['stato'] === 'in_revisione' && !is_admin()): ?>
        <p class="small text-muted mb-0">In attesa di approvazione da parte di un amministratore.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
