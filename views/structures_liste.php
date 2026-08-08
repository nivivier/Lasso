<?php /** @var array $structures */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var array $categorieId */
/** @var array $pays */ /** @var array $departementCanton */ /** @var array $tagId */ /** @var array $statut */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var int $structBloquees */
/** @var ?int $tagBulk */ /** @var string $tagBulkAction */ /** @var string $tagBulkNom */
/** @var string $vue */ /** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var ?int $lieuJaugeMin */ /** @var ?int $lieuJaugeMax */
/** @var int $lieuMoisEvenement */ /** @var int $lieuMoisProg */ /** @var array $flag */
/** @var bool $nonLocalises */ /** @var array $avecEvenements */
// Liens des onglets Liste/Carte : mêmes filtres actifs, seule la vue change
// (voir views/lieux_liste.php pour le même principe).
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['geocode']);
$lienVue = fn (string $v) => '?p=structures&' . http_build_query($qsSansVue + ['vue' => $v]);
// Lien pour quitter le filtre « non localisées » (venu de la vue carte) sans
// perdre les autres filtres actifs — voir views/lieux_liste.php.
$qsSansNonLocalises = $_GET;
unset($qsSansNonLocalises['non_localises']);
$lienQuitterNonLocalises = '?' . http_build_query($qsSansNonLocalises);

// Filtres de colonne (EXPÉRIMENTAL, même mécanique que ?p=fiches — voir
// filtre_colonne_html()/filtre_colonne_actifs_html() dans lib/helpers.php) :
// Statut/Tags/Catégorie/Flag/Avec-événements, à la place des anciens <select>
// de la toolbar. Colonne Ville : porte à la fois Pays et Département/canton
// (deux entonnoirs séparés, un composant par filtre). Jauge/mois restent des
// champs scalaires dans « Plus de filtres », non concernés par ce filtrage.
$statutLabels = [];
foreach (STRUCTURE_STATUTS as $s) { $statutLabels[$s] = structure_statut_libelle($s); }
$tagLabels = [];
foreach ($tagsDispo as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
$categorieLabels = [];
foreach ($categoriesPourSelect as $cat) { $categorieLabels[(int) $cat['id']] = str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) . $cat['nom']; }
$flagLabels = ['aucun' => 'Non marquées', 'star' => 'Étoile', 'heart' => 'Cœur'];
$paysLabels = [];
foreach (array_unique(array_merge($pays, array_column(pays_liste(), 'nom'))) as $nom) { $paysLabels[$nom] = $nom; }
$departementCantonLabels = [];
foreach (array_unique(array_merge($departementCanton, $regionsDispo)) as $r) { $departementCantonLabels[$r] = $r; }
$avecEvenementsLabels = ['avec' => 'Avec événements liés', 'sans' => 'Sans événement lié'];
// $autresXxx : les AUTRES filtres actifs de la page (jamais celui-ci), à
// reporter en hidden inputs par chaque panneau — construits une fois depuis
// $tousFiltres plutôt qu'un littéral quasi identique par filtre. 'depuis' y
// est inclus (jamais exclu par $autresFiltres, vu qu'aucun appel n'exclut
// cette clé) : Structures est partagée par 3 groupes de nav (booking/
// facturation/evenements) et sans ça, soumettre un panneau de filtre — un
// simple <form method="get"> qui ne connaît que ses propres champs — perdait
// ?depuis=… en route, faisant retomber le rail/bandeau sur son groupe par
// défaut (voir nav_groupe_actif()) au lieu de rester dans le groupe de
// provenance.
$tousFiltres = ['categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton,
    'tag_id' => $tagId, 'statut' => $statut, 'flag' => $flag, 'avec_evenements' => $avecEvenements, 'q' => $recherche,
    'depuis' => (string) ($_GET['depuis'] ?? '')];
$autresFiltres = fn (string $cle): array => array_filter(array_diff_key($tousFiltres, [$cle => true]));
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php
// Structures est partagée par 3 groupes de nav (booking/facturation/evenements,
// voir nav_groupe_actif()) — reporté sur les liens vers une structure pour que
// le rail/bandeau y reste dans le même groupe de provenance une fois dessus.
$suffixeDepuis = $ntCle !== null ? '&depuis=' . $ntCle : '';
?>
<?php $actionUrl = '?p=structures'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?= filtre_non_localises_flash_html($nonLocalises, 'structures', $lienQuitterNonLocalises) ?>
<?php if ($tagBulk !== null): ?>
<p class="ok flash">
    <?php if ($tagBulk > 0): ?>
        Étiquette « <?= e($tagBulkNom) ?> » <?= $tagBulkAction === 'retrait' ? 'retirée de' : 'ajoutée à' ?> <strong><?= (int) $tagBulk ?></strong> structure(s).
    <?php else: ?>
        Aucune structure modifiée (étiquette <?= $tagBulkAction === 'retrait' ? 'déjà absente' : 'déjà présente' ?>).
    <?php endif; ?>
