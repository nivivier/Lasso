<?php /** @var array $lieux */ /** @var array $organisateurs */ /** @var string $recherche */ /** @var bool $modeClient */
/** @var string $type */ /** @var string $ville */ /** @var ?int $jaugeMin */ /** @var ?int $jaugeMax */
/** @var int $moisEvenement */ /** @var int $moisProg */ /** @var array $villesDispo */
/** @var string $pays */ /** @var string $grandeRegion */ /** @var array $grandesRegionsDispo */ /** @var string $statut */
/** @var array $categoriesLieu */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */
/** @var string $vue */ /** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var bool $nonLocalises */ /** @var string $flag */
$plusFiltres = $ville !== '' || $pays !== '' || $grandeRegion !== '' || $jaugeMin !== null || $jaugeMax !== null || $moisEvenement || $moisProg || $flag !== '';
$filtresActifs = $type !== '' || $recherche !== '' || $plusFiltres || $statut !== 'actif';
// Lien pour quitter le filtre « non localisés » (venu de la vue carte) sans
// perdre les autres filtres actifs.
$qsSansNonLocalises = $_GET;
unset($qsSansNonLocalises['non_localises']);
$lienQuitterNonLocalises = '?p=lieux&' . http_build_query($qsSansNonLocalises);
// Liens des onglets Liste/Carte : mêmes filtres actifs, seule la vue change.
// vue toujours explicite dans les deux sens (même « liste ») : c'est ce qui
// permet à filtre_persistant() (route_lieux()) de mémoriser le choix — un lien
// omettant vue= au profit du défaut ne mettrait à jour la session que dans un sens.
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['geocode']);
$lienVue = fn (string $v) => '?p=lieux&' . http_build_query($qsSansVue + ['vue' => $v]);
?>
<?php $actionUrl = '?p=lieux'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?php if ((int) ($_GET['lieuxBloquees'] ?? 0) > 0): ?><p class="err flash"><?= (int) $_GET['lieuxBloquees'] ?> lieu(x) non supprimé(s) : une structure y est liée.</p><?php endif; ?>
<?php if ($nonLocalises): ?>
    <p class="flash">Filtre : lieux dont la ville n'a pas pu être localisée sur la carte. <a href="<?= e($lienQuitterNonLocalises) ?>">Quitter ce filtre</a></p>
