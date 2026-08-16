<?php
require_once __DIR__ . '/config.php';

/** Lit tous les avis depuis le fichier JSON */
function lireAvis() {
    if (!file_exists(DATA_FILE)) return [];
    $contenu = file_get_contents(DATA_FILE);
    $data = json_decode($contenu, true);
    return is_array($data) ? $data : [];
}

/**
 * Écrit la liste complète des avis. Tente un verrou de fichier (flock) pour
 * éviter les conflits entre deux envois simultanés, mais NE bloque PAS
 * l'écriture si le verrou échoue : certains hébergements mutualisés (dont
 * Free, sur système de fichiers réseau) ne supportent pas flock() de façon
 * fiable, alors qu'une écriture simple fonctionne très bien.
 */
function ecrireAvis($avis) {
    $contenu = json_encode($avis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($contenu === false) return false;

    $fp = @fopen(DATA_FILE, 'c+');
    if ($fp) {
        $verrouOk = @flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        $ecrit = fwrite($fp, $contenu);
        fflush($fp);
        if ($verrouOk) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        if ($ecrit !== false) {
            return true;
        }
    }

    // Repli : écriture directe si fopen/flock posent problème sur ce serveur
    return @file_put_contents(DATA_FILE, $contenu) !== false;
}

/** Génère un identifiant unique court (compatible PHP 5.6+) */
function genererId() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(6));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes(6));
    }
    return substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
}

/** Génère un entier aléatoire (compatible PHP 5.6+, sans besoin de cryptographie) */
function nombreAleatoire($min, $max) {
    if (function_exists('random_int')) {
        return random_int($min, $max);
    }
    return mt_rand($min, $max);
}

/** Échappe une chaîne pour affichage HTML sûr */
function nettoyer($str) {
    return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
}

/**
 * Redimensionne une image si elle dépasse MAX_IMAGE_WIDTH.
 * Retombe sur une simple copie si GD n'est pas disponible.
 */
function redimensionnerImage($cheminSource, $cheminDest, $largeurMax = MAX_IMAGE_WIDTH) {
    if (!function_exists('imagecreatetruecolor')) {
        return copy($cheminSource, $cheminDest);
    }

    // On essaie chaque décodeur GD directement plutôt que de se fier à
    // getimagesize() pour identifier le format : certains JPEG (notamment
    // de téléphones Samsung, avec de volumineuses métadonnées EXIF) font
    // échouer getimagesize() sur d'anciennes versions de PHP alors que GD
    // les décode très bien.
    $type = null;
    $src = @imagecreatefromjpeg($cheminSource);
    if ($src) {
        $type = IMAGETYPE_JPEG;
    } else {
        $src = @imagecreatefrompng($cheminSource);
        if ($src) {
            $type = IMAGETYPE_PNG;
        } elseif (function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($cheminSource);
            if ($src) $type = IMAGETYPE_WEBP;
        }
    }
    if (!$src) {
        $ok = copy($cheminSource, $cheminDest);
        if ($ok) @chmod($cheminDest, 0644);
        return $ok;
    }

    $largeur = imagesx($src);
    $hauteur = imagesy($src);

    // Corrige l'orientation EXIF (photos de téléphone) si possible
    if (function_exists('exif_read_data') && $type === IMAGETYPE_JPEG) {
        $exif = @exif_read_data($cheminSource);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $src = imagerotate($src, 180, 0); break;
                case 6: $src = imagerotate($src, -90, 0); break;
                case 8: $src = imagerotate($src, 90, 0); break;
            }
            $largeur = imagesx($src);
            $hauteur = imagesy($src);
        }
    }

    if ($largeur <= $largeurMax) {
        $ok = imagejpeg($src, $cheminDest, 85);
        imagedestroy($src);
        if ($ok) @chmod($cheminDest, 0644);
        return $ok;
    }

    $nouvelleLargeur = $largeurMax;
    $nouvelleHauteur = max(1, intval($hauteur * ($largeurMax / $largeur)));
    $dst = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);
    $ok = imagejpeg($dst, $cheminDest, 85);
    imagedestroy($src);
    imagedestroy($dst);
    if ($ok) @chmod($cheminDest, 0644);
    return $ok;
}

