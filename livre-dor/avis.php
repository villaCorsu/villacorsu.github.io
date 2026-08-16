<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Empêche le navigateur de réafficher une ancienne réponse (ex: message
// d'erreur d'un envoi précédent) via le cache ou le bouton "précédent".
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$erreurs = [];
$succes = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer_avis'])) {

    $nom          = nettoyer(isset($_POST['nom']) ? $_POST['nom'] : '');
    $sejour       = nettoyer(isset($_POST['sejour']) ? $_POST['sejour'] : '');
    $commentaire  = nettoyer(isset($_POST['commentaire']) ? $_POST['commentaire'] : '');
    $note         = isset($_POST['note']) ? (int)$_POST['note'] : 0;
    $piegeAntiSpam = isset($_POST['site_web']) ? $_POST['site_web'] : '';
    $reponseCaptcha = isset($_POST['captcha']) && $_POST['captcha'] !== '' ? (int)$_POST['captcha'] : null;

    // Vérification anti-robot : le jeton signé prouve que a/b n'ont pas été
    // modifiés depuis l'affichage du formulaire (ne dépend pas des sessions
    // PHP, ce qui la rend fiable même sur les hébergements qui les bloquent).
    $captchaARecu    = isset($_POST['captcha_a']) ? (int)$_POST['captcha_a'] : null;
    $captchaBRecu    = isset($_POST['captcha_b']) ? (int)$_POST['captcha_b'] : null;
    $captchaJetonRecu = isset($_POST['captcha_jeton']) ? $_POST['captcha_jeton'] : '';

    // Anti-spam : champ piège (doit rester vide) + petite addition
    if ($piegeAntiSpam !== '') {
        $erreurs[] = "Une erreur est survenue, merci de réessayer.";
    }
    if ($captchaARecu === null || $captchaBRecu === null || !jetonCaptchaValide($captchaARecu, $captchaBRecu, $captchaJetonRecu)) {
        $erreurs[] = "La vérification anti-robot a expiré, merci de réessayer.";
    } elseif ($reponseCaptcha !== ($captchaARecu + $captchaBRecu)) {
        $erreurs[] = "La réponse à la question de vérification est incorrecte.";
    }
    if ($nom === '' || mb_strlen($nom) < 2) {
        $erreurs[] = "Merci d'indiquer votre nom ou prénom.";
    }
    if ($commentaire === '' || mb_strlen($commentaire) < 5) {
        $erreurs[] = "Votre message est un peu court, dites-nous en un peu plus !";
    }
    if (mb_strlen($commentaire) > 2000) {
        $erreurs[] = "Votre message est trop long (2000 caractères maximum).";
    }
    if ($note < 0 || $note > 5) {
        $note = 0;
    }

    // Gestion des photos
    $photosEnregistrees = [];
    if (!empty($_FILES['photos']) && empty($erreurs)) {
        $nomsFichiers = $_FILES['photos']['name'];
        $nbPhotos = 0;
        foreach ($nomsFichiers as $i => $nomFichier) {
            if ($nomFichier === '') continue;
            $nbPhotos++;
            if ($nbPhotos > MAX_PHOTOS) {
                $erreurs[] = "Vous ne pouvez envoyer que " . MAX_PHOTOS . " photos maximum.";
                break;
            }
            $tmpPath = $_FILES['photos']['tmp_name'][$i];
            $erreurUpload = $_FILES['photos']['error'][$i];
            $taille = $_FILES['photos']['size'][$i];

            if ($erreurUpload !== UPLOAD_ERR_OK) {
                $erreurs[] = "Erreur lors de l'envoi de la photo « " . nettoyer($nomFichier) . " » : " . libelleErreurUpload($erreurUpload) . ".";
                continue;
            }
            if ($taille > MAX_FILE_SIZE) {
                $erreurs[] = "La photo « " . nettoyer($nomFichier) . " » (" . round($taille / 1024) . " Ko) dépasse 8 Mo.";
                continue;
            }
            $resultatImage = verifierImage($tmpPath);
            if ($resultatImage !== true) {
                $typeEnvoye = isset($_FILES['photos']['type'][$i]) ? $_FILES['photos']['type'][$i] : 'inconnu';
                $erreurs[] = "Le fichier « " . nettoyer($nomFichier) . " » (" . round($taille / 1024) . " Ko, type détecté par le navigateur : " . nettoyer($typeEnvoye) . ") " . $resultatImage;
                continue;
            }

            $extension = 'jpg';
            $nomUnique = date('Ymd_His') . '_' . genererId() . '.' . $extension;
            $cheminDest = UPLOAD_DIR . $nomUnique;

            if (redimensionnerImage($tmpPath, $cheminDest)) {
                $photosEnregistrees[] = $nomUnique;
            } else {
                $erreurs[] = "Impossible d'enregistrer la photo « " . nettoyer($nomFichier) . " ».";
            }
        }
    }

    if (empty($erreurs)) {
        $avis = lireAvis();
        $nouvelAvis = [
            'id'          => genererId(),
            'nom'         => $nom,
            'sejour'      => $sejour,
            'note'        => $note,
            'commentaire' => $commentaire,
            'photos'      => $photosEnregistrees,
            'date'        => date('c'),
            'approuve'    => false,
        ];
        array_unshift($avis, $nouvelAvis);

        if (ecrireAvis($avis)) {
            $succes = true;
        } else {
            $erreurs[] = "Votre avis n'a pas pu être enregistré (problème d'écriture sur le serveur). Merci de réessayer dans quelques instants, ou de contacter directement le propriétaire si le problème persiste.";
            $valeurs = compact('nom', 'sejour', 'commentaire', 'note');
        }
    } else {
        // On garde les valeurs saisies pour ne pas faire tout retaper
        $valeurs = compact('nom', 'sejour', 'commentaire', 'note');
    }
}

