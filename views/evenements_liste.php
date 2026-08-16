<?php
/** @var array $evenements */ /** @var array $annee */ /** @var array $annees */
/** @var array $statutSuisa */ /** @var array $spectacleId */ /** @var array $statut */
/** @var array $visibilite */ /** @var array $spectacles */ /** @var array $spectaclesFiltre */
/** @var array $paysDisponibles */ /** @var array $pays */ /** @var array $salaries */ /** @var string $recherche */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var bool $modeClient */
/** @var ?int $prodExterneOk */ /** @var ?int $prodExterneBloques */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var string $vue */ /** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var bool $nonLocalises */
$termeSingulier = evenements_terme_spectacle(false);
// Liens des onglets Liste/Carte : mêmes filtres actifs, seule la vue change
// (voir views/lieux_liste.php pour le même principe).
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['geocode']);
$lienVue = fn (string $v) => '?p=evenements_liste&' . http_build_query($qsSansVue + ['vue' => $v]);
// Lien pour quitter le filtre « non localisés » (venu de la vue carte) sans
// perdre les autres filtres actifs — voir views/structures_liste.php.
$qsSansNonLocalises = $_GET;
unset($qsSansNonLocalises['non_localises']);
$lienQuitterNonLocalises = '?' . http_build_query($qsSansNonLocalises);

// Filtres de colonne (EXPÉRIMENTAL, même mécanique que ?p=fiches — voir
// filtre_colonne_html()/filtre_colonne_actifs_html() dans lib/helpers.php) :
// Date/Spectacle/Audience/Statut/Suisa/Salariés, et le filtre Pays (ex-toolbar)
// porté par la colonne Ville/salle. En vue liste, remplacent la toolbar de
// filtres ; en vue carte (pas de tableau, rien où accrocher un en-tête de
// colonne), les mêmes composants restent affichés dans la toolbar.
$anneeLabels = [];
foreach (array_unique(array_merge($annee, [(int) date('Y')], $annees)) as $a) { $anneeLabels[(int) $a] = (string) (int) $a; }
$statutLabels = [];
foreach (EVENEMENTS_STATUTS as $s) { $statutLabels[$s] = evenement_statut_libelle($s); }
$visibiliteLabels = [];
foreach (EVENEMENTS_VISIBILITES as $vi) { $visibiliteLabels[$vi] = evenement_visibilite_libelle($vi); }
$statutSuisaLabels = [];
foreach (EVENEMENTS_STATUTS_SUISA_FILTRE as $ss) { $statutSuisaLabels[$ss] = evenement_statut_suisa_libelle($ss); }
$spectacleLabels = ['-1' => 'Sans ' . mb_strtolower($termeSingulier)];
foreach ($spectaclesFiltre as $s) { $spectacleLabels[(int) $s['id']] = $s['nom']; }
$paysLabels = [];
foreach ($paysDisponibles as $code) { $paysLabels[$code] = pays_nom_depuis_code($code) ?: $code; }
$salariesLabels = ['oui' => 'Oui', 'non' => 'Non'];
// $autresFiltres('champ') : les AUTRES filtres actifs de la page (jamais
// celui-ci), à reporter en hidden inputs par chaque panneau — voir
// autres_filtres_fn(), lib/helpers.php.
$tousFiltres = ['annee' => $annee, 'spectacle_id' => $spectacleId, 'statut' => $statut, 'visibilite' => $visibilite,
    'statut_suisa' => $statutSuisa, 'pays' => $pays, 'salaries' => $salaries, 'q' => $recherche];
