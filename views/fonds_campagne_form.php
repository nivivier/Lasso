<?php
/** @var ?array $campagne */ /** @var array $projets */ /** @var array $projetsDispo */
/** @var array $axes */ /** @var array $criteres */ /** @var array $apercu */
/** @var array $retenues */ /** @var array $ajouts */ /** @var bool $previsualise */
/** @var array $tags */ /** @var array $campagnesDispo */ /** @var array $regions */
/** @var array $grandesRegions */ /** @var array $villes */
/** @var array $categoriesPourSelect */ /** @var array $nbEvenements */ /** @var ?string $err */
// Composer une campagne de recherche de fonds. La moitié basse de l'écran — choisir les
// bailleurs — est le ciblage du booking, au mot près : un bailleur est une
// structure, et la question « à qui s'adresse-t-on » n'a pas deux réponses.
$id = (int) ($campagne['id'] ?? 0);
$val = fn (string $c, $d = '') => e((string) ($campagne[$c] ?? $d));
$projetLabels = [];
// Les projets mis de côté sont rendus comme les autres et marqués : la case
// « Actifs seulement » décide de ce qu'on en voit, sans recharger la page.
$projetAttrs = [];
foreach ($projetsDispo as $sp) {
    $projetLabels[(int) $sp['id']] = $sp['nom'];
    if (!($sp['actif'] ?? true)) { $projetAttrs[(int) $sp['id']] = 'data-projet-inactif'; }
}

// Étiquettes des entonnoirs et report des paramètres : le même helper que
// ?p=booking_campagne_form. $saisie dit ce qui, sur CET écran, doit survivre au
// rechargement qu'impose un entonnoir.
$cibF = ciblage_filtres_vue([
    'criteres' => $criteres, 'categoriesPourSelect' => $categoriesPourSelect,
    'tags' => $tags, 'campagnesDispo' => $campagnesDispo,
    'grandesRegions' => $grandesRegions, 'ajouts' => $ajouts,
], [
    'nom'               => (string) ($campagne['nom'] ?? ''),
    'date_debut'        => (string) ($campagne['date_debut'] ?? ''),
    'date_fin'          => (string) ($campagne['date_fin'] ?? ''),
    'montant_minimal'   => (string) ($campagne['montant_minimal'] ?? ''),
    'montant_ideal'     => (string) ($campagne['montant_ideal'] ?? ''),
    'axe_analytique_id' => (string) ($campagne['axe_analytique_id'] ?? ''),
    'drive_url'         => (string) ($campagne['drive_url'] ?? ''),
    'notes'             => (string) ($campagne['notes'] ?? ''),
    'projet_ids'     => array_map('strval', $projets),
], $id);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Même charpente que la composition d'une campagne : la zone du module,
      // un en-tête, puis le tableau de sélection d'un bord à l'autre. ?>
<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="?p=fonds_campagnes"><?= icon('arrow-left') ?> Campagnes</a>

<?php if ($err === 'nom'): ?><p class="err flash">Le nom de la campagne est obligatoire.</p><?php endif; ?>

<div class="page-head">
    <h1><?= $id ? 'Modifier la campagne' : 'Nouvelle campagne de recherche de fonds' ?></h1>
    <?php // La suppression vit ICI, sur l'écran de modification, et pas sur le
          // suivi : celui-ci sert à travailler une campagne, on y clique cent
          // fois sans rien vouloir détruire (docs/UI.md § 3). Même place que sur
          // une campagne de démarchage — à gauche d'« Enregistrer ».
          //
          // La phrase dit ce que la cascade emporte : les dossiers et leurs
          // versements. Celle du démarchage rassure au contraire — là-bas, rien
          // d'autre que la campagne ne disparaît. ?>
    <?php if (peut_ecrire('fonds')): ?>
    <?php ob_start(); ?>
        <?php if ($id): ?>
        <form method="post" action="?p=fonds_campagne_supprimer" class="d-inline"
              data-confirm="Supprimer la campagne « <?= e((string) ($campagne['nom'] ?? '')) ?> » ?<?= $retenues ? ' Ses ' . count($retenues) . ' dossier(s) partent avec elle' : ' Son suivi part avec elle' ?> — montants, dates, pièces demandées et versements. Les bailleurs, eux, ne sont pas touchés.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la campagne"><?= icon('trash') ?></button>
        </form>
        <?php endif; ?>
    <?= entete_form_actions_html('fonds-campagne-form', '', ['libelle' => 'Enregistrer la campagne', 'avant' => (string) ob_get_clean()]) ?>
    <?php endif; ?>
</div>

<?php // La campagne d'abord — ce qu'on crée —, le ciblage ensuite. Les deux ne
      // peuvent pas tenir dans le même <form> : les filtres sont des
      // formulaires GET (prévisualiser ne doit rien écrire), et un formulaire
      // ne s'imbrique pas. Les cases des bailleurs se rattachent donc à
      // celui-ci par form="fonds-campagne-form". ?>
