<?php /** @var array $structures */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var int $categorieId */
/** @var string $pays */ /** @var string $departementCanton */ /** @var int $tagId */ /** @var string $statut */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var int $structBloquees */
/** @var ?int $tagBulk */ /** @var string $tagBulkAction */ /** @var string $tagBulkNom */
/** @var string $vue */ /** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var ?int $lieuJaugeMin */ /** @var ?int $lieuJaugeMax */
/** @var int $lieuMoisEvenement */ /** @var int $lieuMoisProg */ /** @var string $flag */
/** @var bool $nonLocalises */ /** @var bool $avecEvenements */
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
        <h1>Structures</h1>
        <div class="seg-picker" role="radiogroup" aria-label="Affichage">
            <a href="<?= e($lienVue('liste')) ?>" class="seg-btn <?= $vue === 'liste' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'liste' ? 'true' : 'false' ?>" title="Liste"><?= icon('rows-3') ?></a>
            <a href="<?= e($lienVue('carte')) ?>" class="seg-btn <?= $vue === 'carte' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'carte' ? 'true' : 'false' ?>" title="Carte"><?= icon('map') ?></a>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn" href="?p=structure"><?= icon('user-plus') ?><span class="lbl"> Nouvelle structure</span></a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="structures">
        <input type="hidden" name="vue" value="<?= e($vue) ?>">
        <label>Catégorie
            <select name="categorie_id" onchange="this.form.submit()">
                <option value="0">Toutes</option>
                <?php foreach ($categoriesPourSelect as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categorieId === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut" onchange="this.form.submit()">
                <option value="actif" <?= $statut === 'actif' ? 'selected' : '' ?>>Actives</option>
                <option value="contact_privilegie" <?= $statut === 'contact_privilegie' ? 'selected' : '' ?>>Contacts privilégiés</option>
                <option value="ne_pas_contacter" <?= $statut === 'ne_pas_contacter' ? 'selected' : '' ?>>Ne pas contacter</option>
                <option value="inactif" <?= $statut === 'inactif' ? 'selected' : '' ?>>Inactives</option>
                <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Toutes</option>
            </select>
        </label>
        <label class="search-label">
            <input type="search" name="q" id="structures-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
        </label>
        <?php $lieuFiltresActifs = $lieuJaugeMin !== null || $lieuJaugeMax !== null || $lieuMoisEvenement || $lieuMoisProg; ?>
        <details class="filters-more" <?= ($pays !== '' || $departementCanton !== '' || $tagId || $lieuFiltresActifs || $flag !== '' || $avecEvenements) ? 'open' : '' ?>>
            <summary>Plus de filtres</summary>
            <div class="filters-more-body">
                <label>Pays
                    <select name="pays" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <?= pays_options_nom($pays) ?>
                    </select>
                </label>
                <label>Département / canton
                    <select name="departement_canton" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <?php foreach ($regionsDispo as $r): ?>
                            <option value="<?= e($r) ?>" <?= $departementCanton === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($tagsDispo): ?>
                <label>Étiquette
                    <select name="tag_id" onchange="this.form.submit()">
                        <option value="0">Toutes</option>
                        <?php foreach ($tagsDispo as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $tagId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="jauge-filtre">Jauge min du lieu
                    <input type="number" name="lieu_jauge_min" min="0" value="<?= $lieuJaugeMin !== null ? (int) $lieuJaugeMin : '' ?>" onchange="this.form.submit()" placeholder="200">
                </label>
                <label class="jauge-filtre">Jauge max du lieu
                    <input type="number" name="lieu_jauge_max" min="0" value="<?= $lieuJaugeMax !== null ? (int) $lieuJaugeMax : '' ?>" onchange="this.form.submit()" placeholder="1000">
                </label>
                <label>Mois d'événement du lieu
                    <select name="lieu_mois_evenement" onchange="this.form.submit()">
                        <option value="0">Tous</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $lieuMoisEvenement === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Mois de programmation du lieu
                    <select name="lieu_mois_prog" onchange="this.form.submit()">
                        <option value="0">Tous</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $lieuMoisProg === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Flag
                    <select name="flag" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <option value="aucun" <?= $flag === 'aucun' ? 'selected' : '' ?>>Non marquées</option>
                        <option value="star" <?= $flag === 'star' ? 'selected' : '' ?>>Étoile</option>
                        <?php /* Cœur temporairement désactivé (voir route_structure_flag()) */ ?>
                    </select>
                </label>
                <?php if (module_actif('evenements')): ?>
                <label class="check">
                    <input type="hidden" name="avec_evenements" value="0">
                    <input type="checkbox" name="avec_evenements" value="1" <?= $avecEvenements ? 'checked' : '' ?> onchange="this.form.submit()">
                    Avec événements liés
                </label>
                <?php endif; ?>
            </div>
        </details>
    </form>
</div>
</div>

<?php if ($vue === 'carte'): ?>
    <?php require __DIR__ . '/_structures_carte.php'; ?>
<?php elseif (!$structures): ?>
    <?php if ($recherche !== '' || $categorieId !== 0 || $pays !== '' || $departementCanton !== '' || $tagId || $lieuFiltresActifs || $flag !== '' || $avecEvenements): ?>
        <p class="muted">Aucune structure ne correspond à cette recherche.</p>
    <?php else: ?>
        <p class="muted">Aucune structure pour l'instant. Commencez par en ajouter une.</p>
    <?php endif; ?>
<?php else: ?>
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
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr>
        <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
        <th class="col-petit">Statut</th><th>Nom</th><th>Ville</th><th>Catégorie</th><th class="col-petit">Structures liées</th><th>Tags</th><th class="col-petit">Dernier contact</th><th title="Factures liées" aria-label="Factures liées"><?= icon('receipt-swiss-franc') ?></th><?php if (module_actif('evenements')): ?><th title="Événements liés" aria-label="Événements liés"><?= icon('calendar') ?></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($structures as $d): ?>
        <tr class="row-link <?= $d['statut'] === 'inactif' ? 'inactif' : '' ?>" tabindex="0" role="link" data-href="?p=structure&id=<?= (int) $d['id'] ?><?= suffixe_retour_liste($recherche, $pgPage) ?>">
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
            <td class="small">
                <?php
                    $lieesPaires = ($d['structures_liees'] ?? '') !== '' ? array_map(
                        fn ($p) => explode("\x1f", $p, 3) + ['', '', ''],
                        explode("\x1e", (string) $d['structures_liees'])
                    ) : [];
                ?>
                <?php if ($lieesPaires): ?>
                    <?php foreach ($lieesPaires as $i => [$ln, $lid, $ls]): ?><?= $i > 0 ? ', ' : '' ?><span class="ico-tiny"><?= icon($ls === 'organise' ? 'blocks' : 'building') ?></span> <a href="?p=structure&id=<?= (int) $lid ?>" onclick="event.stopPropagation()"><?= e((string) $ln) ?></a><?php endforeach; ?>
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
            <td class="muted small"><?= $d['dernier_contact_le'] ? e(date('d.m.Y', strtotime($d['dernier_contact_le']))) : '—' ?></td>
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
    </tbody>
</table>
</div>
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>
<?php endif; ?>
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
