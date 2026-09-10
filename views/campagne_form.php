<?php
/** @var ?array $campagne */ /** @var array $projets */ /** @var array $spectacles */
/** @var array $criteres */ /** @var array $apercu */ /** @var array $retenues */
/** @var bool $previsualise */ /** @var array $tags */ /** @var array $regions */
/** @var array $grandesRegions */ /** @var array $villes */ /** @var array $categoriesPourSelect */
/** @var array $nbEvenements */ /** @var ?string $err */
// Création / modification d'une campagne. Le ciblage est celui du mailing
// groupé — même question, même code (mailing_criteres_depuis) — mais son
// résultat n'est qu'une PROPOSITION : chaque structure garde sa case, qu'on
// décoche pour la sortir de la campagne.
$id = $campagne ? (int) $campagne['id'] : 0;
$val = fn (string $c, $d = '') => e((string) ($campagne[$c] ?? $d));
$spectacleLabels = [];
foreach ($spectacles as $sp) { $spectacleLabels[(int) $sp['id']] = $sp['nom']; }

// Étiquettes des filtres de ciblage, mêmes composants que ?p=structures.
$labels = fn (array $vals): array => array_combine($vals, $vals) ?: [];
$categorieLabels = [];
foreach ($categoriesPourSelect as $c) {
    $categorieLabels[(int) $c['id']] = str_repeat("\u{00A0}\u{00A0}", (int) ($c['profondeur'] ?? 0)) . $c['nom'];
}
$tagLabels = [];
foreach ($tags as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
$paysLabels = [];
foreach (array_keys($grandesRegions) as $p) { $paysLabels[$p] = $p; }
$grandeRegionLabels = [];
foreach ($grandesRegions as $pays => $rs) { foreach ($rs as $r) { $grandeRegionLabels[$r] = $r; } }

// Les filtres pilotent l'URL (GET) et non le formulaire d'enregistrement :
// prévisualiser ne doit rien écrire. Les champs déjà saisis voyagent avec eux.
$base = ['id' => $id ?: null, 'previsualiser' => '1'];
$tousFiltres = array_filter([
    'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'],
    'pays' => $criteres['pays'], 'grande_region' => $criteres['grande_region'],
    'departement_canton' => $criteres['departement_canton'], 'ville' => $criteres['ville'],
] + array_filter($base));
$autres = autres_filtres_fn($tousFiltres);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Même charpente que les autres pages du module : la zone du module, un
      // en-tête de page, puis le tableau de sélection d'un bord à l'autre. ?>
<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="<?= $id ? '?p=campagne&id=' . $id : '?p=campagnes' ?>"><?= icon('arrow-left') ?> <?= $id ? 'Campagne' : 'Campagnes' ?></a>

<?php if ($err === 'nom'): ?><p class="err flash">Le nom de la campagne est obligatoire.</p><?php endif; ?>

<div class="page-head">
    <h1><?= $id ? 'Modifier la campagne' : 'Nouvelle campagne' ?></h1>
</div>

<?php // La campagne d'abord — ce qu'on crée —, le ciblage ensuite. Les deux ne
      // peuvent pas tenir dans le même <form> : les filtres sont des
      // formulaires GET (prévisualiser ne doit rien écrire), et un formulaire
      // ne s'imbrique pas. Les cases des structures se rattachent donc à
      // celui-ci par form="campagne-form", comme les droits de ?p=comptes. ?>
<form method="post" action="?p=campagne_enregistrer" class="card form" id="campagne-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php // Les critères repartent avec l'enregistrement : ils sont gardés en
          // mémoire sur la campagne, pour savoir d'où venait la sélection. ?>
    <?= hidden_inputs_html(array_filter([
        'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'],
        'pays' => $criteres['pays'], 'grande_region' => $criteres['grande_region'],
        'departement_canton' => $criteres['departement_canton'], 'ville' => $criteres['ville'],
    ])) ?>

    <div class="grid4">
        <label>Nom <input name="nom" value="<?= $val('nom') ?>" required placeholder="ex. Tournée automne 2026"></label>
        <label><span>Projet <?= info_tip("Les spectacles concernés. C'est par eux qu'une prise de contact est rattachée à la campagne : sans projet, la jauge reste à zéro.") ?></span>
            <?= choix_coches_html('spectacle_ids', $spectacleLabels, $projets, 'Aucun projet') ?>
        </label>
        <label><span>Début <?= info_tip("Avant cette date, la campagne se prépare : aucun message ne part.") ?></span>
            <input type="date" name="date_debut" value="<?= $val('date_debut') ?>">
        </label>
        <label><span>Fin <?= info_tip("Passée cette date sans avoir tout contacté, la campagne est signalée en retard.") ?></span>
            <input type="date" name="date_fin" value="<?= $val('date_fin') ?>">
        </label>
    </div>
