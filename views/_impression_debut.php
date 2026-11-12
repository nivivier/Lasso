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
//
// ⚠️ couleurs_css_vars() est indispensable ici, et son absence ne se voyait
// que sur UN élément : la barre d'outils. assets/css/ donne à --brand une valeur
// de repli (#2b0b5b, l'indigo d'origine) pour la page qui n'aurait pas la
// palette de l'employeur — et une page d'impression n'avait pas cette palette,
// puisque seule views/layout.php l'injectait. La barre restait donc indigo
// quelle que soit l'apparence choisie, y compris dans la fenêtre d'aperçu, où
// elle fait office d'en-tête de fenêtre. Le reste du document, lui, ne se
// voyait pas : une feuille est en noir sur blanc.
//
// Le bloc sombre qu'émet couleurs_css_vars() ne s'applique pas ici :
// data-theme="clair" le neutralise des deux côtés (media query et attribut).
// La couleur du MODULE n'est volontairement pas reprise : un document
// n'appartient pas à l'écran d'où on l'ouvre, il porte les couleurs de
// l'association.
?>
<!DOCTYPE html>
<html lang="fr" data-theme="clair">
<head>
    <meta charset="UTF-8">
    <title><?= e($impTitre ?? 'Document') ?></title>
    <?= css_liens_html() ?>
    <?= couleurs_css_vars() ?>
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button data-print><?= icon('printer') ?> Imprimer / PDF</button>
        <?= $impActions ?? '' ?>
    </div>
    <div class="sheet<?= isset($impClasse) ? ' ' . e($impClasse) : '' ?>">
