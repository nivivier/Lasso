<?php
// La rangée de SOUS-onglets : second niveau d'onglets, hors du bandeau et
// juste sous lui. Deux endroits la rendaient à l'identique — les Paramètres
// (views/_param_tabs.php) et un onglet de module qui porte deux écrans de la
// même matière (views/_page_head_band.php) —, c'est-à-dire deux copies d'un
// même balisage à faire diverger. Elles avaient déjà commencé : l'une passait
// la route par e(), l'autre non.
//
// Variables préfixées « st », comme « pt » et « nt » ailleurs : render() fait
// extract($data) avant d'inclure la vue, un nom générique écraserait une
// donnée de l'appelant.
//
//   $stOnglets  [route => libellé], dans l'ordre d'affichage
//   $stActif    la route à mettre en évidence. Résolue par l'APPELANT : lui
//               seul sait qu'une route-alias (une page de traitement d'import)
//               doit allumer le sous-onglet de sa section.
//   $stSuffixe  ce qui s'ajoute à l'adresse de l'onglet — « &depuis=… »,
//               vide sinon.
//               Déjà encodé pour une URL par l'appelant.
?>
<nav class="param-subtabs">
    <?php foreach ($stOnglets as $stRoute => $stLib): ?>
        <a href="?p=<?= e((string) $stRoute) ?><?= $stSuffixe ?? '' ?>"
           class="<?= (string) $stActif === (string) $stRoute ? 'on' : '' ?>"><?= e($stLib) ?></a>
    <?php endforeach; ?>
</nav>