</form>

<?php // Ciblage : mêmes filtres que la liste des structures, et leur résultat
      // n'est qu'une PROPOSITION — chaque structure garde sa case, qu'on décoche
      // pour la sortir de la campagne. ?>
<?php // Pas de cadre autour des filtres : la zone du module en est déjà un, et
      // .filters posé nu s'en dessinerait un second (voir .toolbar > .filters,
      // assets/app.css). Même barre que ?p=structures. ?>
<h2 class="mt-22">Qui contacter <?= info_tip(
        "Les mêmes filtres que la liste des structures. Le résultat est une proposition : vous décochez ensuite
        celles que vous ne voulez pas dans la campagne."
) ?></h2>
<div class="toolbar">
    <div class="filters">
        <?= filtre_colonne_html('campagne_form', 'categorie_id', $categorieLabels, $criteres['categorie_id'], $autres('categorie_id'), 'Catégorie') ?>
        <?= filtre_colonne_html('campagne_form', 'tag_id', $tagLabels, $criteres['tag_id'], $autres('tag_id'), 'Étiquette') ?>
        <?= filtre_colonne_html('campagne_form', 'pays', $paysLabels, $criteres['pays'], $autres('pays'), 'Pays') ?>
        <?= filtre_colonne_html('campagne_form', 'grande_region', $grandeRegionLabels, $criteres['grande_region'], $autres('grande_region'), 'Région') ?>
        <?= filtre_colonne_html('campagne_form', 'departement_canton', $labels($regions), $criteres['departement_canton'], $autres('departement_canton'), 'Département / canton') ?>
        <?= filtre_colonne_html('campagne_form', 'ville', $labels($villes), $criteres['ville'], $autres('ville'), 'Ville') ?>
    </div>
</div>

<?php // Le tableau est celui de ?p=structures, mêmes colonnes et même code
      // (views/_structures_table.php) : on choisit ici dans la liste qu'on a
      // l'habitude de lire, sans avoir à réapprendre où regarder. Il prend toute
      // la largeur pour la même raison que là-bas. ?>
<?php if (!$apercu): ?>
    <p class="muted">Choisissez des filtres ci-dessus pour composer la sélection.</p>
<?php else: ?>
    <p class="muted small mb-8" id="campagne-compte"></p>
    <?php
    // À la modification, seules les structures déjà retenues sont cochées ;
    // à la création, tout le ciblage l'est.
    $stStructures = $apercu;
    $stVide = 'Aucune structure ne correspond à ces filtres.';
    $stCheck = [
        'name' => 'structure_ids[]', 'form' => 'campagne-form',
        'classe' => 'campagne-case', 'tout' => 'campagne-tout',
        'coche' => fn (array $d): bool => !$id || !$retenues || in_array((int) $d['id'], $retenues, true),
    ];
    // Ni entonnoirs ni bouton de réinitialisation dans les en-têtes : le ciblage
    // se fait au-dessus, en un seul endroit. Le nom mène à la fiche, mais la
    // ligne entière n'est pas cliquable — ici on coche, on ne navigue pas.
    $stHref = fn (array $d): string => '?p=structure&id=' . (int) $d['id'] . '&depuis=booking';
    $stSuffixeDepuis = '&depuis=booking';
    $stTagsActifs = false;
    $stLigneCliquable = false;
    $stNbEvenements = $nbEvenements;
    // Pas de colonne « Factures » : on est dans le booking, et ?p=structures la
    // masque déjà quand on y arrive par ce module. Même tableau, même choix.
    $stMontreFactures = false;
    require __DIR__ . '/_structures_table.php';
    ?>
<?php endif; ?>

<div class="form-actions">
    <button type="submit" form="campagne-form"><?= icon('save') ?> Enregistrer la campagne</button>
</div>
</div></div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const tout = document.getElementById('campagne-tout');
    const cases = [...document.querySelectorAll('.campagne-case')];
    const compte = document.getElementById('campagne-compte');
    if (!cases.length) return;
    const majCompte = () => {
        const n = cases.filter(c => c.checked).length;
        compte.textContent = n + ' / ' + cases.length + (n > 1 ? ' retenues' : ' retenue');
        if (tout) tout.checked = n === cases.length;
    };
    tout?.addEventListener('change', () => { cases.forEach(c => { c.checked = tout.checked; }); majCompte(); });
    cases.forEach(c => c.addEventListener('change', majCompte));
    majCompte();
})();
</script>