// Nouvelle question anti-robot pour l'affichage du formulaire (toujours
// régénérée : après un envoi réussi, après une erreur, ou au premier chargement)
$captchaA = nombreAleatoire(2, 8);
$captchaB = nombreAleatoire(2, 8);
$captchaJeton = creerJetonCaptcha($captchaA, $captchaB);

// Avis publics (modérés) à afficher, du plus récent au plus ancien
$tousLesAvis = lireAvis();
$avisApprouves = array_values(array_filter($tousLesAvis, function($a) { return !empty($a['approuve']); }));
usort($avisApprouves, function($a, $b) { return strcmp($b['date'], $a['date']); });

$nbAvis = count($avisApprouves);
$moyenne = 0;
if ($nbAvis > 0) {
    $notes = array_filter(array_column($avisApprouves, 'note'));
    if (count($notes) > 0) $moyenne = round(array_sum($notes) / count($notes), 1);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#f4f0e8">
<title>Livre d'or — La Villa Corsu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400;1,500&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f4f0e8; --bg2:#ede8de; --card:#f9f6f0; --gold:#9a8660; --gold-light:#b8a07a;
  --gold-pale:#e8dfc8; --dark:#4a3f2f; --mid:#6b5c45; --muted:#7a6e5e; --white:#faf8f4;
  --red:#c0392b; --green:#4a6741; --radius:16px;
  --shadow:0 2px 20px rgba(26,22,18,.08); --shadow-lg:0 8px 40px rgba(26,22,18,.14);
}
* { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html { scroll-behavior:smooth; }
body { font-family:'Outfit',sans-serif; background:var(--bg); color:var(--dark); overflow-x:hidden; font-size:16px; line-height:1.6; }
@media(min-width:860px){ body{ max-width:760px; margin:0 auto; } }

/* topbar */
.sticky-header { position:sticky; top:0; z-index:200; box-shadow:0 2px 12px rgba(74,63,47,.08); }
.topbar { background:#ffffff; display:flex; align-items:center; justify-content:center; height:148px; overflow:hidden; }
.logo-video { width:128px; height:128px; border-radius:8px; object-fit:cover; }
.topbar-fallback { font-family:'Playfair Display',serif; font-size:1.15rem; color:var(--dark); letter-spacing:.03em; }
.topbar-fallback small { display:block; font-family:'Outfit',sans-serif; font-size:.6rem; font-weight:400; letter-spacing:.2em; color:var(--gold); text-transform:uppercase; margin-top:2px; }
.retour-link { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-size:.75rem; color:var(--gold); text-decoration:none; display:flex; align-items:center; gap:4px; }

/* hero */
.gb-hero { background:var(--dark); padding:34px 20px 30px; position:relative; overflow:hidden; }
.gb-hero::before { content:''; position:absolute; top:-50px; right:-50px; width:180px; height:180px; border-radius:50%; background:radial-gradient(circle,rgba(154,134,96,.25) 0%, transparent 70%); }
.chip { display:inline-block; font-size:.6rem; font-weight:600; letter-spacing:.25em; text-transform:uppercase; color:var(--gold-light); background:rgba(154,134,96,.2); border-radius:20px; padding:4px 10px; margin-bottom:10px; }
.gb-hero h1 { font-family:'Playfair Display',serif; font-size:clamp(1.7rem,7vw,2.4rem); font-weight:400; color:var(--white); line-height:1.15; margin-bottom:8px; }
.gb-hero h1 em { font-style:italic; color:var(--gold-light); }
.gb-hero p { font-size:.88rem; font-weight:300; color:rgba(250,248,244,.65); line-height:1.75; max-width:520px; position:relative; z-index:1; }
.gb-stats { display:flex; gap:10px; margin-top:18px; position:relative; z-index:1; }
.gb-stat { background:rgba(255,255,255,.06); border:1px solid rgba(154,134,96,.25); border-radius:12px; padding:10px 16px; text-align:center; }
.gb-stat b { display:block; font-family:'Playfair Display',serif; font-size:1.3rem; color:var(--gold-light); }
.gb-stat span { font-size:.62rem; letter-spacing:.08em; text-transform:uppercase; color:rgba(250,248,244,.45); }

/* sections */
.section { padding:28px 20px; }
.section-header { margin-bottom:18px; }
.section-title { font-family:'Playfair Display',serif; font-size:clamp(1.4rem,5vw,1.8rem); font-weight:400; color:var(--dark); line-height:1.2; }
.section-title em { font-style:italic; color:var(--gold); }
.divider { height:1px; background:linear-gradient(90deg,transparent,var(--gold-pale),transparent); margin:4px 20px; }

/* messages */
.msg { border-radius:12px; padding:14px 16px; font-size:.85rem; line-height:1.6; margin-bottom:18px; }
.msg.ok { background:#e9f2e5; border-left:3px solid var(--green); color:#2f4a28; }
.msg.err { background:#fbeceb; border-left:3px solid var(--red); color:#7a2820; }
.msg ul { margin:6px 0 0 18px; }

/* carte avis */
.avis-grid { display:flex; flex-direction:column; gap:14px; }
.avis-card { background:var(--card); border-radius:var(--radius); padding:18px; box-shadow:var(--shadow); }
.avis-head { display:flex; align-items:flex-start; gap:10px; margin-bottom:8px; }
.avis-id { display:flex; align-items:flex-start; gap:10px; }
.avis-avatar { width:38px; height:38px; border-radius:50%; background:var(--gold); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Playfair Display',serif; font-weight:600; font-size:1rem; flex-shrink:0; }
.avis-nom { font-size:.92rem; font-weight:600; color:var(--dark); }
.avis-sejour { font-size:.72rem; color:var(--muted); }
.avis-note { color:#f0b400; font-size:.9rem; letter-spacing:1px; white-space:nowrap; text-align:left; margin-top:3px; text-shadow:0 1px 1px rgba(0,0,0,.06); }
.avis-texte { font-size:.86rem; color:var(--mid); line-height:1.7; margin:6px 0 10px; white-space:pre-wrap; }
.avis-photos { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
.avis-photos img { width:100%; height:90px; object-fit:cover; border-radius:8px; cursor:zoom-in; }
.avis-date { font-size:.68rem; color:rgba(122,110,94,.6); margin-top:10px; text-align:right; }
.avis-vide { text-align:center; color:var(--muted); font-size:.85rem; padding:30px 10px; }

/* formulaire */
.form-card { background:var(--card); border-radius:var(--radius); padding:22px 20px; box-shadow:var(--shadow); }
.champ { margin-bottom:16px; }
.champ label { display:block; font-size:.72rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--mid); margin-bottom:6px; }
.champ input[type=text], .champ textarea, .champ input[type=number] {
  width:100%; border:1px solid var(--gold-pale); background:var(--white); border-radius:10px;
  padding:12px 14px; font-family:'Outfit',sans-serif; font-size:.9rem; color:var(--dark); resize:vertical;
}
.champ input:focus, .champ textarea:focus { outline:none; border-color:var(--gold); }
.champ textarea { min-height:110px; line-height:1.6; }
.hp { position:absolute; left:-9999px; width:1px; height:1px; opacity:0; }

.etoiles { display:flex; gap:6px; flex-direction:row-reverse; justify-content:flex-start; }
.etoiles input { display:none; }
.etoiles label { font-size:1.7rem; color:var(--gold-pale); cursor:pointer; transition:color .15s; }
.etoiles input:checked ~ label,
.etoiles label:hover, .etoiles label:hover ~ label { color:var(--gold); }
.etoiles-hint { font-size:.7rem; color:var(--muted); margin-top:4px; }

.photo-drop { border:2px dashed var(--gold-pale); border-radius:12px; padding:20px; text-align:center; cursor:pointer; background:rgba(154,134,96,.04); transition:border-color .2s; }
.photo-drop:hover { border-color:var(--gold); }
.photo-drop span.gros { display:block; font-size:1.6rem; margin-bottom:4px; }
.photo-drop small { display:block; font-size:.7rem; color:var(--muted); margin-top:4px; }
.photo-input { display:none; }
.previews { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:12px; }
.preview-item { position:relative; border-radius:10px; overflow:hidden; height:90px; }
.preview-item img { width:100%; height:100%; object-fit:cover; display:block; }
.preview-item button { position:absolute; top:4px; right:4px; width:22px; height:22px; border:none; border-radius:50%; background:rgba(0,0,0,.6); color:#fff; font-size:.8rem; cursor:pointer; line-height:1; }

.captcha-row { display:flex; align-items:center; gap:10px; }
.captcha-row input { max-width:90px; text-align:center; }

.envoyer-btn { width:100%; background:var(--gold); color:#fff; border:none; border-radius:12px; padding:15px; font-family:'Outfit',sans-serif; font-size:.95rem; font-weight:600; cursor:pointer; transition:filter .2s; }
.envoyer-btn:active { filter:brightness(.92); }
.rgpd-note { font-size:.68rem; color:var(--muted); line-height:1.5; margin-top:12px; text-align:center; }

/* footer */
.footer { background:var(--dark); text-align:center; padding:30px 20px; }
.footer-note { font-size:.7rem; color:rgba(250,248,244,.35); }
.footer-note a { color:var(--gold-light); text-decoration:none; }

/* lightbox */
#lb { display:flex; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.93); align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .22s; }
#lb.on { opacity:1; pointer-events:all; }
#lb img { max-width:100%; max-height:88vh; border-radius:12px; object-fit:contain; box-shadow:0 8px 50px rgba(0,0,0,.6); }
#lb-close { position:absolute; top:14px; right:18px; background:none; border:none; color:#fff; font-size:2rem; cursor:pointer; opacity:.75; }
</style>
</head>
<body>

<div class="sticky-header">
  <nav class="topbar">
    <a href="http://villa.corsu.free.fr/guide" class="retour-link">← Guide</a>
    <img class="logo-video" src="../gfx/logo.png" alt="La Villa Corsu" onerror="this.style.display='none';document.getElementById('tf').style.display='block'">
    <div id="tf" class="topbar-fallback" style="display:none">La Villa Corsu<small>Livre d'or</small></div>
  </nav>
</div>

<div class="gb-hero">
  <div class="chip">Livre d'or</div>
  <h1>Vos <em>souvenirs</em> à la Villa Corsu</h1>
  <p>Un petit mot, une photo de vacances, un souvenir marquant ? Cette page est faite pour vous — merci de partager un peu de votre séjour avec nous et les futurs locataires.</p>
  <div class="gb-stats">
    <div class="gb-stat"><b><?= $nbAvis ?></b><span>Avis</span></div>
    <div class="gb-stat"><b><?= $moyenne > 0 ? $moyenne : '—' ?></b><span>Note moy.</span></div>
  </div>
</div>

<!-- FORMULAIRE -->
<div class="section" id="formulaire">
  <div class="section-header">
    <h2 class="section-title">Laisser un <em>avis</em></h2>
  </div>

  <?php if ($succes): ?>
    <div class="msg ok">✅ Merci beaucoup pour votre message ! Il sera publié ici après une petite vérification.</div>
  <?php elseif (!empty($erreurs)): ?>
    <div class="msg err">
      <strong>Petit souci avant l'envoi :</strong>
      <ul><?php foreach ($erreurs as $e) echo '<li>' . nettoyer($e) . '</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <div class="form-card">
    <form method="post" enctype="multipart/form-data" id="form-avis">
      <input type="text" name="site_web" class="hp" tabindex="-1" autocomplete="off">

      <div class="champ">
        <label for="nom">Votre nom</label>
        <input type="text" id="nom" name="nom" maxlength="60" required
          value="<?= nettoyer(isset($valeurs['nom']) ? $valeurs['nom'] : '') ?>" placeholder="Ex : Camille &amp; famille">
      </div>

      <div class="champ">
        <label for="sejour">Dates de séjour <span style="text-transform:none;font-weight:400">(facultatif)</span></label>
        <input type="text" id="sejour" name="sejour" maxlength="60"
          value="<?= nettoyer(isset($valeurs['sejour']) ? $valeurs['sejour'] : '') ?>" placeholder="Ex : Juillet 2026">
      </div>

      <div class="champ">
        <label>Votre note</label>
        <div class="etoiles">
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" name="note" id="etoile<?= $i ?>" value="<?= $i ?>" <?= ((isset($valeurs['note']) ? $valeurs['note'] : 0) == $i) ? 'checked' : '' ?>>
            <label for="etoile<?= $i ?>">★</label>
          <?php endfor; ?>
        </div>
        <div class="etoiles-hint">Facultatif</div>
      </div>

      <div class="champ">
        <label for="commentaire">Votre message</label>
        <textarea id="commentaire" name="commentaire" required maxlength="2000"
          placeholder="Racontez-nous votre séjour, vos moments préférés..."><?= nettoyer(isset($valeurs['commentaire']) ? $valeurs['commentaire'] : '') ?></textarea>
      </div>

      <div class="champ">
        <label>Photos souvenirs <span style="text-transform:none;font-weight:400">(facultatif, <?= MAX_PHOTOS ?> max)</span></label>
        <label class="photo-drop" for="photos-input">
          <span class="gros">📷</span>
          Ajouter des photos
          <small>JPG, PNG ou WEBP — 8 Mo max par photo</small>
        </label>
        <input type="file" id="photos-input" name="photos[]" class="photo-input"
          accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp,.JPG,.JPEG,.PNG,.WEBP,.Jpg,.Jpeg,.Png,.Webp" multiple>
        <div class="previews" id="previews"></div>
      </div>

      <div class="champ">
        <label for="captcha">Vérification anti-robot</label>
        <div class="captcha-row">
          <span>Combien font <?= $captchaA ?> + <?= $captchaB ?> ?</span>
          <input type="number" id="captcha" name="captcha" inputmode="numeric" required>
          <input type="hidden" name="captcha_a" value="<?= $captchaA ?>">
          <input type="hidden" name="captcha_b" value="<?= $captchaB ?>">
          <input type="hidden" name="captcha_jeton" value="<?= nettoyer($captchaJeton) ?>">
        </div>
      </div>

      <button type="submit" name="envoyer_avis" class="envoyer-btn">Envoyer mon avis 💛</button>
      <p class="rgpd-note">Votre nom, votre message et vos photos seront publiés sur cette page après validation. Vous pouvez demander leur retrait à tout moment en nous écrivant.</p>
    </form>
  </div>
</div>

<div class="divider"></div>

<!-- GALERIE DES AVIS -->
<div class="section">
  <div class="section-header">
    <h2 class="section-title">Ils sont <em>venus</em></h2>
  </div>

  <div class="avis-grid">
    <?php if ($nbAvis === 0): ?>
      <div class="avis-vide">Aucun avis publié pour le moment — soyez les premiers ! ✨</div>
    <?php else: ?>
      <?php foreach ($avisApprouves as $a): ?>
        <div class="avis-card">
          <div class="avis-head">
            <div class="avis-avatar"><?= nettoyer(mb_substr($a['nom'], 0, 1)) ?></div>
            <div>
              <div class="avis-nom"><?= nettoyer($a['nom']) ?></div>
              <?php if (!empty($a['sejour'])): ?><div class="avis-sejour"><?= nettoyer($a['sejour']) ?></div><?php endif; ?>
              <?php if (!empty($a['note'])): ?>
                <div class="avis-note"><?= str_repeat('★', (int)$a['note']) . str_repeat('☆', 5 - (int)$a['note']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="avis-texte"><?= nettoyer($a['commentaire']) ?></div>
          <?php if (!empty($a['photos'])): ?>
            <div class="avis-photos">
              <?php foreach ($a['photos'] as $photo): ?>
                <img src="<?= UPLOAD_URL . nettoyer($photo) ?>" alt="Souvenir de <?= nettoyer($a['nom']) ?>" onclick="lbShow(this.src,this.alt)" loading="lazy">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="avis-date"><?= nettoyer(date('d/m/Y', strtotime($a['date']))) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<footer class="footer">
  <p class="footer-note">La Villa Corsu — <a href="http://villa.corsu.free.fr/guide">Retour au guide</a></p>
</footer>

<div id="lb" onclick="if(event.target===this)lbHide()">
  <button id="lb-close" onclick="lbHide()">&#x2715;</button>
  <img id="lb-img" src="" alt="">
</div>

<script>
function lbShow(src, alt) {
  document.getElementById('lb-img').src = src;
  document.getElementById('lb-img').alt = alt || '';
  document.getElementById('lb').classList.add('on');
  document.body.style.overflow = 'hidden';
}
function lbHide() {
  document.getElementById('lb').classList.remove('on');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') lbHide(); });

// Aperçu des photos sélectionnées avant envoi
const input = document.getElementById('photos-input');
const previews = document.getElementById('previews');
const dropZone = document.querySelector('.photo-drop');
const dropZoneTexteOrigine = dropZone.innerHTML;
const MAX_PHOTOS = <?= (int)MAX_PHOTOS ?>;
const DIMENSION_MAX = 1600;      // largeur/hauteur max après compression
const QUALITE_JPEG = 0.85;
let fichiersChoisis = [];

function chargerImage(fichier) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = URL.createObjectURL(fichier);
  });
}

// Redimensionne/compresse une photo dans le navigateur avant envoi, pour
// rester largement sous la limite de 8 Mo et éviter les soucis de format
// ou de limites serveur. Si le navigateur ne sait pas décoder le fichier
// (ex : certains formats propriétaires), on renvoie le fichier original
// tel quel — le serveur se chargera de donner un message d'erreur clair.
async function redimensionnerFichier(fichier) {
  if (!fichier.type.startsWith('image/')) return fichier;
  try {
    const image = await chargerImage(fichier);
    let { width, height } = image;
    if (width > DIMENSION_MAX || height > DIMENSION_MAX) {
      const ratio = Math.min(DIMENSION_MAX / width, DIMENSION_MAX / height);
      width = Math.round(width * ratio);
      height = Math.round(height * ratio);
    }
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(image, 0, 0, width, height);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', QUALITE_JPEG));
    URL.revokeObjectURL(image.src);
    if (!blob) return fichier;
    // Si la compression n'apporte rien (photo déjà petite), on garde l'original
    if (blob.size >= fichier.size) return fichier;
    const nomSansExtension = fichier.name.replace(/\.[^.]+$/, '');
    return new File([blob], nomSansExtension + '.jpg', { type: 'image/jpeg' });
  } catch (e) {
    return fichier;
  }
}

input.addEventListener('change', async () => {
  const nouveaux = Array.from(input.files).slice(0, Math.max(0, MAX_PHOTOS - fichiersChoisis.length));
  input.value = ''; // permet de resélectionner le(s) même(s) fichier(s) plus tard si besoin

  dropZone.innerHTML = '<span class="gros">⏳</span>Compression des photos…<small>Un instant, ça ne sera pas long</small>';
  for (const fichier of nouveaux) {
    const fichierTraite = await redimensionnerFichier(fichier);
    fichiersChoisis.push(fichierTraite);
  }
  dropZone.innerHTML = dropZoneTexteOrigine;

  majApercus();
});

function majApercus() {
  previews.innerHTML = '';
  fichiersChoisis.forEach((fichier, i) => {
    const url = URL.createObjectURL(fichier);
    const poidsKo = Math.round(fichier.size / 1024);
    const div = document.createElement('div');
    div.className = 'preview-item';
    div.innerHTML = `<img src="${url}" title="${poidsKo} Ko"><button type="button" aria-label="Retirer">✕</button>`;
    div.querySelector('button').onclick = () => {
      fichiersChoisis.splice(i, 1);
      majApercus();
    };
    previews.appendChild(div);
  });
  // Reconstruit la liste de fichiers réelle pour l'envoi du formulaire
  const dt = new DataTransfer();
  fichiersChoisis.forEach(f => dt.items.add(f));
  input.files = dt.files;
}
</script>

</body>
</html>
