<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Authentification par cookie signé, SANS dépendre des sessions PHP
// (sur certains hébergements, dont Free avec système de fichiers réseau,
// les sessions ne persistent pas de façon fiable — voir avis.php pour le
// même choix appliqué au captcha).
define('NOM_COOKIE_ADMIN', 'livreor_admin');

function jetonAdminAttendu() {
    return hash_hmac('sha256', 'connecte', ADMIN_TOKEN_SECRET);
}
function adminEstConnecte() {
    return isset($_COOKIE[NOM_COOKIE_ADMIN]) && hash_equals(jetonAdminAttendu(), $_COOKIE[NOM_COOKIE_ADMIN]);
}

// --- Connexion ---
if (isset($_POST['mot_de_passe'])) {
    if (hash_equals(ADMIN_PASSWORD, $_POST['mot_de_passe'])) {
        setcookie(NOM_COOKIE_ADMIN, jetonAdminAttendu(), time() + 60 * 60 * 24 * 7, '/', '', false, true);
        header('Location: moderate.php');
        exit;
    } else {
        $erreurConnexion = "Mot de passe incorrect.";
    }
}
if (isset($_GET['deconnexion'])) {
    setcookie(NOM_COOKIE_ADMIN, '', time() - 3600, '/', '', false, true);
    header('Location: moderate.php');
    exit;
}

$connecte = adminEstConnecte();

// --- Actions (approuver / supprimer) ---
if ($connecte && isset($_GET['action'], $_GET['id'])) {
    $avis = lireAvis();
    $index = null;
    foreach ($avis as $i => $a) {
        if ($a['id'] === $_GET['id']) { $index = $i; break; }
    }
    if ($index !== null) {
        if ($_GET['action'] === 'approuver') {
            $avis[$index]['approuve'] = true;
            ecrireAvis($avis);
        } elseif ($_GET['action'] === 'refuser') {
            $photosASupprimer = isset($avis[$index]['photos']) ? $avis[$index]['photos'] : array();
            foreach ($photosASupprimer as $photo) {
                @unlink(UPLOAD_DIR . $photo);
            }
            array_splice($avis, $index, 1);
            ecrireAvis($avis);
        }
    }
    header('Location: moderate.php');
    exit;
}

