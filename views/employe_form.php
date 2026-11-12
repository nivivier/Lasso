<?php /** @var ?array $emp */ /** @var ?string $err */ /** @var int $nbFiches */ /** @var array $personnesDispo */
$v = fn(string $k, $d = '') => e((string) ($emp[$k] ?? $d));
$isEdit = !empty($emp['id']);
$nbFiches = (int) ($nbFiches ?? 0);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour($isEdit ? '?p=employe&id=' . (int) $emp['id'] : '?p=employes', $isEdit ? 'Fiche employé' : 'Employés') ?>
<div class="page-head page-head-bande">
    <h1><?= $isEdit ? 'Modifier l\'employé' : 'Nouvel employé' ?></h1>
    <?php // La suppression vit ici, sur l'écran qui modifie l'employé, et pas sur
          // sa fiche — qu'on ouvre pour consulter ses décomptes. Rien à la
          // création, et rien non plus dès qu'une fiche de salaire existe : la
          // route la refuserait de toute façon.
          //
          // « Enregistrer » et « Annuler » tiennent l'en-tête eux aussi : cette
          // page EST un formulaire, il n'y a rien à lire qu'un crayon
          // ouvrirait. Sans droit d'écriture, le formulaire n'est pas rendu du
          // tout (plus bas), donc aucune commande ici non plus. ?>
    <?php if (peut_ecrire('salaires')): ?>
    <?php ob_start(); ?>
        <?php if ($isEdit && $nbFiches === 0): ?>
        <form method="post" action="?p=employe_supprimer" data-confirm="Supprimer définitivement cet employé ?" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer l'employé"><?= icon('trash') ?></button>
        </form>
        <?php endif; ?>
    <?= entete_form_actions_html('employe-form', '?p=employes', ['avant' => (string) ob_get_clean()]) ?>
    <?php endif; ?>
</div>


<?php if (!peut_ecrire('salaires')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=employe_form<?= $isEdit ? '&id=' . (int) $emp['id'] : '' ?>" class="card form" id="employe-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <?php // Reprendre une personne de l'annuaire (un contact de structure, un
          // compte) plutôt que de recréer son identité : la choisir préremplit
          // les champs ci-dessous, et l'emploi se rattache à elle. Seulement à
          // la création — un emploi ne change pas de personne. ?>
    <?php $personnesDispo = $personnesDispo ?? []; ?>
    <?php if (!$isEdit && $personnesDispo): ?>
    <?php $pidChoisi = (int) ($emp['personne_id'] ?? 0); ?>
    <div class="grid2">
        <label>Personne existante (facultatif)
            <span class="cat-search" id="employe-personne">
                <input type="text" class="cat-search-input" placeholder="Rechercher dans l'annuaire…" autocomplete="off">
                <input type="hidden" name="personne_id" class="cat-search-val" value="<?= $pidChoisi ?: '' ?>">
                <ul class="cat-search-list" hidden role="listbox">
                    <li data-val="">— Nouvelle personne —</li>
                    <?php foreach ($personnesDispo as $pd): ?>
                        <li data-val="<?= (int) $pd['id'] ?>"
                            <?php foreach (['prenom', 'nom', 'email', 'rue', 'npa_localite', 'date_naissance'] as $c): ?>
                                data-<?= str_replace('_', '-', $c) ?>="<?= e((string) $pd[$c]) ?>"
                            <?php endforeach; ?>><?= e(personne_nom($pd)) ?><?= $pd['email'] !== '' && personne_nom($pd) !== $pd['email'] ? ' · ' . e((string) $pd['email']) : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            </span>
        </label>
    </div>
    <?php endif; ?>

    <div class="grid2">
        <label>Prénom <input name="prenom" value="<?= $v('prenom') ?>" required></label>
        <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    </div>
    <div class="grid3">
  	 	<label>E-mail (optionnel) <input name="email" type="email" value="<?= $v('email') ?>" placeholder="prenom@exemple.ch"></label>
		<label>Rue <input name="rue" value="<?= $v('rue') ?>"></label>
        <label>NPA, localité <input name="npa_localite" value="<?= $v('npa_localite') ?>"></label>
    </div>
    <div class="grid3">
        <label>Numéro AVS <input name="numero_avs" value="<?= $v('numero_avs') ?>" placeholder="<?= !empty($emp['avs_illisible']) ? 'Illisible — à ressaisir' : '756.XXXX.XXXX.XX' ?>"></label>
		<label>Date de naissance <input name="date_naissance" type="date" value="<?= $v('date_naissance') ?>"></label>
        <label>Canton
            <select name="canton">
                <?php foreach (CANTONS as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($emp['canton'] ?? 'Genève') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="grid3">
        <label>Procédure de décompte
            <select name="procedure">
                <?php foreach (PROCEDURES as $p): ?>
                    <option value="<?= e($p) ?>" <?= ($emp['procedure'] ?? 'Ordinaire') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Supplément vacances <?= info_tip(
            "Le supplément vacances et l'impôt à la source sont des valeurs par défaut : elles peuvent être ajustées "
            . "sur chaque fiche de salaire. Le salaire horaire, lui, se définit ligne par ligne sur la fiche."
        ) ?></span>
            <select name="supplement_vacances">
                <?php foreach (SUPPLEMENTS_VACANCES as $val => $lib):
                    $sel = (string) ($emp['supplement_vacances'] ?? '0.0833') === $val
                        || (abs((float) ($emp['supplement_vacances'] ?? 0) - (float) $val) < 1e-9); ?>
                    <option value="<?= e($val) ?>" <?= $sel ? 'selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Impôt à la source (%) <?= info_tip(
            "Le supplément vacances et l'impôt à la source sont des valeurs par défaut : elles peuvent être ajustées "
            . "sur chaque fiche de salaire. Le salaire horaire, lui, se définit ligne par ligne sur la fiche."
        ) ?></span>
            <input name="impot_source_taux" type="text" inputmode="decimal"
                   value="<?= e(number_format((float) ($emp['impot_source_taux'] ?? 0) * 100, 2, '.', '')) ?>">
        </label>
    </div>

    <label class="check">
        <input type="checkbox" name="actif" value="1" <?= (!$isEdit || $emp['actif']) ? 'checked' : '' ?>>
        Employé actif (apparaît dans la création de fiches)
    </label>

</form>
<?php if (!$isEdit && $personnesDispo): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const wrap = document.getElementById('employe-personne');
    const form = document.getElementById('employe-form');
    // Choisir une personne recopie son identité dans les champs ; revenir à
    // « Nouvelle personne » les laisse tels quels (on a pu commencer à saisir).
    lassoInitCatSearch(wrap, {
        hydrateInitial: true,
        clearHiddenOnInput: true,
        onSelect: li => {
            if (li.dataset.val === '') return;
            ['prenom', 'nom', 'email', 'rue', 'npa_localite', 'date_naissance'].forEach(c => {
                form.elements[c].value = li.dataset[c.replace(/_(.)/g, (m, l) => l.toUpperCase())] || '';
            });
        },
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</div></div>
