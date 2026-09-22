<?php
$titolo_pagina = 'Categorie';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'salva') {
            $id = (int) ($_POST['id'] ?? 0);
            $codice = trim($_POST['codice']) ?: null;
            $nome = trim($_POST['nome']);
            $padreId = $_POST['categoria_padre_id'] ?: null;
            if ($padreId && $id && (int)$padreId === $id) { $padreId = null; }

            if ($nome === '') {
                $errore = 'Il nome è obbligatorio.';
            } elseif ($id) {
                $pdo->prepare("UPDATE categorie_componenti SET codice=?, nome=?, categoria_padre_id=? WHERE id=? AND azienda_id=?")->execute([$codice, $nome, $padreId, $id, azienda_id()]);
            } else {
                $pdo->prepare("INSERT INTO categorie_componenti (azienda_id, codice, nome, categoria_padre_id) VALUES (?,?,?,?)")->execute([azienda_id(), $codice, $nome, $padreId]);
            }
            if (!$errore) {
                $_SESSION['flash_msg'] = 'Categoria salvata.'; $_SESSION['flash_type'] = 'success';
                header('Location: categorie.php'); exit;
            }
        } elseif ($azione === 'imposta_predefinita') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE categorie_componenti SET is_predefinita = 0 WHERE azienda_id = ?")->execute([azienda_id()]);
            $pdo->prepare("UPDATE categorie_componenti SET is_predefinita = 1 WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
            $pdo->commit();
            header('Location: categorie.php'); exit;
        } elseif ($azione === 'elimina') {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM componenti WHERE categoria_id = ? AND azienda_id = ?");
            $stmt->execute([(int) $_POST['id'], azienda_id()]);
            if ($stmt->fetch()['c'] > 0) {
                $_SESSION['flash_msg'] = 'Impossibile eliminare: la categoria è usata da uno o più componenti.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $pdo->prepare("DELETE FROM categorie_componenti WHERE id = ? AND azienda_id = ?")->execute([(int) $_POST['id'], azienda_id()]);
                $_SESSION['flash_msg'] = 'Categoria eliminata.'; $_SESSION['flash_type'] = 'success';
            }
            header('Location: categorie.php'); exit;
        }
    } catch (PDOException $e) {
        $errore = str_contains($e->getMessage(), 'Duplicate') ? 'Codice già esistente.' : 'Errore: ' . $e->getMessage();
    }
}

$modificaId = (int) ($_GET['modifica'] ?? 0);
$inModifica = null;
if ($modificaId) {
    $stmt = $pdo->prepare("SELECT * FROM categorie_componenti WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$modificaId, azienda_id()]);
    $inModifica = $stmt->fetch();
}

$categorie = $pdo->prepare("SELECT c.*, p.nome nome_padre, (SELECT COUNT(*) FROM componenti co WHERE co.categoria_id = c.id) n_componenti
                           FROM categorie_componenti c LEFT JOIN categorie_componenti p ON p.id = c.categoria_padre_id
                           WHERE c.azienda_id = ? ORDER BY c.codice IS NULL, c.codice, c.nome");
$categorie->execute([azienda_id()]); $categorie = $categorie->fetchAll();
$tutteCategorie = $pdo->prepare("SELECT * FROM categorie_componenti WHERE azienda_id = ? ORDER BY codice IS NULL, codice, nome");
$tutteCategorie->execute([azienda_id()]); $tutteCategorie = $tutteCategorie->fetchAll();
?>
<h4><i class="bi bi-folder2-open"></i> Categorie componenti</h4>
<p class="text-muted">Categorie merceologiche usate per organizzare i componenti (es. Resistori, Condensatori, Connettori, PCB...). Puoi creare una gerarchia impostando una categoria padre.</p>

<?php if ($errore): ?><div class="alert alert-danger"><?= h($errore) ?></div><?php endif; ?>

<div class="card">
<table class="table table-hover mb-0">
  <thead><tr><th>Codice</th><th>Nome</th><th>Categoria padre</th><th>Default</th><th>N° componenti</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($categorie as $c): ?>
    <tr>
      <td><?= h($c['codice']) ?: '-' ?></td>
      <td><?= h($c['nome']) ?></td>
      <td><?= h($c['nome_padre'] ?? '-') ?></td>
      <td>
        <?php if ($c['is_predefinita']): ?>
          <span class="badge bg-success">Predefinita</span>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="imposta_predefinita">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary">Imposta default</button>
          </form>
        <?php endif; ?>
      </td>
      <td><?= $c['n_componenti'] ?></td>
      <td class="text-end">
        <a href="?modifica=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
        <?php if ($c['n_componenti'] == 0): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questa categoria?');">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="elimina">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$categorie): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessuna categoria configurata.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="card p-4 mt-3">
  <h6><?= $inModifica ? 'Modifica categoria' : 'Nuova categoria' ?></h6>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="azione" value="salva">
    <input type="hidden" name="id" value="<?= $inModifica['id'] ?? 0 ?>">
    <div class="col-md-2"><label class="form-label small">Codice</label><input type="text" name="codice" class="form-control" maxlength="30" value="<?= h($inModifica['codice'] ?? '') ?>" placeholder="es. RES"></div>
    <div class="col-md-4"><label class="form-label small">Nome</label><input type="text" name="nome" class="form-control" required value="<?= h($inModifica['nome'] ?? '') ?>"></div>
    <div class="col-md-4">
      <label class="form-label small">Categoria padre (opzionale)</label>
      <select name="categoria_padre_id" class="form-select">
        <option value="">-- Nessuna --</option>
        <?php foreach ($tutteCategorie as $cat):
          if ($inModifica && $cat['id'] == $inModifica['id']) continue; ?>
        <option value="<?= $cat['id'] ?>" <?= (!empty($inModifica['categoria_padre_id']) && $inModifica['categoria_padre_id']==$cat['id']) ? 'selected' : '' ?>><?= h($cat['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-save"></i></button></div>
  </form>
  <?php if ($inModifica): ?><a href="categorie.php" class="small">Annulla modifica</a><?php endif; ?>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3">Torna all'amministrazione</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