$tousLesAvis = $connecte ? lireAvis() : [];
usort($tousLesAvis, function($a, $b) { return strcmp($b['date'], $a['date']); });
$enAttente = array_values(array_filter($tousLesAvis, function($a) { return empty($a['approuve']); }));
$approuves = array_values(array_filter($tousLesAvis, function($a) { return !empty($a['approuve']); }));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Modération — Livre d'or Villa Corsu</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root { --bg:#f4f0e8; --card:#f9f6f0; --gold:#9a8660; --dark:#4a3f2f; --mid:#6b5c45; --red:#c0392b; --green:#4a6741; }
  * { box-sizing:border-box; }
  body { font-family:system-ui,sans-serif; background:var(--bg); color:var(--dark); margin:0; padding:20px; }
  .wrap { max-width:720px; margin:0 auto; }
  h1 { font-size:1.3rem; margin-bottom:16px; }
  .login-box { background:var(--card); border-radius:14px; padding:24px; max-width:340px; margin:60px auto; box-shadow:0 2px 20px rgba(0,0,0,.08); }
  .login-box input { width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; margin-bottom:10px; font-size:.95rem; }
  .login-box button { width:100%; padding:11px; background:var(--gold); color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
  .err { color:var(--red); font-size:.85rem; margin-bottom:10px; }
  .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
  .topbar a { font-size:.85rem; color:var(--mid); text-decoration:none; }
  .card { background:var(--card); border-radius:12px; padding:16px; margin-bottom:12px; box-shadow:0 2px 12px rgba(0,0,0,.06); }
  .card h3 { font-size:1rem; margin-bottom:4px; }
  .card .meta { font-size:.75rem; color:var(--mid); margin-bottom:8px; }
  .card p { font-size:.88rem; line-height:1.5; white-space:pre-wrap; margin-bottom:10px; }
  .photos { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
  .photos img { width:70px; height:70px; object-fit:cover; border-radius:6px; }
  .actions a { display:inline-block; padding:8px 14px; border-radius:8px; text-decoration:none; font-size:.8rem; font-weight:600; margin-right:8px; }
  .approuver { background:var(--green); color:#fff; }
  .refuser { background:var(--red); color:#fff; }
  .badge { display:inline-block; font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; padding:3px 8px; border-radius:20px; background:#fff3cd; color:#856404; margin-bottom:8px; }
  section h2 { font-size:1rem; margin:24px 0 10px; color:var(--gold); }
  .vide { color:var(--mid); font-size:.85rem; padding:10px 0; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$connecte): ?>
  <div class="login-box">
    <h1 style="text-align:center">🔒 Modération</h1>
    <?php if (!empty($erreurConnexion)): ?><p class="err"><?= nettoyer($erreurConnexion) ?></p><?php endif; ?>
    <form method="post">
      <input type="password" name="mot_de_passe" placeholder="Mot de passe" required autofocus>
      <button type="submit">Se connecter</button>
    </form>
  </div>
<?php else: ?>

  <div class="topbar">
    <h1>Modération — Livre d'or</h1>
    <a href="?deconnexion=1">Se déconnecter</a>
  </div>

  <section>
    <h2>⏳ En attente (<?= count($enAttente) ?>)</h2>
    <?php if (empty($enAttente)): ?>
      <div class="vide">Rien à valider pour le moment.</div>
    <?php endif; ?>
    <?php foreach ($enAttente as $a): ?>
      <div class="card">
        <span class="badge">En attente</span>
        <h3><?= nettoyer($a['nom']) ?> <?= !empty($a['note']) ? str_repeat('★', (int)$a['note']) : '' ?></h3>
        <div class="meta"><?= nettoyer(isset($a['sejour']) ? $a['sejour'] : '') ?> — <?= nettoyer(date('d/m/Y H:i', strtotime($a['date']))) ?></div>
        <p><?= nettoyer($a['commentaire']) ?></p>
        <?php if (!empty($a['photos'])): ?>
          <div class="photos">
            <?php foreach ($a['photos'] as $p): ?><img src="<?= UPLOAD_URL . nettoyer($p) ?>"><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="actions">
          <a class="approuver" href="?action=approuver&id=<?= urlencode($a['id']) ?>">✓ Publier</a>
          <a class="refuser" href="?action=refuser&id=<?= urlencode($a['id']) ?>" onclick="return confirm('Supprimer définitivement cet avis ?')">✕ Refuser</a>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <section>
    <h2>✅ Publiés (<?= count($approuves) ?>)</h2>
    <?php if (empty($approuves)): ?>
      <div class="vide">Aucun avis publié.</div>
    <?php endif; ?>
    <?php foreach ($approuves as $a): ?>
      <div class="card">
        <h3><?= nettoyer($a['nom']) ?> <?= !empty($a['note']) ? str_repeat('★', (int)$a['note']) : '' ?></h3>
        <div class="meta"><?= nettoyer(isset($a['sejour']) ? $a['sejour'] : '') ?> — <?= nettoyer(date('d/m/Y H:i', strtotime($a['date']))) ?></div>
        <p><?= nettoyer($a['commentaire']) ?></p>
        <?php if (!empty($a['photos'])): ?>
          <div class="photos">
            <?php foreach ($a['photos'] as $p): ?><img src="<?= UPLOAD_URL . nettoyer($p) ?>"><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="actions">
          <a class="refuser" href="?action=refuser&id=<?= urlencode($a['id']) ?>" onclick="return confirm('Supprimer définitivement cet avis ?')">✕ Retirer</a>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

<?php endif; ?>
</div>
</body>
</html>
