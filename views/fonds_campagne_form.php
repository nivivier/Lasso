<?php
/** @var ?array $campagne */ /** @var array $projets */ /** @var array $spectacles */
/** @var array $axes */ /** @var array $criteres */ /** @var array $apercu */
/** @var array $retenues */ /** @var array $ajouts */ /** @var bool $previsualise */
/** @var array $tags */ /** @var array $campagnesDispo */ /** @var array $regions */
/** @var array $grandesRegions */ /** @var array $villes */
/** @var array $categoriesPourSelect */ /** @var array $nbEvenements */ /** @var ?string $err */
// Composer une recherche de fonds. La moitié basse de l'écran — choisir les
// bailleurs — est le ciblage du booking, au mot près : un bailleur est une
// structure, et la question « à qui s'adresse-t-on » n'a pas deux réponses.
$id = (int) ($campagne['id'] ?? 0);
$val = fn (string $c, $d = '') => e((string) ($campagne[$c] ?? $d));
$spectacleLabels = [];
foreach ($spectacles as $sp) { $spectacleLabels[(int) $sp['id']] = $sp['nom']; }

// Étiquettes des entonnoirs et report des paramètres : le même helper que
// ?p=campagne_form. $saisie dit ce qui, sur CET écran, doit survivre au
// rechargement qu'impose un entonnoir.
$cibF = ciblage_filtres_vue([
    'criteres' => $criteres, 'categoriesPourSelect' => $categoriesPourSelect,
    'tags' => $tags, 'campagnesDispo' => $campagnesDispo,
    'grandesRegions' => $grandesRegions, 'ajouts' => $ajouts,
], [
    'nom'               => (string) ($campagne['nom'] ?? ''),
    'date_debut'        => (string) ($campagne['date_debut'] ?? ''),
    'date_fin'          => (string) ($campagne['date_fin'] ?? ''),
    'montant_cible'     => (string) ($campagne['montant_cible'] ?? ''),
    'axe_analytique_id' => (string) ($campagne['axe_analytique_id'] ?? ''),
    'drive_url'         => (string) ($campagne['drive_url'] ?? ''),
    'notes'             => (string) ($campagne['notes'] ?? ''),
    'spectacle_ids'     => array_map('strval', $projets),
], $id);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Même charpente que la composition d'une campagne : la zone du module,
      // un en-tête, puis le tableau de sélection d'un bord à l'autre. ?>
<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="<?= $id ? '?p=fonds' : '?p=fonds' ?>"><?= icon('arrow-left') ?> Recherches</a>

<?php if ($err === 'nom'): ?><p class="err flash">Le nom de la recherche est obligatoire.</p><?php endif; ?>

<div class="page-head">
    <h1><?= $id ? 'Modifier la recherche' : 'Nouvelle recherche de fonds' ?></h1>
    <?php if (peut_ecrire('fonds')): ?>
    <?= entete_form_actions_html('fonds-campagne-form', '', ['libelle' => 'Enregistrer la recherche']) ?>
    <?php endif; ?>
</div>

<?php // La recherche d'abord — ce qu'on crée —, le ciblage ensuite. Les deux ne
      // peuvent pas tenir dans le même <form> : les filtres sont des
      // formulaires GET (prévisualiser ne doit rien écrire), et un formulaire
      // ne s'imbrique pas. Les cases des bailleurs se rattachent donc à
      // celui-ci par form="fonds-campagne-form". ?>
<form method="post" action="?p=fonds_campagne_enregistrer" class="card form" id="fonds-campagne-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php // Les critères repartent avec l'enregistrement : ils sont gardés en
          // mémoire sur la recherche, pour savoir d'où venait la sélection. ?>
    <?= hidden_inputs_html($cibF['criteresActifs']) ?>

    <div class="grid4">
        <label>Nom <input name="nom" value="<?= $val('nom') ?>" required placeholder="ex. Création 2027"></label>
        <?php // <div> et non <label> : voir choix_coches_html(). ?>
        <div class="field-group"><span>Projet <?= info_tip(
            "Ce que cette recherche finance. L'axe analytique ci-dessous s'en déduit, puisque c'est le projet qui le porte."
        ) ?></span>
            <?= choix_coches_html('spectacle_ids', $spectacleLabels, $projets, 'Aucun projet') ?>
        </div>
        <label><span>Début <?= info_tip("Avant cette date, la recherche se prépare.") ?></span>
            <input type="date" name="date_debut" value="<?= $val('date_debut') ?>">
        </label>
        <label><span>Fin <?= info_tip("Passée cette date sans avoir tout déposé, la recherche est signalée en retard.") ?></span>
            <input type="date" name="date_fin" value="<?= $val('date_fin') ?>">
        </label>
    </div>

    <div class="grid3 mt-16">
        <label><span>Budget à trouver <?= info_tip(
            "Le montant que cette recherche doit réunir. C'est lui qui donne son sens à la jauge : obtenu, en attente, reste à trouver."
        ) ?></span>
            <span class="pct-input">
                <input name="montant_cible" type="text" inputmode="decimal"
                       value="<?= (float) ($campagne['montant_cible'] ?? 0) > 0 ? e(number_format((float) $campagne['montant_cible'], 2, '.', '')) : '' ?>">
                <span class="pct-suffix">CHF</span>
            </span>
        </label>
        <?php if ($axes): ?>
        <label><span>Axe analytique <?= info_tip(
            "Celui du projet financé, repris automatiquement si vous le laissez vide. Il portera la facture et les écritures de cette recherche."
        ) ?></span>
            <select name="axe_analytique_id">
                <option value="">— Celui du projet —</option>
                <?php $axeChoisi = (int) ($campagne['axe_analytique_id'] ?? 0); ?>
                <?php foreach ($axes as $ax): ?>
                <option value="<?= (int) $ax['id'] ?>"<?= (int) $ax['id'] === $axeChoisi ? ' selected' : '' ?>>
                    <?= e(trim((string) ($ax['code'] ?? '')) !== '' ? $ax['code'] . ' — ' . $ax['libelle'] : (string) $ax['libelle']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label><span>Dossier partagé <?= info_tip(
            "L'adresse du dossier où vivent les pièces de cette recherche : budgets, lettres, décisions, bilans. "
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
