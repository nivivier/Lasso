<?php
// L'exemplaire UNIQUE du formulaire d'ajout de tag d'une liste de structures :
// il est déplacé dans la cellule de la ligne dont on clique le « + », son
// structure_id renseigné à ce moment-là (lassoInitTagAjout(), assets/app.js).
// Hors du tableau au repos, pour ne peser qu'une fois — sur 5900 lignes, un
// exemplaire par ligne coûtait 40 % du poids de la page.
//
// Partagé par ?p=structures et par le suivi d'une campagne, qui posent tous
// deux des tags depuis leur tableau.
//
// Attendu de l'appelant (préfixe « ta ») :
//   $taTags   (array) les tags existants, pour les suggestions.
//   $taRetour (array) où revenir SANS JavaScript : ['retour' => 'structures'],
//             ou ['retour' => 'campagne', 'campagne_id' => N]. Avec JavaScript,
//             la requête part en JSON et seule la cellule est remplacée.
$taTags = $taTags ?? [];
$taRetour = $taRetour ?? [];
?>
<?php // Exemplaire unique du formulaire d'ajout d'étiquette : déplacé dans la
      // cellule de la ligne cliquée à l'ouverture, son structure_id renseigné à
      // ce moment-là. Hors du tableau au repos, pour ne peser qu'une fois. ?>
<form method="post" action="?p=structure_tag_ajouter" class="linked-add tag-ajouter-ligne" id="tag-ajouter-form-liste" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="">
    <?= hidden_inputs_html($taRetour) ?>
    <div class="cat-search tag-search">
        <input type="text" name="nom" class="cat-search-input" placeholder="Tag…" autocomplete="off">
        <ul class="cat-search-list" hidden role="listbox">
            <?php foreach ($taTags as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php // Ce bouton ne sert qu'à CRÉER : choisir une étiquette existante dans
          // la liste l'enregistre au clic (voir lassoInitTagAjout()). D'où le
          // libellé, qui ne promet plus un simple « Ajouter ». ?>
    <button type="submit" class="btn ghost btn-sm icon-only" title="Créer ce tag" aria-label="Créer ce tag et l'ajouter"><?= icon('plus') ?></button>
    <button type="button" class="btn ghost btn-sm icon-only tag-ajouter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
</form>
