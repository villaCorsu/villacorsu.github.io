<?php
/**
 * Configuration — Livre d'or Villa Corsu
 * ⚠️ Pensez à changer ADMIN_PASSWORD avant la mise en ligne !
 */

define('ADMIN_PASSWORD', 'Peugeot06!ftp');   // mot de passe pour /moderate.php
define('DATA_FILE', __DIR__ . '/data/avis.json');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
define('MAX_PHOTOS', 6);                      // nb max de photos par avis
define('MAX_FILE_SIZE', 8 * 1024 * 1024);     // 8 Mo par photo
define('MAX_IMAGE_WIDTH', 1600);              // redimensionnement (si GD dispo)
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp']);

// Clé utilisée pour signer la question anti-robot (voir functions.php).
// Dérivée du mot de passe admin : pas besoin de la stocker ailleurs.
define('CAPTCHA_SECRET', hash('sha256', ADMIN_PASSWORD . '::livre-dor-villa-corsu'));

// Clé utilisée pour signer le cookie de connexion admin (voir moderate.php).
// Change automatiquement si vous changez ADMIN_PASSWORD (déconnecte tout le monde).
define('ADMIN_TOKEN_SECRET', hash('sha256', ADMIN_PASSWORD . '::livre-dor-admin-token'));

// Sur certains hébergements mutualisés (dont Free), le dossier de session
// PHP par défaut n'est pas inscriptible pour les comptes perso, ce qui
// empêche les sessions de fonctionner (utile pour l'espace admin).
// On utilise donc un dossier dédié, à l'intérieur de notre propre espace.
$dossierSessionsLivreOr = __DIR__ . '/sessions';
if (!is_dir($dossierSessionsLivreOr)) {
    @mkdir($dossierSessionsLivreOr, 0755, true);
}
if (is_dir($dossierSessionsLivreOr) && is_writable($dossierSessionsLivreOr)) {
    session_save_path($dossierSessionsLivreOr);
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
date_default_timezone_set('Europe/Paris');