<form method="post" action="?p=fonds_campagne_enregistrer" class="card form" id="fonds-campagne-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php // Les critères repartent avec l'enregistrement : ils sont gardés en
          // mémoire sur la campagne, pour savoir d'où venait la sélection. ?>
    <?= hidden_inputs_html($cibF['criteresActifs']) ?>

    <div class="grid4">
        <label>Nom <input name="nom" value="<?= $val('nom') ?>" required placeholder="ex. Création 2027"></label>
        <?php // <div> et non <label> : voir choix_coches_html(). ?>
        <div class="field-group" id="fonds-projets">
            <?= projet_entete_actifs_html('Projet', '', '#fonds-projets', ' ' . info_tip(
                "Ce que cette campagne finance. L'axe analytique ci-dessous s'en déduit, puisque c'est le projet qui le porte."
            )) ?>
            <?= choix_coches_html('projet_ids', $projetLabels, $projets, 'Aucun projet', $projetAttrs) ?>
        </div>
        <label><span>Début <?= info_tip("Avant cette date, la campagne se prépare.") ?></span>
            <input type="date" name="date_debut" value="<?= $val('date_debut') ?>">
        </label>
        <label><span>Fin <?= info_tip("Passée cette date sans avoir tout déposé, la campagne est signalée en retard.") ?></span>
            <input type="date" name="date_fin" value="<?= $val('date_fin') ?>">
        </label>
    </div>

    <?php // Deux paliers, parce qu'une campagne vise deux montants : celui sans
          // lequel le projet ne se fait pas, et celui qui le ferait comme on le
          // voudrait. Un budget unique laissait croire qu'au-dessous de la barre
          // tout est perdu, et au-dessus qu'il n'y a plus rien à chercher. ?>
    <div class="grid2 mt-16">
        <label><span>Objectif minimal <?= info_tip(
            "Le plancher : en dessous, le projet ne se fait pas. C'est lui que marque le repère sur la jauge, "
            . "et lui seul que l'argent déjà obtenu doit franchir pour que la campagne soit gagnée."
        ) ?></span>
            <span class="pct-input">
                <input name="montant_minimal" type="text" inputmode="decimal"
                       value="<?= (float) ($campagne['montant_minimal'] ?? 0) > 0 ? e(number_format((float) $campagne['montant_minimal'], 2, '.', '')) : '' ?>">
                <span class="pct-suffix">CHF</span>
            </span>
        </label>
        <label><span>Objectif idéal <?= info_tip(
            "Ce qu'il faudrait pour faire le projet comme on le voudrait. C'est lui qui donne sa longueur à la "
            . "jauge : la barre peut ainsi dépasser le minimum sans déborder. Laissé vide, la jauge se cale sur le minimal."
        ) ?></span>
            <span class="pct-input">
                <input name="montant_ideal" type="text" inputmode="decimal"
                       value="<?= (float) ($campagne['montant_ideal'] ?? 0) > 0 ? e(number_format((float) $campagne['montant_ideal'], 2, '.', '')) : '' ?>">
                <span class="pct-suffix">CHF</span>
            </span>
        </label>
    </div>

    <div class="grid2 mt-16">
        <?php if ($axes): ?>
        <label><span>Axe analytique <?= info_tip(
            "Celui du projet financé, repris automatiquement si vous le laissez vide. Il portera la facture et les écritures de cette campagne."
        ) ?></span>
            <select name="axe_analytique_id"><?= options_axes_longues($axes, (int) ($campagne['axe_analytique_id'] ?? 0), '— Celui du projet —') ?></select>
        </label>
        <?php endif; ?>
        <label><span>Dossier partagé <?= info_tip(
            "L'adresse du dossier où vivent les pièces de cette campagne : budgets, lettres, décisions, bilans. "
            . "Lasso ne stocke aucun fichier — il garde le chemin qui y mène."
        ) ?></span>
            <input name="drive_url" type="url" value="<?= $val('drive_url') ?>" placeholder="https://…">
        </label>
    </div>

    <label class="mt-16">Remarques
        <textarea name="notes" rows="2"><?= $val('notes') ?></textarea>
    </label>
</form>

<?php
// Le ciblage : filtres, ajout par le nom, tableau à cocher. Le même bloc que
// la composition d'une campagne de démarchage.
$cibPage = 'fonds_campagne_form';
$cibForm = 'fonds-campagne-form';
$cibPrefixe = 'fonds-campagne';
$cibTitre = 'À qui demander';
$cibAide = "Les mêmes filtres que la liste des structures : un bailleur est une structure comme une autre. "
    . "Le résultat est une proposition — vous décochez ensuite celles que vous ne sollicitez pas.";
$cibDepuis = '&depuis=fonds';
require __DIR__ . '/_ciblage_structures.php';
?>

</div></div>
