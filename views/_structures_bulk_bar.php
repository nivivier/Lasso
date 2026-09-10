<?php
// La barre d'action groupée d'une liste de structures : cases à cocher dans le
// tableau (views/_structures_table.php, $stCheck), et cette barre qui apparaît
// dès qu'une ligne est cochée. Partagée par ?p=structures et par le suivi d'une
// campagne — même barre, mêmes actions, même annulation ; côté serveur c'est
// structures_bulk_appliquer() qui les applique.
//
// Attendu de l'appelant (préfixe « bb ») :
//   $bbAction      (string) l'URL où poster — la page elle-même, avec ce qu'il
//                  faut pour y revenir (l'id de la campagne, par exemple).
//   $bbTagsDispo   (array)  les tags existants, pour les deux actions de tag.
//   $bbCategories  (array)  structure_categories_pour_select(), pour « Catégorie ».
//
// Le comportement (les deux menus, l'icône du bouton, les confirmations) est
// dans lassoInitBulkBar(), assets/app.js : il est identique sur les deux pages.
$bbTagsDispo = $bbTagsDispo ?? [];
$bbCategories = $bbCategories ?? [];
?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="<?= e($bbAction) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php // « section » est la valeur que le serveur commute (voir
              // structures_bulk_appliquer()). Elle n'est plus portée par un <select> mais par
              // ce champ caché, alimenté par le JS depuis l'un OU l'autre des deux
              // menus : les sept modifications de champ tenaient auparavant sept
              // lignes dans une liste qui en comptait douze, ce qui la rendait longue
              // à parcourir pour trouver « Supprimer » ou « Fusionner ». ?>
        <input type="hidden" name="section" id="bulk-section" value="">

        <select id="bulk-action" class="inline-year-select" aria-label="Action groupée">
            <option value="">— Choisir une action —</option>
            <option value="modifier">Modifier…</option>
            <?php if ($bbTagsDispo || module_actif('booking')): ?>
            <option value="tag_ajouter">Ajouter un tag</option>
            <option value="tag_retirer">Retirer un tag</option>
            <?php endif; ?>
            <option value="fusionner">Fusionner</option>
            <option value="delete">Supprimer</option>
        </select>

        <?php // Second menu, révélé par « Modifier… » : le champ à changer. Les
              // valeurs sont exactement celles attendues par le serveur, inchangées. ?>
        <select id="bulk-champ" class="inline-year-select" aria-label="Champ à modifier" hidden>
            <option value="">— Choisir un champ —</option>
            <option value="statut">Statut</option>
            <option value="ville">Ville</option>
            <option value="departement_canton">Département / canton</option>
            <option value="pays">Pays</option>
            <option value="categorie">Catégorie</option>
            <option value="via">Connu via</option>
        </select>

        <span class="bulk-field" data-for="categorie" hidden>
            <select name="bulk_categorie_id" class="inline-year-select">
                <?php foreach ($bbCategories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="ville" hidden>
            <input type="text" name="bulk_ville" class="inline-year-select" placeholder="Nouvelle ville">
        </span>
        <span class="bulk-field" data-for="departement_canton" hidden>
            <input type="text" name="bulk_departement_canton" class="inline-year-select" placeholder="Nouveau département / canton">
        </span>
        <span class="bulk-field" data-for="pays" hidden>
            <select name="bulk_pays" class="inline-year-select"><?= pays_options_nom('') ?></select>
        </span>
        <span class="bulk-field" data-for="via" hidden>
            <input type="text" name="bulk_via" class="inline-year-select" placeholder="Nouveau « via »">
        </span>
        <?php if ($bbTagsDispo || module_actif('booking')): ?>
        <span class="bulk-field" data-for="tag_ajouter" hidden>
            <div class="cat-search tag-search">
                <input type="text" name="bulk_tag_ajouter" class="cat-search-input inline-year-select"
                       placeholder="Tag à ajouter" autocomplete="off">
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($bbTagsDispo as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </span>
        <span class="bulk-field" data-for="tag_retirer" hidden>
            <select name="bulk_tag_retirer" class="inline-year-select">
                <?php foreach ($bbTagsDispo as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= e($t['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php endif; ?>
        <span class="bulk-field" data-for="statut" hidden>
            <select name="bulk_statut" class="inline-year-select">
                <?php foreach (STRUCTURE_STATUTS as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(structure_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>

        <?php // Bouton en icône seule : le libellé « Modifier la sélection » prenait
              // toute la largeur d'un écran de téléphone, au point de réduire le champ
              // voisin à rien. Les TROIS états sont rendus ici plutôt qu'injectés en
              // JS : le sprite d'icônes ne contient que ce qui a été rendu côté
              // serveur, une icône seulement référencée depuis un script serait
              // introuvable. Le script se contente de basculer leur visibilité et le
              // libellé accessible. ?>
        <button type="submit" class="btn icon-only" id="bulk-submit" disabled
                title="Modifier la sélection" aria-label="Modifier la sélection">
            <span data-bulk-icone="modifier"><?= icon('save') ?></span>
            <span data-bulk-icone="supprimer" hidden><?= icon('trash') ?></span>
            <span data-bulk-icone="fusionner" hidden><?= icon('merge') ?></span>
        </button>
    </form>
</div>
