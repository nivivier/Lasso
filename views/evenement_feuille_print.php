<?php
// Feuille de route imprimable d'une date : ce qu'on emporte le jour même, sur
// papier ou en PDF depuis le navigateur. Le contenu vient du corps partagé avec
// la fenêtre de consultation (_evenement_feuille_corps.php) ; il n'y a ici que
// l'enveloppe — l'en-tête à l'employeur, le bouton d'impression, le pied.
/** @var array $evenement */ /** @var array $elements */ /** @var array $organisateurs */
/** @var string $nomEmployeur */
$titrePage = trim((string) ($evenement['spectacle_nom'] ?? '')) ?: 'Date';
?>
<!DOCTYPE html>
<?php // data-theme="clair" : un document s'imprime sur du papier BLANC, donc à
      // l'encre foncée, quel que soit le thème de la machine. Sans ce marqueur,
      // une machine en thème sombre donnait à la feuille les couleurs du thème —
      // texte gris clair sur fond blanc, illisible à l'écran comme au papier.
      // C'est le même attribut que pose views/layout.php ; le bloc sombre de
      // app.css est gardé par :root:not([data-theme="clair"]). ?>
<html lang="fr" data-theme="clair">
<head>
    <meta charset="UTF-8">
    <title>Feuille de route — <?= e($titrePage) ?>, <?= e(date('d.m.Y', strtotime((string) $evenement['date']))) ?></title>
    <?php // Version dans l'URL, comme dans views/layout.php : sans elle, le
          // navigateur sert la feuille de style qu'il a en cache — celle d'avant
          // la dernière mise à jour —, et la page s'affiche avec des règles
          // périmées alors que le fichier sur le serveur est à jour. ?>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button data-print><?= icon('printer') ?> Imprimer / PDF</button>
    </div>
    <?php // La feuille est resserrée : marges réduites d'un cran par rapport à la
          // page A4 générique, pour tenir sur une page sans rogner le contenu. ?>
    <div class="sheet sheet-feuille">
        <div class="fr-print">
            <?php require __DIR__ . '/_evenement_feuille_corps.php'; ?>
            <?php // Le logo en pied, tout petit : sur une feuille de route, c'est
                  // la date et le lieu qu'on cherche des yeux, pas l'identité de
                  // qui l'a éditée — elle est là pour signer, pas pour ouvrir. ?>
            <p class="fr-print-pied">
                <?php $logo = param_logo('clair'); ?>
                <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" class="fr-print-logo"><?php endif; ?>
                <?= e($nomEmployeur) ?> · feuille de route éditée le <?= e(date('d.m.Y')) ?>
            </p>
        </div>
    </div>
<?php // « Imprimer / PDF » : data-print n'est traité que dans assets/app.js, et
      // une page d'impression ne charge pas tout le script de l'application pour
      // un appel. Échap referme l'onglet — ou, dans la fenêtre d'aperçu, c'est
      // elle qui l'intercepte (views/layout.php). ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelector('[data-print]').addEventListener('click', () => window.print());
document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
</script>
</body>
</html>
