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
$caCampagnes = $caCampagnes ?? [];
$caRetour = $caRetour ?? [];
?>
<?php // Exemplaire unique, comme le formulaire d'étiquette juste au-dessus :
      // déplacé dans la cellule de la ligne dont on clique le « + ». Une liste
      // fermée — on rattache à une campagne existante, on n'en crée pas d'ici —
      // donc un menu déroulant plutôt qu'un champ à suggestions. ?>
<form method="post" action="?p=structure_campagne" class="linked-add campagne-ajouter-ligne" id="campagne-ajouter-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="">
    <?= hidden_inputs_html($caRetour) ?>
    <select name="campagne_id" aria-label="Campagne">
        <option value="">— Choisir une campagne —</option>
        <?php foreach ($caCampagnes as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn ghost" title="Ajouter à cette campagne" aria-label="Ajouter à cette campagne"><?= icon('plus') ?><span class="lbl"> Ajouter</span></button>
    <button type="button" class="btn ghost icon-only campagne-ajouter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
</form>
