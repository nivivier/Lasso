<?php
// L'ouverture d'un document imprimable : la page, sa feuille de style, le
// bouton « Imprimer / PDF », et la feuille de papier. Huit vues l'écrivaient à
// l'identique, au TITRE près — et ce titre est tout ce qui les distinguait.
//
// Un document d'impression ne passe pas par views/layout.php (render_bare()) :
// pas de rail, pas de bandeau, rien qui se retrouverait sur le papier. Il lui
// faut donc sa propre enveloppe complète, et c'est celle-ci.
//
// Variables, préfixées « imp » — comme « pt » et « nt » ailleurs, parce qu'un
// nom générique écraserait une donnée de la vue appelante :
//   $impTitre   le <title> de l'onglet. Obligatoire.
//   $impClasse  une classe de plus sur la feuille (la feuille de route s'en
//               sert pour resserrer ses marges). Facultative.
//   $impActions du HTML à poser dans la barre, après « Imprimer » — la feuille
//               de route y met son envoi à l'équipe, parce que c'est là qu'on
//               a le document sous les yeux et qu'on décide de le diffuser.
//               Facultatif, et à construire par ob_start()/ob_get_clean()
//               quand il contient du PHP (même procédé que $fmColonnes dans
//               views/_filtres_mobile.php).
//
// ⚠️ data-theme="clair" en dur : un document s'imprime sur du papier blanc, et
// le thème sombre d'un écran n'a rien à y faire.
?>
<!DOCTYPE html>
<html lang="fr" data-theme="clair">
<head>
    <meta charset="UTF-8">
    <title><?= e($impTitre ?? 'Document') ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button data-print><?= icon('printer') ?> Imprimer / PDF</button>
        <?= $impActions ?? '' ?>
    </div>
    <div class="sheet<?= isset($impClasse) ? ' ' . e($impClasse) : '' ?>">
