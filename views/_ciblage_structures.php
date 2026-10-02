<?php
// Le bloc de CIBLAGE d'un écran qui compose une sélection de structures : les
// filtres de ?p=structures, le champ d'ajout par le nom, et le tableau où l'on
// coche. Partagé par la composition d'une campagne de démarchage et par celle
// d'une recherche de fonds — la question y est la même, « à qui s'adresse-t-on ».
// Les données viennent de ciblage_structures_preparer() et les étiquettes de
// ciblage_filtres_vue() (lib/booking.php).
//
// À poser avant d'inclure ce fichier :
//   $cibPage   la route (p=…) : les entonnoirs rechargent la page, ils doivent
//              revenir sur CELLE-CI et mémoriser leurs filtres sous son nom.
//   $cibForm   l'id du <form> d'enregistrement : les cases s'y rattachent par
//              form="…", les deux ne pouvant pas s'imbriquer (filtres en GET).
//   $cibPrefixe préfixe des id de CET écran (champ d'ajout, compteur) : le
//              script de page les retrouve par ces noms-là.
//   $cibTitre  le titre de la section ; $cibAide sa bulle d'explication.
//   $cibDepuis le suffixe ?depuis= des liens vers une fiche structure.
//   $cibF      le retour de ciblage_filtres_vue().
/** @var string $cibPage */ /** @var string $cibForm */ /** @var string $cibTitre */
/** @var string $cibAide */ /** @var string $cibDepuis */ /** @var array $cibF */
/** @var string $cibPrefixe */
/** @var array $criteres */ /** @var array $apercu */ /** @var array $retenues */
/** @var array $ajouts */ /** @var array $nbEvenements */ /** @var int $id */
?>
<?php // Ciblage : mêmes filtres que la liste des structures, et leur résultat
      // n'est qu'une PROPOSITION — chaque structure garde sa case, qu'on décoche
      // pour la sortir de la sélection. ?>
<?php // Pas de cadre autour des filtres : la zone du module en est déjà un, et
      // .filters posé nu s'en dessinerait un second (voir .toolbar > .filters,
      // assets/app.css). Même barre que ?p=structures. ?>
<h2 class="mt-22"><?= e($cibTitre) ?> <?= info_tip($cibAide) ?></h2>
<div class="toolbar">
    <div class="filters">
        <?= filtre_colonne_html($cibPage, 'statut', $cibF['statutLabels'], $criteres['statut'], ($cibF['autres'])('statut'), 'Statut') ?>
        <?= filtre_colonne_html($cibPage, 'categorie_id', $cibF['categorieLabels'], $criteres['categorie_id'], ($cibF['autres'])('categorie_id'), 'Catégorie') ?>
        <?= $cibF['tagLabels'] ? filtre_colonne_html($cibPage, 'tag_id', $cibF['tagLabels'], $criteres['tag_id'], ($cibF['autres'])('tag_id'), 'Tags') : '' ?>
        <?= $cibF['campagneLabels'] ? filtre_colonne_html($cibPage, 'campagne_id', $cibF['campagneLabels'], $criteres['campagne_id'], ($cibF['autres'])('campagne_id'), 'Campagnes') : '' ?>
        <?= filtre_colonne_html($cibPage, 'pays', $cibF['paysLabels'], $criteres['pays'], ($cibF['autres'])('pays'), 'Pays') ?>
        <?= filtre_colonne_html($cibPage, 'grande_region', $cibF['grandeRegionLabels'], $criteres['grande_region'], ($cibF['autres'])('grande_region'), 'Région') ?>
        <?= filtre_colonne_html($cibPage, 'departement_canton', ($cibF['labels'])($regions), $criteres['departement_canton'], ($cibF['autres'])('departement_canton'), 'Département / canton') ?>
        <?= filtre_colonne_html($cibPage, 'ville', ($cibF['labels'])($villes), $criteres['ville'], ($cibF['autres'])('ville'), 'Ville') ?>
    </div>
    <?php // Chercher une structure par son nom, pour l'ajouter à la sélection
          // sans avoir à trouver le filtre qui la fait apparaître — une salle
          // dont on se souvient au dernier moment n'a pas de critère commun
          // avec le reste du ciblage. Formulaire GET, comme les entonnoirs :
          // l'ajout part dans l'URL et la page se recompose avec. ?>
    <form method="get" class="filters ajout-structure" id="<?= e($cibPrefixe) ?>-ajout-form">
        <input type="hidden" name="p" value="<?= e($cibPage) ?>">
        <?= hidden_inputs_html($cibF['ajoutParams']) ?>
        <div class="cat-search" id="<?= e($cibPrefixe) ?>-ajout-search">
            <input type="text" class="cat-search-input" placeholder="Ajouter une structure par son nom…" autocomplete="off"
                   aria-label="Ajouter une structure à la sélection">
            <input type="hidden" class="cat-search-val" name="ajout[]" value="">
            <ul class="cat-search-list" hidden role="listbox"></ul>
        </div>
        <?php // Repli sans JavaScript : la liste des noms se peuple au premier
              // focus (?p=lieux_json), ce bouton reste le moyen d'envoyer
              // le choix si le clic sur une suggestion n'a pas déjà soumis. ?>
        <button type="submit" class="btn ghost btn-sm icon-only" title="Ajouter à la sélection"
                aria-label="Ajouter à la sélection"><?= icon('plus') ?></button>
    </form>