</p>
<?php endif; ?>
<?php if ($structBloquees): ?><p class="err flash"><?= (int) $structBloquees ?> structure(s) non supprimée(s) : des factures y sont rattachées.</p><?php endif; ?>
<div class="page-head-band<?= $vue === 'carte' ? ' carte-header' : '' ?>">
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e($ntLabel) ?></h1>
    </div>
    <?php require __DIR__ . '/_module_tabs_render.php'; ?>
</div>
</div>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="structures">
            <input type="hidden" name="vue" value="<?= e($vue) ?>">
            <?php if (($_GET['depuis'] ?? '') !== ''): ?><input type="hidden" name="depuis" value="<?= e((string) $_GET['depuis']) ?>"><?php endif; ?>
            <label class="search-label">
                <input type="search" name="q" id="structures-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
            </label>
            <?php $lieuFiltresActifs = $lieuJaugeMin !== null || $lieuJaugeMax !== null || $lieuMoisEvenement || $lieuMoisProg; ?>
            <details class="filters-more" <?= $lieuFiltresActifs ? 'open' : '' ?>>
                <summary title="Plus de filtres" aria-label="Plus de filtres"><?= icon('funnel-plus') ?></summary>
                <div class="filters-more-body">
                    <label class="jauge-filtre">Jauge min
                        <input type="number" name="lieu_jauge_min" min="0" value="<?= $lieuJaugeMin !== null ? (int) $lieuJaugeMin : '' ?>" onchange="this.form.submit()" placeholder="200">
                    </label>
                    <label class="jauge-filtre">Jauge max
                        <input type="number" name="lieu_jauge_max" min="0" value="<?= $lieuJaugeMax !== null ? (int) $lieuJaugeMax : '' ?>" onchange="this.form.submit()" placeholder="1000">
                    </label>
                    <label>Mois d'événement
                        <select name="lieu_mois_evenement" onchange="this.form.submit()">
                            <option value="0">Tous</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $lieuMoisEvenement === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>Mois de programmation
                        <select name="lieu_mois_prog" onchange="this.form.submit()">
                            <option value="0">Tous</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $lieuMoisProg === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>
            </details>
        </form>
        <?php if ($vue === 'carte'): ?>
        <!-- Vue carte : pas de tableau où accrocher un en-tête de colonne, les
             mêmes filtres que la vue liste (voir plus bas, thead) restent donc
             ici, dans la toolbar. -->
        <div class="filters carte-filters">
            <span class="col-th">Statut <?= filtre_colonne_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Catégorie <?= filtre_colonne_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Pays <?= filtre_colonne_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Département / canton <?= filtre_colonne_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton') + ['vue' => 'carte']) ?></span>
            <?php if ($tagsDispo): ?>
            <span class="col-th">Tags <?= filtre_colonne_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id') + ['vue' => 'carte']) ?></span>
            <?php endif; ?>
            <span class="col-th">Flag <?= filtre_colonne_html('structures', 'flag', $flagLabels, $flag, $autresFiltres('flag') + ['vue' => 'carte']) ?></span>
            <?php if (module_actif('evenements')): ?>
            <span class="col-th">Événements <?= filtre_colonne_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements') + ['vue' => 'carte']) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="head-actions">
            <div class="seg-picker" role="radiogroup" aria-label="Affichage">
                <a href="<?= e($lienVue('liste')) ?>" class="seg-btn <?= $vue === 'liste' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'liste' ? 'true' : 'false' ?>" title="Liste"><?= icon('rows-3') ?></a>
                <a href="<?= e($lienVue('carte')) ?>" class="seg-btn <?= $vue === 'carte' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'carte' ? 'true' : 'false' ?>" title="Carte"><?= icon('map') ?></a>
            </div>
            <a class="btn" href="?p=structure"><?= icon('user-plus') ?><span class="lbl"> Nouvelle structure</span></a>
        </div>
    </div>

<?php if ($vue === 'carte'): ?>
    <?php require __DIR__ . '/_structures_carte.php'; ?>