/**
 * Crée un jeton signé pour la question anti-robot, SANS dépendre des sessions
 * PHP (certains hébergements mutualisés, dont Free, ont un dossier de session
 * par défaut non inscriptible, ce qui rend les sessions peu fiables).
 * Le jeton empêche de modifier a/b/réponse sans que ce soit détecté.
 */
function creerJetonCaptcha($a, $b) {
    return hash_hmac('sha256', $a . ':' . $b, CAPTCHA_SECRET);
}

function jetonCaptchaValide($a, $b, $jeton) {
    if ($jeton === '' || $jeton === null) return false;
    return hash_equals(creerJetonCaptcha($a, $b), $jeton);
}

/** Traduit un code d'erreur d'upload PHP en message compréhensible */
function libelleErreurUpload($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return "le fichier dépasse la taille maximale autorisée par le serveur (upload_max_filesize)";
        case UPLOAD_ERR_FORM_SIZE:
            return "le fichier dépasse la taille maximale autorisée par le formulaire";
        case UPLOAD_ERR_PARTIAL:
            return "l'envoi a été interrompu avant la fin (connexion coupée ?)";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "le serveur n'a pas de dossier temporaire disponible";
        case UPLOAD_ERR_CANT_WRITE:
            return "le serveur n'a pas pu écrire le fichier sur le disque";
        case UPLOAD_ERR_EXTENSION:
            return "une extension PHP du serveur a bloqué l'envoi";
        default:
            return "erreur inconnue (code " . $code . ")";
    }
}

/**
 * Vérifie qu'un fichier uploadé est une image utilisable.
 * Retourne TRUE si c'est bon, ou une chaîne expliquant pourquoi ça ne
 * l'est pas (message affiché à l'utilisateur) sinon.
 */
function verifierImage($tmpPath) {
    $info = @getimagesize($tmpPath);
    if ($info && isset($info['mime']) && in_array($info['mime'], ALLOWED_TYPES, true)) {
        return true; // cas normal : getimagesize reconnaît directement le format
    }

    // getimagesize() peut parfois échouer sur des fichiers pourtant valides
    // (JPEG un peu particuliers, etc.) : on tente une seconde détection via
    // Fileinfo, plus permissive, avant de rejeter le fichier.
    $mimeReel = null;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeReel = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }
    if (!$mimeReel && function_exists('mime_content_type')) {
        $mimeReel = @mime_content_type($tmpPath);
    }

    if ($mimeReel && in_array($mimeReel, ALLOWED_TYPES, true)) {
        return true;
    }

    if ($mimeReel === 'image/heic' || $mimeReel === 'image/heif') {
        return "est au format HEIC/HEIF (photos iPhone). Sur l'iPhone : Réglages → Appareil photo → Formats → choisissez « Le plus compatible », puis reprenez la photo (ou convertissez-la en JPEG avant l'envoi).";
    }
    if ($mimeReel === 'image/avif') {
        return "est au format AVIF, pas encore pris en charge ici. Merci de le convertir en JPEG ou PNG avant l'envoi.";
    }

    // Dernier recours : getimagesize() et Fileinfo peuvent parfois échouer sur
    // des JPEG pourtant tout à fait valides (certains téléphones, notamment
    // Samsung, intègrent des métadonnées EXIF volumineuses que d'anciennes
    // versions de PHP — ex. PHP 5.6 — analysent mal). On tente donc de
    // décoder directement l'image avec GD, qui s'en sort souvent bien mieux.
    if (function_exists('imagecreatefromjpeg')) {
        $test = @imagecreatefromjpeg($tmpPath);
        if ($test) { imagedestroy($test); return true; }
    }
    if (function_exists('imagecreatefrompng')) {
        $test = @imagecreatefrompng($tmpPath);
        if ($test) { imagedestroy($test); return true; }
    }
    if (function_exists('imagecreatefromwebp')) {
        $test = @imagecreatefromwebp($tmpPath);
        if ($test) { imagedestroy($test); return true; }
    }

    return "n'est pas une image valide (JPG, PNG ou WEBP uniquement)";
}
