<?php
require_once __DIR__ . '/../includes/functions.php';

$lingua = $_GET['lingua'] ?? '';
if (!isset(LINGUE_DISPONIBILI[$lingua])) {
    $lingua = 'it';
}

$_SESSION['lingua'] = $lingua;
setcookie('lingua', $lingua, time() + 60 * 60 * 24 * 365, '/');

if (is_logged_in()) {
    $pdo->prepare("UPDATE utenti SET lingua = ? WHERE id = ?")->execute([$lingua, $_SESSION['user_id']]);
}

// torna alla pagina da cui si è cambiata lingua: usa il parametro esplicito se dato,
// altrimenti il referer (comodo per i link nel menu, che non devono costruirlo a mano)
$ritorno = $_GET['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($ritorno === '' || str_contains($ritorno, '://') && !str_contains($ritorno, $_SERVER['HTTP_HOST'] ?? '__nessuno__')) {
    $ritorno = base_url('index.php');
}
header('Location: ' . $ritorno);
