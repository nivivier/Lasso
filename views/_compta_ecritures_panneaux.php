<?php
// Les deux singletons de la liste des écritures, posés après le tableau et
// repositionnés par le script : le panneau de ventilation analytique et le
// menu de lettrage partagé par toutes les lignes.
//
// Attendu de l'appelant : $axes, $feuillesSorted, $catPrefixById, $catLeafById.
?>
<!-- Panneau de ventilation analytique (singleton, positionné par JS) -->
<?php if ($axes): ?>
<div id="axe-panel" class="axe-panel" hidden aria-label="Ventilation analytique">
    <div id="axe-panel-rows"></div>
    <?php // Ce qui est réparti, et surtout ce qu'il RESTE à répartir : c'est le
          // chiffre qu'on cherche en ventilant, et le calculer de tête à chaque
          // ligne saisie est le genre de peine qu'un écran doit s'épargner. ?>
    <div class="axe-panel-total">
        <span id="axe-panel-sum">0.00</span> / <span id="axe-panel-ref"></span> CHF
        <span id="axe-panel-reste" class="axe-panel-reste"></span>
    </div>
    <div class="axe-panel-btns">
        <button type="button" id="axe-panel-add" class="btn ghost btn-sm"><?= icon('plus') ?> Ajouter</button>
        <button type="button" id="axe-panel-save" class="btn btn-sm"><?= icon('check') ?> Enregistrer</button>
        <button type="button" id="axe-panel-cancel" class="btn ghost btn-sm">Annuler</button>
    </div>
</div>
<?php endif; ?>

<?php // LE formulaire de lettrage, en un seul exemplaire pour toute la page.
      //
      // Il vivait dans CHAQUE ligne : jeton CSRF, section, identifiant et
      // valeur courante recopiés 235 fois, soit un cinquième du poids de la
      // page pour un formulaire dont un seul sert à la fois. C'est exactement
      // ce que ?p=structures avait corrigé pour son formulaire d'étiquette
      // (docs/DECISIONS.md § Poids des pages de liste) ; la colonne Catégorie
      // était restée en arrière.
      //
      // Le champ de saisie, lui aussi unique, se DÉPLACE dans la cellule
      // ouverte — le déplacer plutôt que le recréer conserve ses écouteurs. Il
      // revient ici dès que l'édition se referme, et la page retrouve l'état
      // qu'elle avait au chargement.
      //
      // Sans JavaScript, cette colonne ne se modifiait déjà pas : le crayon est
      // un <button type="button"> et le champ n'avait aucun bouton d'envoi.
      // Rien n'est donc perdu à le sortir des lignes. ?>
<?php if (peut_ecrire('compta')): ?>
<form method="post" action="?p=compta_ecritures<?= $qs ?>" id="ecr-cat-form" class="ecr-cat-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="lettrer">
    <input type="hidden" name="ids[]" id="ecr-cat-id" value="">
    <input type="hidden" name="plan_compte_id" class="row-cat-val" value="">
    <div class="row-field-inp" id="ecr-cat-inp">
        <div class="cat-prefix"></div>
        <input type="text" class="row-cat-input" aria-label="Catégorie de l'écriture" autocomplete="off" placeholder="— à lettrer —" value="">
    </div>
</form>
<?php endif; ?>

<?php // Les champs de l'édition en ligne d'une écriture manuelle, en un seul
      // exemplaire : le script les déplace dans les cellules de la ligne
      // ouverte, une à une, et rend à chacune son contenu en refermant.
      //
      // Un gabarit plutôt que des champs rendus dans chaque ligne : le
      // sélecteur de compte porte toutes ses options, et les recopier sur
      // chaque ligne aurait repris le poids qu'on vient de rendre à cet écran.
      //
      // Pas de champ CATÉGORIE : elle a sa propre colonne, qui l'enregistre
      // toute seule. L'avoir ici en double, c'était deux endroits pour un même
      // réglage, et une chance sur deux d'écraser l'un par l'autre.
      // Pas d'axe non plus, pour la même raison. ?>
<?php if (peut_ecrire('compta')): ?>
<template id="tpl-ecr-edit">
    <input type="date" class="ecr-champ" data-champ="date_op" required aria-label="Date de l'écriture">
    <select class="ecr-champ" data-champ="compte_bancaire_id" required aria-label="Compte bancaire">
        <?php foreach ($comptes as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['libelle']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" class="ecr-champ" data-champ="texte" required aria-label="Description">
    <input type="text" inputmode="decimal" class="ecr-champ ecr-champ-num" data-champ="montant" required aria-label="Montant (+ crédit, − débit)">
    <?php // Enregistrer, supprimer, annuler — dans la cellule d'actions, là où
          // l'on a cliqué pour ouvrir. L'ORDRE est celui de toute
          // l'application (docs/UI.md § 2d, et carte_actions_html() qui pose
          // « extra » entre les deux) : enregistrer mis en évidence, la
          // corbeille rouge que l'édition révèle, et la croix EN DERNIER — à
          // l'emplacement exact du crayon qui vient de l'ouvrir, pour qu'un
          // seul endroit ouvre et referme. ?>
    <span class="ecr-edit-actions" data-champ="actions">
        <button type="button" class="btn btn-sm icon-only ecr-ok-btn" title="Enregistrer" aria-label="Enregistrer l'écriture"><?= icon('save') ?></button>
        <button type="button" class="btn danger btn-sm icon-only ecr-suppr-btn" title="Supprimer cette écriture" aria-label="Supprimer cette écriture"><?= icon('trash') ?></button>
        <button type="button" class="btn ghost btn-sm icon-only ecr-annuler-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
    </span>
</template>
<?php endif; ?>

<!-- Dropdown partagé pour le lettrage individuel (repositionné par JS) -->
<ul id="row-cat-list" class="cat-search-list" hidden role="listbox">
    <li data-val="" data-prefix="" data-leaf="">— à lettrer —</li>
    <li data-val="ignore" data-prefix="" data-leaf="Ne pas lettrer">Ne pas lettrer</li>
    <?php $sensCourant = null; $grpCourant = null; foreach ($feuillesSorted as $f): $fid = (int) $f['id']; $grp = $catPrefixById[$fid] ?? ''; ?>
    <?php if ($f['sens'] !== $sensCourant): $sensCourant = $f['sens']; $grpCourant = null; ?><li class="cat-search-sens"><?= $sensCourant === 'produit' ? 'Recettes' : 'Dépenses' ?></li><?php endif; ?>
    <?php if ($grp !== $grpCourant): $grpCourant = $grp; if ($grp !== ''): ?><li class="cat-search-group"><?= e($grp) ?></li><?php endif; endif; ?>
        <li data-val="<?= $fid ?>" data-prefix="<?= e($catPrefixById[$fid] ?? '') ?>" data-leaf="<?= e($catLeafById[$fid] ?? $f['chemin']) ?>"><?= e($f['chemin']) ?></li>
    <?php endforeach; ?>
</ul>