</div>

<?php // Le tableau est celui de ?p=structures, mêmes colonnes et même code
      // (views/_structures_table.php) : on choisit ici dans la liste qu'on a
      // l'habitude de lire, sans avoir à réapprendre où regarder. Il prend toute
      // la largeur pour la même raison que là-bas. ?>
<?php if (!$apercu): ?>
    <p class="muted">Choisissez des filtres ci-dessus pour composer la sélection, ou ajoutez une structure par son nom.</p>
<?php else: ?>
    <p class="muted small mb-8" id="<?= e($cibPrefixe) ?>-compte"></p>
    <?php
    // À la modification, seules les structures déjà retenues sont cochées ;
    // à la création, tout le ciblage l'est.
    $stStructures = $apercu;
    $stVide = 'Aucune structure ne correspond à ces filtres.';
    $stCheck = [
        'name' => 'structure_ids[]', 'form' => $cibForm,
        'classe' => 'campagne-case', 'tout' => 'campagne-tout',
        // Une structure ajoutée à la main est cochée d'office : on ne la
        // cherche pas par son nom pour la laisser hors de la sélection.
        'coche' => fn (array $d): bool => in_array((int) $d['id'], $ajouts, true)
            || !$id || !$retenues || in_array((int) $d['id'], $retenues, true),
    ];
    // Ni entonnoirs ni bouton de réinitialisation dans les en-têtes : le ciblage
    // se fait au-dessus, en un seul endroit. Le nom mène à la fiche, mais la
    // ligne entière n'est pas cliquable — ici on coche, on ne navigue pas.
    $stHref = fn (array $d): string => '?p=structure&id=' . (int) $d['id'] . $cibDepuis;
    $stSuffixeDepuis = $cibDepuis;
    $stTagsActifs = false;
    $stLigneCliquable = false;
    $stNbEvenements = $nbEvenements;
    // Pas de colonne « Factures » : on est dans le booking, et ?p=structures la
    // masque déjà quand on y arrive par ce module. Même tableau, même choix.
    $stMontreFactures = false;
    require __DIR__ . '/_structures_table.php';
    ?>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
// Recherche d'une structure à ajouter. Les noms ne sont pas écrits dans la
// page : il y en a plusieurs milliers, et la page pèse déjà lourd. Ils sont
// chargés au PREMIER focus (?p=lieux_json, la route qui sert déjà le même
// choix à ?p=evenement), puis filtrés à la frappe par lassoInitCatSearch().
//
// Choisir une suggestion envoie le formulaire : l'ajout part dans l'URL avec
// les filtres et la saisie en cours, et la page revient avec la structure à sa
// place alphabétique, cochée. Sans JavaScript, la liste reste vide — le champ
// ne propose alors rien, et le ciblage par filtres reste le chemin.
//
// Ce script vit ICI et non dans la page appelante : le ciblage sert deux
// écrans — une campagne de démarchage et une campagne de recherche de fonds —
// et le second avait le champ sans ce qui le fait chercher.
(function () {
    const wrap = document.getElementById('<?= e($cibPrefixe) ?>-ajout-search');
    const form = document.getElementById('<?= e($cibPrefixe) ?>-ajout-form');
    if (!wrap || !form || !window.lassoInitCatSearch) { return; }
    const champ = wrap.querySelector('.cat-search-input');
    const liste = wrap.querySelector('.cat-search-list');
    lassoInitCatSearch(wrap, {
        clearHiddenOnInput: true,
        onSelect: () => form.submit(),
    });
    let chargee = false;
    champ.addEventListener('focus', function () {
        if (chargee) { return; }
        chargee = true;
        fetch('?p=lieux_json', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(function (opts) {
                // Celles déjà dans le tableau ne sont pas proposées : les
                // rajouter ne ferait rien, et la suggestion serait un leurre.
                const dejaLa = new Set(
                    [...document.querySelectorAll('.campagne-case')].map(c => c.value)
                );
                const frag = document.createDocumentFragment();
                opts.forEach(function (o) {
                    if (dejaLa.has(String(o.id))) { return; }
                    const li = document.createElement('li');
                    li.dataset.val = o.id;
                    li.textContent = o.nom;
                    frag.appendChild(li);
                });
                liste.appendChild(frag);
                champ.dispatchEvent(new Event('input'));
            })
            .catch(function () { chargee = false; });
    });
})();
</script>
