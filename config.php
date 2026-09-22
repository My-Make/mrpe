<?php
// ============================================================
// CONFIGURAZIONE - modifica questi valori con i tuoi parametri
// ============================================================
define('DB_HOST', 'sql.mymake.it');
define('DB_NAME', 'mymakeit67973');
define('DB_USER', 'mymakeit67973');
define('DB_PASS', 'myma43470');

define('UPLOAD_DIR', __DIR__ . '/uploads/documenti/');
define('UPLOAD_URL', 'uploads/documenti/');
define('IMG_UPLOAD_DIR', __DIR__ . '/uploads/immagini/');
define('IMG_UPLOAD_URL', 'uploads/immagini/');
define('LOGO_UPLOAD_DIR', __DIR__ . '/uploads/loghi/');
define('LOGO_UPLOAD_URL', 'uploads/loghi/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
// Estensioni ammesse per gli allegati tecnici del componente (datasheet, disegni, ecc.)
define('ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'dwg', 'step', 'stp']);
// Estensioni ammesse per l'immagine principale del componente
define('ALLOWED_IMG_EXT', ['jpg', 'jpeg', 'png', 'webp']);

// Se true, imposta il cookie di sessione come "secure" (richiede HTTPS)
define('FORCE_HTTPS_COOKIE', false);

// ------------------------------------------------------------
// BASE URL DELL'APPLICAZIONE (calcolata automaticamente)
// Confronta la cartella reale di questo file (radice dell'app)
// con la document root del server, per ottenere il prefisso
// corretto sia se l'app è installata nella root del dominio,
// sia se è installata in una sottocartella (es. /mrp_app).
// ------------------------------------------------------------
if (!defined('APP_BASE_URL')) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(__DIR__);
    $baseUrl = '';
    if ($docRoot && $appRoot && str_starts_with($appRoot, $docRoot)) {
        $baseUrl = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $baseUrl = rtrim($baseUrl, '/');
    }
    define('APP_BASE_URL', $baseUrl); // es. '' oppure '/mrp_app'
}


date_default_timezone_set('Europe/Rome');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => FORCE_HTTPS_COOKIE,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Errore di connessione al database: ' . htmlspecialchars($e->getMessage()));
}
