<?php
/**
 * Script de diagnostic — à ouvrir une seule fois dans votre navigateur
 * (ex: villacorsu.free.fr/livre-dor/verif-permissions.php)
 * puis à SUPPRIMER une fois que tout fonctionne (ne pas le laisser en ligne).
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><meta charset='utf-8'><body style='font-family:monospace;background:#f4f0e8;padding:30px;line-height:1.8'>";
echo "<h2>🔍 Vérification des permissions — Livre d'or Villa Corsu</h2>";

function verifier($label, $chemin) {
    echo "<p><strong>$label</strong><br>Chemin : <code>$chemin</code><br>";
    if (!file_exists($chemin)) {
        echo "❌ N'existe pas.</p>";
        return false;
    }
    $perms = substr(sprintf('%o', fileperms($chemin)), -4);
    echo "Permissions actuelles : $perms<br>";

    $estDossier = is_dir($chemin);
    $testFile = $estDossier ? $chemin . '/.test_ecriture.tmp' : $chemin;

    $ok = @file_put_contents($testFile, 'test') !== false;
    if ($ok) {
        echo "✅ Écriture réussie.</p>";
        if ($estDossier) @unlink($testFile);
    } else {
        echo "❌ Écriture IMPOSSIBLE avec les permissions actuelles.<br>";
        // Tentative de correction automatique via PHP (fonctionne souvent
        // même quand FileZilla / SITE CHMOD est bloqué par l'hébergeur)
        if (@chmod($chemin, 0755) && @file_put_contents($testFile, 'test') !== false) {
            echo "🔧 Correction automatique réussie (permissions ajustées à 0755). Réessayez le formulaire.</p>";
            if ($estDossier) @unlink($testFile);
        } else {
            echo "⚠️ Impossible de corriger automatiquement. Contactez le support Free en leur demandant de vérifier les droits d'écriture sur ce dossier pour votre compte.</p>";
        }
    }
    return $ok;
}

verifier('Fichier des avis', DATA_FILE);
verifier('Dossier des photos', UPLOAD_DIR);

echo "<p>PHP version : " . phpversion() . "<br>";
echo "Extension GD (redimensionnement photos) : " . (function_exists('imagecreatetruecolor') ? '✅ disponible' : '⚠️ absente (les photos seront copiées sans redimensionnement, ce n\'est pas grave)') . "<br>";
echo "Extension mbstring (texte) : " . (function_exists('mb_strlen') ? '✅ disponible' : '❌ absente — contactez le support Free') . "</p>";

echo "<p style='color:#c0392b'><strong>⚠️ Pensez à supprimer ce fichier (verif-permissions.php) une fois la vérification terminée.</strong></p>";
echo "</body>";