<?php else: ?>
<?php $filtresActifs = $recherche !== '' || $categorieId || $pays || $departementCanton || $tagId || $lieuFiltresActifs || $flag || $avecEvenements; ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="categorie">Modifier la catégorie</option>
            <option value="ville">Modifier la ville</option>
            <option value="departement_canton">Modifier le département / canton</option>
            <option value="pays">Modifier le pays</option>
            <option value="via">Modifier le « via »</option>
            <option value="flag">Modifier le flag</option>
            <?php if ($tagsDispo || module_actif('booking')): ?>
            <option value="tag_ajouter">Ajouter une étiquette</option>
            <option value="tag_retirer">Retirer une étiquette</option>
            <?php endif; ?>
            <option value="statut">Modifier le statut</option>
            <option value="fusionner">Fusionner (2 sélections ou plus)</option>
            <option value="delete">Supprimer</option>
        </select>

        <span class="bulk-field" data-for="categorie" hidden>
            <select name="bulk_categorie_id" class="inline-year-select">
                <?php foreach ($categoriesPourSelect as $cat): ?>
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
        <span class="bulk-field" data-for="flag" hidden>
            <select name="bulk_flag" class="inline-year-select">
                <option value="">Aucun</option>
                <option value="star">Étoile</option>
                <?php /* Cœur temporairement désactivé (voir route_structure_flag()) */ ?>
            </select>
        </span>
        <?php if ($tagsDispo || module_actif('booking')): ?>
        <span class="bulk-field" data-for="tag_ajouter" hidden>
            <input type="text" name="bulk_tag_ajouter" class="inline-year-select" list="bulk-tags-dispo"
                   placeholder="Étiquette à ajouter" autocomplete="off">
            <datalist id="bulk-tags-dispo">
                <?php foreach ($tagsDispo as $t): ?><option value="<?= e($t['nom']) ?>"><?php endforeach; ?>
            </datalist>
        </span>
        <span class="bulk-field" data-for="tag_retirer" hidden>
            <select name="bulk_tag_retirer" class="inline-year-select">
                <?php foreach ($tagsDispo as $t): ?>
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

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<?php $nbCols = 10 + (module_actif('evenements') ? 1 : 0); ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr>
        <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
        <th class="col-petit">
            <span class="col-th">
                Statut
                <?= filtre_colonne_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut')) ?>
            </span>
            <?= filtre_colonne_actifs_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut')) ?>
        </th>
        <th class="col-nom">
            <span class="col-th">
                Nom
                <?= filtre_colonne_html('structures', 'flag', $flagLabels, $flag, $autresFiltres('flag')) ?>
            </span>
            <?= filtre_colonne_actifs_html('structures', 'flag', $flagLabels, $flag, $autresFiltres('flag')) ?>
        </th>
        <th class="col-ville">
            <span class="col-th">
                Ville
                <?= filtre_colonne_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays')) ?>
                <?= filtre_colonne_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton')) ?>
            </span>
            <?= filtre_colonne_actifs_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays')) ?>
            <?= filtre_colonne_actifs_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton')) ?>
        </th>
        <th class="col-categorie">
            <span class="col-th">
                Catégorie
                <?= filtre_colonne_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id')) ?>
            </span>
            <?= filtre_colonne_actifs_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id')) ?>
        </th>
        <th class="col-petit">Structures liées</th>
        <th class="col-tags">
            <span class="col-th">
                Tags
                <?php if ($tagsDispo): ?><?= filtre_colonne_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id')) ?><?php endif; ?>
            </span>
            <?php if ($tagsDispo): ?><?= filtre_colonne_actifs_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id')) ?><?php endif; ?>
        </th>
        <th>Contact</th><th class="col-petit">Dernier contact</th>
        <th title="Factures liées" aria-label="Factures liées"><?= icon('receipt-swiss-franc') ?></th>
        <?php if (module_actif('evenements')): ?>
        <th class="col-evenements">
            <span class="col-th">
                <span title="Événements liés" aria-label="Événements liés"><?= icon('calendar') ?></span>
                <?= filtre_colonne_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements')) ?>
            </span>
            <?= filtre_colonne_actifs_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements')) ?>
        </th>
        <?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$structures): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted"><?= $filtresActifs ? 'Aucune structure ne correspond à cette recherche.' : "Aucune structure pour l'instant. Commencez par en ajouter une." ?></td></tr>
    <?php else: ?>
    <?php foreach ($structures as $d): ?>
        <tr class="row-link <?= $d['statut'] === 'inactif' ? 'inactif' : '' ?>" tabindex="0" role="link" data-href="?p=structure&id=<?= (int) $d['id'] ?><?= $suffixeDepuis ?><?= suffixe_retour_liste($recherche, $pgPage) ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" form="bulkform" class="row-check" onclick="event.stopPropagation()"></td>
            <td><span class="<?= e(structure_statut_icone_classe((string) $d['statut'])) ?>" title="<?= e(structure_statut_libelle((string) $d['statut'])) ?>"><?= icon(structure_statut_icone((string) $d['statut'])) ?></span></td>
            <td>
                <?= flag_toggle_html('structure', (int) $d['id'], (string) ($d['flag'] ?? '')) ?>
                <strong><?= e($d['nom']) ?></strong>
            </td>
            <td class="small">
                <?php $villeHtml = ville_departement_canton_html((string) $d['adresse_localite'], pays_drapeau_nom((string) $d['adresse_pays']), (string) $d['adresse_pays'], (string) $d['departement_canton']); ?>
                <?= $villeHtml !== '' ? $villeHtml : '—' ?>
            </td>
            <td><?= categorie_sous_categorie_html((string) $d['categorie'], (string) $d['sous_categorie']) ?></td>
            <td class="tiny">
                <?php
                    $lieesPaires = ($d['structures_liees'] ?? '') !== '' ? array_map(
                        fn ($p) => explode("\x1f", $p, 3) + ['', '', ''],
                        explode("\x1e", (string) $d['structures_liees'])
                    ) : [];
                ?>
                <?php if ($lieesPaires): ?>
                    <?php foreach ($lieesPaires as $i => [$ln, $lid, $ls]): ?><?= $i > 0 ? ', ' : '' ?><span class="ico-tiny"><?= icon($ls === 'organise' ? 'blocks' : 'building') ?></span> <a href="?p=structure&id=<?= (int) $lid ?><?= $suffixeDepuis ?>" onclick="event.stopPropagation()"><?= e((string) $ln) ?></a><?php endforeach; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="small">
                <?php
                    $tagsPaires = ($d['tags_noms'] ?? '') !== '' ? array_map(
                        fn ($p) => explode("\x1f", $p, 2) + ['', ''],
                        explode("\x1e", (string) $d['tags_noms'])
                    ) : [];
                ?>
                <?php foreach ($tagsPaires as [$tn, $tc]): ?><span class="badge"<?= badge_style_html((string) $tc) ?>><?= e((string) $tn) ?></span> <?php endforeach; ?>
                <?php if (!$tagsPaires): ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="tiny">
                <?php $contactsNoms = ($d['contacts_noms'] ?? '') !== '' ? explode("\x1e", (string) $d['contacts_noms']) : []; ?>
                <?= $contactsNoms ? e(implode(', ', $contactsNoms)) : '<span class="muted">—</span>' ?>
            </td>
            <td class="muted tiny"><?= $d['dernier_contact_le'] ? e(date('d.m.Y', strtotime($d['dernier_contact_le']))) : '—' ?></td>
            <td class="small">
                <?php if ((int) $d['nb_factures'] > 0): ?>
                    <a href="?p=facturation_liste&annee=0&statut=tous&q=<?= urlencode($d['nom']) ?>" onclick="event.stopPropagation()"><?= (int) $d['nb_factures'] ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </td>
            <?php if (module_actif('evenements')): ?>
            <td class="muted small"><?php $ne = (int) ($nbEvenements[(int) $d['id']] ?? 0); echo $ne > 0 ? $ne : '—'; ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($structures): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