<?php endif; ?>
<div class="page-head-band<?= $vue === 'carte' ? ' carte-header' : '' ?>">
<div class="page-head">
    <div class="page-head-title">
        <h1>Lieux</h1>
        <div class="seg-picker" role="radiogroup" aria-label="Affichage">
            <a href="<?= e($lienVue('liste')) ?>" class="seg-btn <?= $vue === 'liste' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'liste' ? 'true' : 'false' ?>" title="Liste"><?= icon('rows-3') ?></a>
            <a href="<?= e($lienVue('carte')) ?>" class="seg-btn <?= $vue === 'carte' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'carte' ? 'true' : 'false' ?>" title="Carte"><?= icon('map') ?></a>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn" href="?p=lieu"><?= icon('plus') ?><span class="lbl"> Nouveau lieu</span></a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="lieux">
        <?php if ($vue === 'carte'): ?><input type="hidden" name="vue" value="carte"><?php endif; ?>
        <label>Type
            <select name="type" onchange="this.form.submit()">
                <option value="">Tous</option>
                <?php foreach ($categoriesLieu as $c): ?>
                    <option value="<?= e($c) ?>" <?= $type === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut" onchange="this.form.submit()">
                <option value="actif" <?= $statut === 'actif' ? 'selected' : '' ?>>Actifs</option>
                <option value="inactif" <?= $statut === 'inactif' ? 'selected' : '' ?>>Inactifs</option>
                <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
            </select>
        </label>
        <label class="search-label">
            <input type="search" name="q" id="lieux-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
        </label>
        <details class="filters-more" <?= $plusFiltres ? 'open' : '' ?>>
            <summary>Plus de filtres</summary>
            <div class="filters-more-body">
                <label>Ville
                    <select name="ville" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach ($villesDispo as $vl): ?>
                            <option value="<?= e($vl) ?>" <?= $ville === $vl ? 'selected' : '' ?>><?= e($vl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Pays
                    <select name="pays" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <?= pays_options_nom($pays) ?>
                    </select>
                </label>
                <?php if ($grandesRegionsDispo): ?>
                <label>Région
                    <select name="grande_region" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <?php foreach ($grandesRegionsDispo as $paysNom => $regions): ?>
                            <optgroup label="<?= e($paysNom) ?>">
                                <?php foreach ($regions as $gr): ?>
                                    <option value="<?= e($gr) ?>" <?= $grandeRegion === $gr ? 'selected' : '' ?>><?= e($gr) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="jauge-filtre">Jauge min
                    <input type="number" name="jauge_min" min="0" value="<?= $jaugeMin !== null ? (int) $jaugeMin : '' ?>" onchange="this.form.submit()" placeholder="200">
                </label>
                <label class="jauge-filtre">Jauge max
                    <input type="number" name="jauge_max" min="0" value="<?= $jaugeMax !== null ? (int) $jaugeMax : '' ?>" onchange="this.form.submit()" placeholder="1000">
                </label>
                <label>Mois d'événement
                    <select name="mois_evenement" onchange="this.form.submit()">
                        <option value="0">Tous</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $moisEvenement === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Mois de programmation
                    <select name="mois_prog" onchange="this.form.submit()">
                        <option value="0">Tous</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $moisProg === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Flag
                    <select name="flag" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <option value="aucun" <?= $flag === 'aucun' ? 'selected' : '' ?>>Non marqués</option>
                        <option value="star" <?= $flag === 'star' ? 'selected' : '' ?>>Étoile</option>
                        <?php /* Cœur temporairement désactivé (voir route_lieu_flag()) */ ?>
                    </select>
                </label>
            </div>
        </details>
    </form>
</div>
</div>

<?php if ($vue === 'carte'): ?>
    <?php require __DIR__ . '/_lieux_carte.php'; ?>
<?php elseif (!$lieux): ?>
    <?php if ($filtresActifs): ?>
        <p class="muted">Aucune salle ni festival ne correspond à ces critères.</p>
    <?php else: ?>
        <p class="muted">Aucune salle ni festival pour l'instant.</p>
    <?php endif; ?>
<?php else: ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=lieux">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="type">Modifier le type</option>
            <option value="ville">Modifier la ville</option>
            <option value="departement_canton">Modifier le département / canton</option>
            <option value="pays">Modifier le pays</option>
            <option value="flag">Modifier le flag</option>
            <option value="delete">Supprimer</option>
        </select>

        <span class="bulk-field" data-for="type" hidden>
            <select name="bulk_type" class="inline-year-select">
                <?php foreach ($categoriesLieu as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
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
        <span class="bulk-field" data-for="flag" hidden>
            <select name="bulk_flag" class="inline-year-select">
                <option value="">Aucun</option>
                <option value="star">Étoile</option>
                <?php /* Cœur temporairement désactivé (voir route_lieu_flag()) */ ?>
            </select>
        </span>

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr>
        <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
        <th>Nom</th><th>Organisateur</th><th>Ville</th><th>Type</th><th>Jauge</th><th>Événement</th><th>Programmation</th><?php if (module_actif('evenements')): ?><th>Événements</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($lieux as $l): ?>
        <tr class="row-link <?= (int) ($l['actif'] ?? 1) ? '' : 'inactif' ?>" tabindex="0" role="link" data-href="?p=lieu&id=<?= (int) $l['id'] ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $l['id'] ?>" form="bulkform" class="row-check" onclick="event.stopPropagation()"></td>
            <td><?= flag_toggle_html('lieu', (int) $l['id'], (string) ($l['flag'] ?? '')) ?> <strong><?= e($l['nom']) ?></strong><?php if (!(int) ($l['actif'] ?? 1)): ?> <span class="badge muted-badge">inactif</span><?php endif; ?></td>
            <td class="small">
                <?php $orgs = $organisateurs[(int) $l['id']] ?? []; ?>
                <?php if ($orgs): ?>
                    <?php foreach ($orgs as $i => $org): ?><?= $i ? ', ' : '' ?><a href="<?= url_avec_retour('?p=structure&id=' . $org['id'], 'lieux') ?>" onclick="event.stopPropagation()"><?= e($org['nom']) ?></a><?php endforeach; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="small">
                <?php $villeHtml = ville_departement_canton_html((string) $l['ville'], pays_drapeau_nom((string) $l['pays']), (string) $l['pays'], (string) $l['departement_canton']); ?>
                <?= $villeHtml !== '' ? $villeHtml : '—' ?>
            </td>
            <td class="muted small col-petit"><?= e((string) $l['type']) ?></td>
            <td class="muted small">
                <?= $l['jauge_min'] || $l['jauge_max'] ? e(($l['jauge_min'] ?: '?') . '–' . ($l['jauge_max'] ?: '?')) : '—' ?>
            </td>
            <td class="muted small">
                <?= $l['mois_evenement_debut'] && $l['mois_evenement_fin'] ? e(mois_nom((int) $l['mois_evenement_debut']) . ' – ' . mois_nom((int) $l['mois_evenement_fin'])) : '—' ?>
            </td>
            <td class="muted small">
                <?= $l['mois_debut'] && $l['mois_fin'] ? e(mois_nom((int) $l['mois_debut']) . ' – ' . mois_nom((int) $l['mois_fin'])) : '—' ?>
            </td>
            <?php if (module_actif('evenements')): ?>
            <td class="muted small"><?php $ne = (int) ($nbEvenements[(int) $l['id']] ?? 0); echo $ne > 0 ? $ne : '—'; ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>
<?php endif; ?>
<script>
<?php if ($modeClient): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#lieux-search',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('lieux-search'));
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
        } else {
            submit.textContent = 'Modifier la sélection';
            submit.classList.remove('danger');
        }
    }
    action.addEventListener('change', syncAction);
    syncAction();

    document.getElementById('bulkform').addEventListener('submit', e => {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (action.value === 'delete' && !confirm('Supprimer ' + n + ' lieu(x) ? Cette action est irréversible.')) {
            e.preventDefault();
        }
    });
})();
</script>