$autresFiltres = autres_filtres_fn($tousFiltres);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php $actionUrl = '?p=evenements_liste'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?= filtre_non_localises_flash_html($nonLocalises, 'événements', $lienQuitterNonLocalises) ?>
<?php if ($prodExterneOk): ?><p class="flash"><?= (int) $prodExterneOk ?> événement(s) passé(s) en « Production externe ».</p><?php endif; ?>
<?php if ($prodExterneBloques): ?><p class="err flash"><?= (int) $prodExterneBloques ?> événement(s) non modifié(s) : une prestation liée est déjà sur une fiche payée (figée, jamais modifiée).</p><?php endif; ?>
<?php $ntBandClasse = $vue === 'carte' ? 'carte-header' : null; require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar toolbar-opaque<?= $vue === 'carte' ? ' toolbar-carte' : '' ?>">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="evenements_liste">
            <input type="hidden" name="vue" value="<?= e($vue) ?>">
            <label class="search-label">
                <input type="search" name="q" id="evenements-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
            </label>
        </form>
        <?php if ($vue === 'carte'): ?>
        <!-- Vue carte : pas de tableau où accrocher un en-tête de colonne, les
             mêmes filtres que la vue liste (voir plus bas, thead) restent donc
             ici, dans la toolbar, ENTRE la recherche et .head-actions (ordre
             visuel voulu : recherche | filtres | boutons). .carte-filters
             est flex:1 1 auto (pas 100%) : il occupe l'espace restant sur la
             ligne 1 et laisse flex-wrap:wrap (hérité de .filters) rejeter ses
             PROPRES enfants (chaque filtre) sur une 2e ligne interne si ça ne
             tient pas — .head-actions reste sur la ligne 1, poussé à droite
             par margin-left:auto, quelle que soit la hauteur prise par
             .carte-filters. -->
        <div class="filters carte-filters">
            <span class="col-th">Date <?= filtre_colonne_html('evenements_liste', 'annee', $anneeLabels, $annee, $autresFiltres('annee') + ['vue' => 'carte']) ?></span>
            <span class="col-th"><?= e($termeSingulier) ?> <?= filtre_colonne_html('evenements_liste', 'spectacle_id', $spectacleLabels, $spectacleId, $autresFiltres('spectacle_id') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Pays <?= filtre_colonne_html('evenements_liste', 'pays', $paysLabels, $pays, $autresFiltres('pays') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Audience <?= filtre_colonne_html('evenements_liste', 'visibilite', $visibiliteLabels, $visibilite, $autresFiltres('visibilite') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Statut <?= filtre_colonne_html('evenements_liste', 'statut', $statutLabels, $statut, $autresFiltres('statut') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Suisa <?= filtre_colonne_html('evenements_liste', 'statut_suisa', $statutSuisaLabels, $statutSuisa, $autresFiltres('statut_suisa') + ['vue' => 'carte']) ?></span>
            <span class="col-th">Salariés <?= filtre_colonne_html('evenements_liste', 'salaries', $salariesLabels, $salaries, $autresFiltres('salaries') + ['vue' => 'carte']) ?></span>
        </div>
        <?php endif; ?>
        <div class="head-actions">
            <div class="seg-picker" role="radiogroup" aria-label="Affichage">
                <a href="<?= e($lienVue('liste')) ?>" class="seg-btn <?= $vue === 'liste' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'liste' ? 'true' : 'false' ?>" title="Liste"><?= icon('rows-3') ?></a>
                <a href="<?= e($lienVue('carte')) ?>" class="seg-btn <?= $vue === 'carte' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'carte' ? 'true' : 'false' ?>" title="Carte"><?= icon('map') ?></a>
            </div>
            <?php $exportQs = http_build_query([
                'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
                'statut' => $statut, 'visibilite' => $visibilite, 'pays' => $pays, 'salaries' => $salaries,
                'q' => $recherche,
            ]); ?>
            <a class="btn ghost" href="?p=evenements_export_suisa&<?= $exportQs ?>" title="Exporter les événements filtrés actuellement (SUISA + organisateur)">
                <?= icon('download') ?> <span class="lbl">Export SUISA</span>
            </a>
            <?php if (peut_ecrire('evenements')): ?>
            <a class="btn" href="?p=evenement"><?= icon('calendar-plus') ?><span class="lbl"> Nouvel événement</span></a>
            <?php endif; ?>
        </div>
    </div>

<?php if ($vue === 'carte'): ?>
    <?php require __DIR__ . '/_evenements_carte.php'; ?>
<?php else: ?>
<?php if (peut_ecrire('evenements')): ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=evenements_liste">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="delete">Supprimer</option>
            <option value="statut">Modifier le statut</option>
            <option value="visibilite">Modifier le type d'audience</option>
            <option value="spectacle">Modifier <?= mb_strtolower(e($termeSingulier)) ?></option>
            <option value="departement_canton">Modifier le département / canton</option>
            <option value="pays">Modifier le pays</option>
            <option value="suisa_applicable">Modifier si la SUISA s'applique</option>
            <option value="suisa_envoi">Modifier l'envoi SUISA</option>
            <option value="suisa_decompte">Modifier la date du décompte SUISA</option>
            <option value="production_externe">Modifier « Production externe »</option>
        </select>

        <span class="bulk-field" data-for="statut" hidden>
            <select name="bulk_statut" class="inline-year-select">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>"><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="visibilite" hidden>
            <select name="bulk_visibilite" class="inline-year-select">
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>"><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="spectacle" hidden>
            <select name="bulk_spectacle_id" class="inline-year-select">
                <option value="">— Aucun —</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="departement_canton" hidden>
            <input type="text" name="bulk_departement_canton" class="inline-year-select" placeholder="canton ou département">
        </span>
        <span class="bulk-field" data-for="pays" hidden>
            <select name="bulk_pays" class="inline-year-select">
                <option value="">—</option>
                <?= pays_options_code('') ?>
            </select>
        </span>
        <span class="bulk-field" data-for="suisa_applicable" hidden>
            <select name="bulk_suisa_applicable" class="inline-year-select">
                <option value="1">S'applique</option>
                <option value="0">Ne s'applique pas</option>
            </select>
        </span>
        <span class="bulk-field" data-for="suisa_envoi" hidden>
            <select name="bulk_suisa_envoye_a" class="inline-year-select">
                <option value="">—</option>
                <option value="suisa">Directement à la SUISA</option>
                <option value="organisateur">À l'organisateur</option>
            </select>
            <input type="date" name="bulk_suisa_envoye_le" class="inline-year-select">
        </span>
        <span class="bulk-field" data-for="suisa_decompte" hidden>
            <input type="date" name="bulk_suisa_decompte_le" class="inline-year-select">
        </span>
        <span class="bulk-field" data-for="production_externe" hidden>
            <select name="bulk_production_externe" class="inline-year-select" id="bulk-production-externe">
                <option value="1">Activer</option>
                <option value="0">Désactiver</option>
            </select>
        </span>

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<?php endif; ?>
<div class="table-scroll">
<table class="list list-wide evenements-liste">
    <?php $nbCols = 8 - (peut_ecrire('evenements') ? 0 : 1); ?>
    <thead>
        <tr>
            <?php if (peut_ecrire('evenements')): ?><th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th><?php endif; ?>
            <th class="col-date">
                <span class="col-th">
                    Date
                    <?= filtre_colonne_html('evenements_liste', 'annee', $anneeLabels, $annee, $autresFiltres('annee')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'annee', $anneeLabels, $annee, $autresFiltres('annee')) ?>
            </th>
            <th class="col-spectacle">
                <span class="col-th">
                    <?= e($termeSingulier) ?>
                    <?= filtre_colonne_html('evenements_liste', 'spectacle_id', $spectacleLabels, $spectacleId, $autresFiltres('spectacle_id')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'spectacle_id', $spectacleLabels, $spectacleId, $autresFiltres('spectacle_id')) ?>
            </th>
            <th class="col-ville">
                <span class="col-th">
                    Ville / salle
                    <?= filtre_colonne_html('evenements_liste', 'pays', $paysLabels, $pays, $autresFiltres('pays')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'pays', $paysLabels, $pays, $autresFiltres('pays')) ?>
            </th>
            <th class="col-audience">
                <span class="col-th">
                    Audience
                    <?= filtre_colonne_html('evenements_liste', 'visibilite', $visibiliteLabels, $visibilite, $autresFiltres('visibilite')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'visibilite', $visibiliteLabels, $visibilite, $autresFiltres('visibilite')) ?>
            </th>
            <th class="col-statut">
                <span class="col-th">
                    Statut
                    <?= filtre_colonne_html('evenements_liste', 'statut', $statutLabels, $statut, $autresFiltres('statut')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'statut', $statutLabels, $statut, $autresFiltres('statut')) ?>
            </th>
            <th class="col-suisa">
                <span class="col-th">
                    SUISA
                    <?= filtre_colonne_html('evenements_liste', 'statut_suisa', $statutSuisaLabels, $statutSuisa, $autresFiltres('statut_suisa')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'statut_suisa', $statutSuisaLabels, $statutSuisa, $autresFiltres('statut_suisa')) ?>
            </th>
            <th class="num col-salaries">
                <span class="col-th">
                    Salariés
                    <?= filtre_colonne_html('evenements_liste', 'salaries', $salariesLabels, $salaries, $autresFiltres('salaries')) ?>
                </span>
                <?= filtre_colonne_actifs_html('evenements_liste', 'salaries', $salariesLabels, $salaries, $autresFiltres('salaries')) ?>
            </th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$evenements): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted">Aucun événement pour cette sélection.</td></tr>
    <?php else: ?>
    <?php $moisPrecedent = null; foreach ($evenements as $ev):
        $moisCle = substr((string) $ev['date'], 0, 7); // "AAAA-MM"
        if ($moisCle !== $moisPrecedent):
            $moisPrecedent = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="8"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <?php
            $estAnnule = $ev['statut'] === 'annule';
            $drapeau = pays_drapeau((string) $ev['pays']);
            $festivalSalle = implode(', ', array_filter([$ev['festival'], $ev['salle']], fn ($v) => $v !== ''));
        ?>
        <?php $hrefLigne = '?p=evenement&id=' . (int) $ev['id'] . suffixe_retour_liste($recherche, $pgPage); ?>
        <tr class="row-link" tabindex="0" role="link" data-href="<?= e($hrefLigne) ?>">
            <?php if (peut_ecrire('evenements')): ?><td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $ev['id'] ?>" form="bulkform" class="row-check"></td><?php endif; ?>
            <td<?= $estAnnule ? ' class="text-strike"' : '' ?>><a href="<?= e($hrefLigne) ?>" class="titre-lien"><?= e(date('d.m.Y', strtotime($ev['date']))) ?></a></td>
            <td class="small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
            <td class="<?= $estAnnule ? 'text-strike' : '' ?>">
                <?= ville_departement_canton_html((string) $ev['ville'], $drapeau, (string) $ev['pays'], (string) $ev['departement_canton']) ?>
                <?php if ($festivalSalle !== ''): ?> <span class="muted small"><?= e($festivalSalle) ?></span><?php endif; ?>
                <?php if ($ev['ville'] === '' && $festivalSalle === ''): ?>—<?php endif; ?>
            </td>
            <td><?= evenement_icone_visibilite($ev) ?></td>
            <td><?= evenement_badge_statut($ev) ?></td>
            <td><?= evenement_suisa_badge($ev, true) ?></td>
            <td class="num salaries-cell">
                <?php if ((int) $ev['production_externe']): ?><span title="Production externe" aria-label="Production externe"><?= icon('handshake') ?></span><?php endif; ?>
                <?= (int) $ev['nb_salaries'] ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($evenements): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
<script>
(function () {
    const bulkBar = document.getElementById('bulk-bar');
    if (bulkBar) {
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        // Ne coche que les lignes visibles : en mode client (lassoListeClient()),
        // les lignes des autres pages restent dans le DOM mais display:none —
        // « Tout cocher » ne doit porter que sur la page actuellement affichée.
        document.querySelectorAll('.row-check').forEach(c => {
            if (c.closest('tr').style.display !== 'none') c.checked = all.checked;
        });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    // Action choisie → affiche le champ correspondant (s'il y en a un) et adapte
    // le libellé du bouton (suppression = destructif, le reste = modification).
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
        if (action.value === 'delete' && !confirm('Supprimer ' + n + ' événement(s) ? Cette action est irréversible.')) {
            e.preventDefault();
        } else if (action.value === 'production_externe' && document.getElementById('bulk-production-externe').value === '1'
            && !confirm('Activer « Production externe » va supprimer les prestations déjà liées (non payées) sur les fiches de salaire des employés des ' + n + ' événement(s) sélectionné(s). Continuer ?')) {
            e.preventDefault();
        }
    });
    }

    // Recherche : voir lassoRechercheServeur() (assets/app.js) — paginée côté
    // serveur, sinon une recherche ne porterait que sur la page déjà chargée.
    // En dessous du seuil client (lib/helpers.php), lassoListeClient() prend
    // le relais entièrement en JS.
    <?php if ($modeClient && $vue !== 'carte'): ?>
    lassoListeClient({
        tableSelector: '.evenements-liste',
        searchInputSelector: '#evenements-search',
        separatorSelector: '.mois-sep',
    });
    <?php else: ?>
    lassoRechercheServeur(document.getElementById('evenements-search'));
    <?php endif; ?>
})();
</script>
<?php endif; ?>
</div></div>
