<?php
$titolo_pagina = 'Tipi componente';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'salva') {
            $id = (int) ($_POST['id'] ?? 0);
            $codice = trim($_POST['codice']);
            $descrizione = trim($_POST['descrizione']);
            $haDistintaBase = isset($_POST['ha_distinta_base']) ? 1 : 0;
            $ordinamento = (int) ($_POST['ordinamento'] ?: 0);

            if ($codice === '' || $descrizione === '') {
                $errore = 'Codice e descrizione sono obbligatori.';
            } elseif ($id) {
                $pdo->prepare("UPDATE tipi_componente SET codice=?, descrizione=?, ha_distinta_base=?, ordinamento=? WHERE id=? AND azienda_id=?")
                    ->execute([$codice, $descrizione, $haDistintaBase, $ordinamento, $id, azienda_id()]);
            } else {
                $pdo->prepare("INSERT INTO tipi_componente (azienda_id, codice, descrizione, ha_distinta_base, ordinamento) VALUES (?,?,?,?,?)")
                    ->execute([azienda_id(), $codice, $descrizione, $haDistintaBase, $ordinamento]);
            }
            if (!$errore) {
                $_SESSION['flash_msg'] = 'Tipo componente salvato.'; $_SESSION['flash_type'] = 'success';
                header('Location: tipi_componente.php'); exit;
            }
        } elseif ($azione === 'imposta_predefinito') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tipi_componente SET is_predefinito = 0 WHERE azienda_id = ?")->execute([azienda_id()]);
            $pdo->prepare("UPDATE tipi_componente SET is_predefinito = 1 WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            $pdo->commit();
            header('Location: tipi_componente.php'); exit;
        } elseif ($azione === 'attiva_disattiva') {
            $pdo->prepare("UPDATE tipi_componente SET attivo = 1 - attivo WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            header('Location: tipi_componente.php'); exit;
        } elseif ($azione === 'elimina') {
            // consentito solo se nessun componente usa questo tipo
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE tipo_componente_id = ? AND azienda_id = ?");
            $stmt->execute([(int) $_POST['id'], azienda_id()]);
            if ($stmt->fetch()['c'] > 0) {
                $_SESSION['flash_msg'] = 'Impossibile eliminare: il tipo è usato da uno o più componenti. Disattivalo invece.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $pdo->prepare("DELETE FROM tipi_componente WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
                $_SESSION['flash_msg'] = 'Tipo componente eliminato.'; $_SESSION['flash_type'] = 'success';
            }
            header('Location: tipi_componente.php'); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM tipi_componente WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$modificaId, azienda_id()]);
    $inModifica = $stmt->fetch();
}

$tipi = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM componenti c WHERE c.tipo_componente_id = t.id) n_componenti
                      FROM tipi_componente t WHERE t.azienda_id = ? ORDER BY t.ordinamento, t.descrizione");
$tipi->execute([azienda_id()]); $tipi = $tipi->fetchAll();
?>
<h4><i class="bi bi-tags"></i> Tipi componente</h4>
<p class="text-muted">Definiscono la natura strutturale di un componente. Se "ha distinta base" è attivo, il componente potrà avere una propria BOM esplodibile a livello superiore (come i tradizionali Semilavorato/Prodotto finito); altrimenti sarà considerato un elemento base.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Descrizione</th><th>Distinta base</th><th>Default</th><th>N° componenti</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tipi as $t): ?>
    <tr class="<?= $t['attivo'] ? '' : 'text-muted' ?>">
      <td><?= h($t['codice']) ?></td>
      <td><?= h($t['descrizione']) ?></td>
      <td><?= $t['ha_distinta_base'] ? '<span class="badge bg-info text-dark">Sì</span>' : '<span class="badge bg-light text-dark">No</span>' ?></td>
      <td>
        <?php if ($t['is_predefinito']): ?>
          <span class="badge bg-success">Predefinito</span>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="imposta_predefinito">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary">Imposta default</button>
          </form>
        <?php endif; ?>
      </td>
      <td><?= $t['n_componenti'] ?></td>
      <td>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="attiva_disattiva">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm <?= $t['attivo'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>"><?= $t['attivo'] ? 'Attivo' : 'Disattivo' ?></button>
        </form>
      </td>
      <td class="text-end">
        <a href="?modifica=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <?php if ($t['n_componenti'] == 0): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Eliminare definitivamente questo tipo?');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="elimina">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tipi): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessun tipo configurato.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><?= $inModifica ? 'Modifica tipo componente' : 'Nuovo tipo componente' ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="salva">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?? 0 ?>">
    <div class="col-md-3"><label class="form-label small">Codice</label><input type="text" name="codice" class="form-control" required value="<?= h($inModifica['codice'] ?? '') ?>" placeholder="es. accessorio"></div>
    <div class="col-md-4"><label class="form-label small">Descrizione</label><input type="text" name="descrizione" class="form-control" required value="<?= h($inModifica['descrizione'] ?? '') ?>" placeholder="es. Accessorio"></div>
    <div class="col-md-2"><label class="form-label small">Ordinamento</label><input type="number" name="ordinamento" class="form-control" value="<?= h((string)($inModifica['ordinamento'] ?? 0)) ?>"></div>
    <div class="col-md-2 form-check mb-2">
      <input type="checkbox" name="ha_distinta_base" class="form-check-input" id="hdb" <?= !empty($inModifica['ha_distinta_base']) ? 'checked' : '' ?>>
      <label class="form-check-label small" for="hdb">Ha distinta base</label>
    </div>
    <div class="col-md-1"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <?php if ($inModifica): ?><a href="tipi_componente.php" class="small">Annulla modifica</a><?php endif; ?>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