<?php endif; ?>
</div></div>
<script>
<?php if ($modeClient && $vue !== 'carte'): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#structures-search',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('structures-search'));
<?php endif; ?>
lassoInitFlagToggle();

(function () {
    const bulkBar = document.getElementById('bulk-bar');
    if (!bulkBar) return;
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => {
            if (c.closest('tr').style.display !== 'none') c.checked = all.checked;
        });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    const action = document.getElementById('bulk-action');
    const submit = document.getElementById('bulk-submit');
    const fields = document.querySelectorAll('.bulk-field');
    function syncAction() {
        fields.forEach(f => { f.hidden = f.dataset.for !== action.value; });
        submit.disabled = action.value === '';
        if (action.value === 'delete') {
            submit.textContent = 'Supprimer la sélection';
            submit.classList.add('danger');
        } else if (action.value === 'fusionner') {
            submit.textContent = 'Fusionner la sélection';
            submit.classList.remove('danger');
        } else {
            submit.textContent = 'Modifier la sélection';
            submit.classList.remove('danger');
        }
    }
    action.addEventListener('change', syncAction);
    syncAction();

    document.getElementById('bulkform').addEventListener('submit', e => {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (action.value === 'delete' && !confirm('Supprimer ' + n + ' structure(s) ? Cette action est irréversible.')) {
            e.preventDefault();
        } else if (action.value === 'fusionner' && n < 2) {
            alert('Sélectionnez au moins deux structures à fusionner.');
            e.preventDefault();
        }
    });
})();
</script>
