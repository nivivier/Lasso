<?php
// L'exemplaire UNIQUE du formulaire « ajouter à une campagne » d'une liste de
// structures : il est déplacé dans la cellule de la ligne dont on clique le
// « + », son structure_id renseigné à ce moment-là (lassoInitCampagneCellule(),
// assets/app.js). Hors du tableau au repos, pour ne peser qu'une fois.
//
// Une liste fermée — on rattache à une campagne existante, on n'en crée pas
// d'ici — donc un menu déroulant plutôt qu'un champ à suggestions.
//
// Partagé par ?p=structures et par le suivi d'une campagne, qui montrent tous
// deux la colonne « Campagnes ». Sans lui, le « + » et la croix de cette
// colonne n'ont rien à ouvrir et ne font rien.
//
// Attendu de l'appelant (préfixe « ca ») :
//   $caCampagnes (array) les campagnes où l'on peut ranger la structure.
//   $caRetour    (array) où revenir SANS JavaScript : ['retour' => 'structures'],
//                ou ['retour' => 'campagne', 'campagne_id' => N].
//   $caAction    (string) la route qui enregistre. Par défaut celle du
//                démarchage ; la liste des structures atteinte depuis la
//                recherche de fonds vise la sienne — une page ne montre jamais
//                les deux colonnes à la fois, un seul formulaire suffit donc.
$caCampagnes = $caCampagnes ?? [];
$caRetour = $caRetour ?? [];
$caAction = $caAction ?? '?p=structure_campagne';
?>
<?php // Exemplaire unique, comme le formulaire d'étiquette juste au-dessus :
      // déplacé dans la cellule de la ligne dont on clique le « + ». Une liste
      // fermée — on rattache à une campagne existante, on n'en crée pas d'ici —
      // avec un champ cherchable, comme les étiquettes. ?>
<form method="post" action="<?= e($caAction) ?>" class="linked-add linked-add-ligne campagne-ajouter-ligne" id="campagne-ajouter-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="">
    <?= hidden_inputs_html($caRetour) ?>
    <?php // Champ cherchable plutôt que menu déroulant, comme pour les
          // étiquettes : une association a vite trente campagnes, et l'on sait
          // laquelle on cherche. La liste reste FERMÉE — on n'en crée pas
          // d'ici —, d'où la valeur cachée que seule une sélection remplit. ?>
    <div class="cat-search campagne-search">
        <input type="text" class="cat-search-input" placeholder="Campagne…" autocomplete="off" aria-label="Campagne">
        <input type="hidden" name="campagne_id" class="cat-search-val" value="">
        <ul class="cat-search-list" hidden role="listbox">
            <?php foreach ($caCampagnes as $c): ?>
            <li data-val="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <button type="submit" class="btn btn-sm icon-only" title="Ajouter à cette campagne" aria-label="Ajouter à cette campagne"><?= icon('plus') ?></button>
    <button type="button" class="btn ghost btn-sm icon-only campagne-ajouter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
</form>
