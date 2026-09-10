<?php
/** @var ?array $campagne */ /** @var array $projets */ /** @var array $spectacles */
/** @var array $criteres */ /** @var array $apercu */ /** @var array $retenues */
/** @var array $ajouts */
/** @var bool $previsualise */ /** @var array $tags */ /** @var array $campagnesDispo */
/** @var array $regions */
/** @var array $grandesRegions */ /** @var array $villes */ /** @var array $categoriesPourSelect */
/** @var array $nbEvenements */ /** @var ?string $err */
// Création / modification d'une campagne. Le ciblage est celui du mailing
// groupé — même question, même code (mailing_criteres_depuis) — mais son
// résultat n'est qu'une PROPOSITION : chaque structure garde sa case, qu'on
// décoche pour la sortir de la campagne.
$id = (int) ($campagne['id'] ?? 0);
$val = fn (string $c, $d = '') => e((string) ($campagne[$c] ?? $d));
$spectacleLabels = [];
foreach ($spectacles as $sp) { $spectacleLabels[(int) $sp['id']] = $sp['nom']; }

// Étiquettes des filtres de ciblage, mêmes composants que ?p=structures.
$labels = fn (array $vals): array => array_combine($vals, $vals) ?: [];
$categorieLabels = [];
foreach ($categoriesPourSelect as $c) {
    $categorieLabels[(int) $c['id']] = str_repeat("\u{00A0}\u{00A0}", (int) ($c['profondeur'] ?? 0)) . $c['nom'];
}
// « Aucun » en tête des deux listes de liaison, comme sur ?p=structures
// (views/_structures_filtres.php) : « lesquelles n'ont encore aucun tag ? »,
// « lesquelles ne sont dans aucune campagne ? » sont justement les questions
// qu'on pose en composant un démarchage.
$tagLabels = [];
foreach ($tags as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
if ($tagLabels) { $tagLabels = ['aucun' => 'Aucun tag'] + $tagLabels; }
$campagneLabels = [];
foreach ($campagnesDispo as $c) { $campagneLabels[(int) $c['id']] = $c['nom']; }
if ($campagneLabels) { $campagneLabels = ['aucun' => 'Aucune campagne'] + $campagneLabels; }
// Statut : seulement les statuts contactables. Un ciblage ne sort jamais de
// ceux-là (mailing_structures_eligibles()) — proposer « Inactif » aurait offert
// un filtre qui ne rend jamais rien.
$statutLabels = [];
foreach (STRUCTURE_STATUTS_CONTACTABLES as $st) { $statutLabels[$st] = structure_statut_libelle($st); }
$paysLabels = [];
foreach (array_keys($grandesRegions) as $p) { $paysLabels[$p] = $p; }
$grandeRegionLabels = [];
foreach ($grandesRegions as $pays => $rs) { foreach ($rs as $r) { $grandeRegionLabels[$r] = $r; } }

// Les filtres pilotent l'URL (GET) et non le formulaire d'enregistrement :
// prévisualiser ne doit rien écrire.
//
// Ce qui est déjà saisi voyage AVEC eux, en paramètres reportés dans chaque
// panneau : sans cela, cocher une catégorie rechargeait la page et effaçait le
// nom, les dates et les projets qu'on venait de choisir. La route les relit
// (route_campagne_form) et réaffiche cette version-là plutôt que celle de la base.
$base = ['id' => $id ?: null, 'previsualiser' => '1'];
$saisie = array_filter([
    'nom' => (string) ($campagne['nom'] ?? ''),
    'date_debut' => (string) ($campagne['date_debut'] ?? ''),
    'date_fin' => (string) ($campagne['date_fin'] ?? ''),
    'spectacle_ids' => array_map('strval', $projets),
    // Les structures ajoutées à la main voyagent avec le reste : un entonnoir
    // recharge la page, et sans elles l'ajout disparaîtrait au filtre suivant.
    'ajout' => array_map('strval', $ajouts),
]);
$criteresActifs = array_filter([
    'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'],
    'campagne_id' => $criteres['campagne_id'], 'statut' => $criteres['statut'],
    'pays' => $criteres['pays'], 'grande_region' => $criteres['grande_region'],
    'departement_canton' => $criteres['departement_canton'], 'ville' => $criteres['ville'],
]);
$tousFiltres = array_filter($criteresActifs + $saisie + array_filter($base));
$autres = autres_filtres_fn($tousFiltres);
// Le formulaire d'ajout emporte l'état de la page, moins « previsualiser »
// tant qu'aucun critère n'est posé : sans critère, prévisualiser veut dire
// TOUTES les structures — ce n'est pas ce qu'on demande en cherchant un nom.
$ajoutParams = $tousFiltres;
if (!$criteresActifs) {
    unset($ajoutParams['previsualiser']);
}
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
    <?= hidden_inputs_html($criteresActifs) ?>

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
        <?= filtre_colonne_html('campagne_form', 'statut', $statutLabels, $criteres['statut'], $autres('statut'), 'Statut') ?>
        <?= filtre_colonne_html('campagne_form', 'categorie_id', $categorieLabels, $criteres['categorie_id'], $autres('categorie_id'), 'Catégorie') ?>
        <?= $tagLabels ? filtre_colonne_html('campagne_form', 'tag_id', $tagLabels, $criteres['tag_id'], $autres('tag_id'), 'Tags') : '' ?>
        <?= $campagneLabels ? filtre_colonne_html('campagne_form', 'campagne_id', $campagneLabels, $criteres['campagne_id'], $autres('campagne_id'), 'Campagnes') : '' ?>
        <?= filtre_colonne_html('campagne_form', 'pays', $paysLabels, $criteres['pays'], $autres('pays'), 'Pays') ?>
        <?= filtre_colonne_html('campagne_form', 'grande_region', $grandeRegionLabels, $criteres['grande_region'], $autres('grande_region'), 'Région') ?>
        <?= filtre_colonne_html('campagne_form', 'departement_canton', $labels($regions), $criteres['departement_canton'], $autres('departement_canton'), 'Département / canton') ?>
        <?= filtre_colonne_html('campagne_form', 'ville', $labels($villes), $criteres['ville'], $autres('ville'), 'Ville') ?>
    </div>
    <?php // Chercher une structure par son nom, pour l'ajouter à la sélection
          // sans avoir à trouver le filtre qui la fait apparaître — une salle
          // dont on se souvient au dernier moment n'a pas de critère commun
          // avec le reste du ciblage. Formulaire GET, comme les entonnoirs :
          // l'ajout part dans l'URL et la page se recompose avec. ?>
    <form method="get" class="filters ajout-structure" id="campagne-ajout-form">
        <input type="hidden" name="p" value="campagne_form">
        <?= hidden_inputs_html($ajoutParams) ?>
        <div class="cat-search" id="campagne-ajout-search">
            <input type="text" class="cat-search-input" placeholder="Ajouter une structure par son nom…" autocomplete="off"
                   aria-label="Ajouter une structure à la sélection">
            <input type="hidden" class="cat-search-val" name="ajout[]" value="">
            <ul class="cat-search-list" hidden role="listbox"></ul>
        </div>
        <?php // Repli sans JavaScript : la liste des noms se peuple au premier
              // focus (?p=lieux_options), ce bouton reste le moyen d'envoyer
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
    <p class="muted small mb-8" id="campagne-compte"></p>
    <?php
    // À la modification, seules les structures déjà retenues sont cochées ;
    // à la création, tout le ciblage l'est.
    $stStructures = $apercu;
    $stVide = 'Aucune structure ne correspond à ces filtres.';
    $stCheck = [
        'name' => 'structure_ids[]', 'form' => 'campagne-form',
        'classe' => 'campagne-case', 'tout' => 'campagne-tout',
        // Une structure ajoutée à la main est cochée d'office : on ne la
        // cherche pas par son nom pour la laisser hors de la campagne.
        'coche' => fn (array $d): bool => in_array((int) $d['id'], $ajouts, true)
            || !$id || !$retenues || in_array((int) $d['id'], $retenues, true),
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

<script nonce="<?= e(csp_nonce()) ?>">
// Recherche d'une structure à ajouter. Les noms ne sont pas écrits dans la
// page : il y en a plusieurs milliers, et la page pèse déjà lourd. Ils sont
// chargés au PREMIER focus (?p=lieux_options, la route qui sert déjà le même
// choix à ?p=evenement_form), puis filtrés à la frappe par lassoInitCatSearch().
//
// Choisir une suggestion envoie le formulaire : l'ajout part dans l'URL avec
// les filtres et la saisie en cours, et la page revient avec la structure à sa
// place alphabétique, cochée. Sans JavaScript, la liste reste vide — le champ
// ne propose alors rien, et le ciblage par filtres reste le chemin.
(function () {
    const wrap = document.getElementById('campagne-ajout-search');
    const form = document.getElementById('campagne-ajout-form');
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
        fetch('?p=lieux_options', { headers: { 'Accept': 'application/json' } })
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

<script nonce="<?= e(csp_nonce()) ?>">
// Un panneau de filtre est un formulaire GET : il recharge la page avec ce qu'il
// porte, et rien d'autre. Les champs de la campagne y sont bien reportés en
// champs cachés — mais écrits AU RENDU, donc avec les valeurs que le serveur
// connaissait. Ce qu'on vient de taper sans avoir encore rien enregistré ne s'y
// trouve pas : appliquer un filtre effaçait alors le nom, les dates et les
// projets. On recopie donc l'état réel du formulaire au moment de l'envoi.
//
// Le report côté serveur reste en place : c'est le repli quand JavaScript
// manque, où l'on perd au pire ce qui n'a pas été enregistré.
(function () {
    const form = document.getElementById('campagne-form');
    if (!form) { return; }
    const champs = ['nom', 'date_debut', 'date_fin'];
    document.querySelectorAll('.col-filter-menu').forEach(panneau => {
        panneau.addEventListener('submit', () => {
            // On retire ce que le rendu avait posé avant d'y remettre l'actuel :
            // sans cela, deux valeurs partiraient pour le même nom.
            panneau.querySelectorAll('[data-report-campagne]').forEach(e => e.remove());
            champs.forEach(nom => {
                panneau.querySelectorAll('input[type="hidden"][name="' + nom + '"]').forEach(e => e.remove());
                const valeur = (form.elements[nom]?.value || '').trim();
                if (valeur !== '') { poser(panneau, nom, valeur); }
            });
            panneau.querySelectorAll('input[type="hidden"][name="spectacle_ids[]"]').forEach(e => e.remove());
            form.querySelectorAll('input[name="spectacle_ids[]"]:checked')
                .forEach(c => poser(panneau, 'spectacle_ids[]', c.value));
        });
    });
    function poser(panneau, nom, valeur) {
        const i = document.createElement('input');
        i.type = 'hidden';
        i.name = nom;
        i.value = valeur;
        i.setAttribute('data-report-campagne', '');
        panneau.appendChild(i);
    }
})();
</script>
