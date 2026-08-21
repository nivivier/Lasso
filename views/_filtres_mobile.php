<?php
// Panneau « Filtres » des listes en mini-cartes.
//
// Sur téléphone, la mise en cartes masque le <thead> (@media 700px,
// assets/app.css) : les entonnoirs accrochés aux en-têtes de colonne
// deviennent inatteignables. Ce panneau les reprend derrière un unique bouton
// posé dans la toolbar, à côté de la recherche — invisible au-delà de 700 px,
// où les entonnoirs du <thead> reprennent la main. La vue carte de
// ?p=structures s'en sert aussi (classe .filtres-carte, visible à toute
// largeur) : elle n'a pas non plus d'en-tête de colonne où les accrocher.
//
// Variables attendues, posées par la vue juste avant le require :
//   $fmColonnes (obligatoire) : les filtres de colonne, déjà rendus
//                               (filtre_colonne_html(), avec leur libellé —
//                               hors tableau, aucun en-tête ne les nomme).
//   $fmActifs   (facultatif)  : bande des filtres actifs
//                               (filtre_colonne_actifs_html() concaténés).
//   $fmExtra    (facultatif)  : bloc supplémentaire dans le panneau
//                               (le formulaire jauge/mois des structures).
//   $fmClasse   (facultatif)  : classe en plus sur le <details>.
$fmActifs = $fmActifs ?? '';
$fmExtra  = $fmExtra ?? '';
$fmClasse = $fmClasse ?? '';
// Panneau fermé, rien ne dirait qu'un filtre est actif : le bouton porte donc
// le compte, sur le modèle des filtres nommés (filtre_bouton_html()). Il est
// déduit de la bande elle-même — une pastille par valeur active, qu'elle vienne
// d'une case à cocher ou d'un groupe (jauge, mois) — plutôt que recompté par
// chaque appelant, qui finirait par diverger de ce qui s'affiche.
$fmNb = substr_count($fmActifs, 'class="col-th-actif"');
?>
<details class="filters-more filtres-mobile<?= $fmClasse !== '' ? ' ' . $fmClasse : '' ?>">
    <summary class="<?= $fmNb ? 'on' : '' ?>" title="Filtres" aria-label="Filtres<?= $fmNb ? ' (' . $fmNb . ' actif' . ($fmNb > 1 ? 's' : '') . ')' : '' ?>"><?= icon('funnel') ?><?php if ($fmNb): ?><span class="col-filter-nb"><?= $fmNb ?></span><?php endif; ?></summary>
    <?php // Les moitiés du panneau (filtres de colonne, puis l'éventuel bloc
          // supplémentaire) vivent dans un conteneur commun : c'est LUI que le
          // CSS détache en panneau flottant sous la toolbar, sinon chacune se
          // détacherait au même point d'ancrage et elles se superposeraient. ?>
    <div class="filtres-mobile-panneau">
        <div class="filters carte-filters filters-more-body"><?= $fmColonnes ?></div>
        <?= $fmExtra ?>
        <?php if ($fmActifs !== ''): ?>
        <?php // Après tous les contrôles, comme la bande de ?p=mailing_campagne
              // sous sa toolbar : elle résume l'ensemble des filtres actifs. ?>
        <div class="filtres-ciblage-actifs"><?= $fmActifs ?></div>
        <?php endif; ?>
    </div>
</details>
<?php unset($fmColonnes, $fmActifs, $fmExtra, $fmClasse, $fmNb); ?>
