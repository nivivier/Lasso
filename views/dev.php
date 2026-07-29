<?php
/** @var string $type */ /** @var array $doublons */ /** @var ?string $doublonsErr */
/** @var ?string $ok */ /** @var int $ns */ /** @var int $nl */ /** @var int $nc */
/** @var string $datesEtape */ /** @var ?string $datesErr */ /** @var ?array $datesResultat */
/** @var ?int $datesAppliqueN */
/** @var array $grandesRegions */ /** @var ?string $grandesRegionsErr */ /** @var ?int $grandesRegionsAppliqueN */
/** @var array $evenementsLieuxUnivoques */ /** @var array $evenementsLieuxAmbigues */
/** @var array $evenementsLieuxAucuneGroupes */ /** @var ?string $evenementsLieuxErr */
/** @var ?int $evenementsLieuxLiesN */ /** @var ?int $evenementsLieuxCreesN */ /** @var ?int $evenementsLieuxCreesEvN */

$libellesTable = ['structures' => 'Structures', 'lieux' => 'Lieux', 'evenements' => 'Événements'];

$libellesType = ['structures' => 'Structures', 'lieux' => 'Lieux', 'contacts' => 'Contacts', 'tous' => 'Tous'];
$totalGroupes = count($doublons['structures']) + count($doublons['lieux']) + count($doublons['contacts']);
$totalSurnum  = 0;
foreach (['structures', 'lieux', 'contacts'] as $k) {
    foreach ($doublons[$k] as $g) { $totalSurnum += count($g['ids']) - 1; }
}
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?php if ($ok === 'doublons'): ?>
    <p class="ok flash">Fusion effectuée : <?= $ns ?> structure(s), <?= $nl ?> lieu(x) et <?= $nc ?> contact(s) en doublon supprimés.</p>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">Doublons exacts</h2>
    <p class="muted small">
        Détecte les fiches strictement identiques : structures (nom + localité), lieux (nom + ville + type),
        contacts (même structure + e-mail, ou à défaut nom + téléphone). Dans chaque groupe, la fiche la plus
        ancienne est conservée ; les autres lui cèdent leurs rattachements avant d'être supprimées.
    </p>

    <form method="get" class="mb-16">
        <input type="hidden" name="p" value="dev">
        <label class="d-inline">Type
            <select name="type" onchange="this.form.submit()">
                <?php foreach ($libellesType as $v => $lib): ?>
                    <option value="<?= e($v) ?>" <?= $type === $v ? 'selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn-sm">Filtrer</button></noscript>
    </form>

    <?php if ($doublonsErr): ?><p class="err"><?= e($doublonsErr) ?></p><?php endif; ?>

    <?php if ($totalGroupes === 0): ?>
        <p class="muted">Aucun doublon exact trouvé.</p>
    <?php else: ?>
        <p><?= $totalGroupes ?> groupe(s) de doublons, <?= $totalSurnum ?> fiche(s) en trop.</p>
        <?php foreach (['structures' => 'Structures', 'lieux' => 'Lieux', 'contacts' => 'Contacts'] as $k => $lib): ?>
            <?php if ($doublons[$k]): ?>
                <h3><?= e($lib) ?> (<?= count($doublons[$k]) ?>)</h3>
                <table class="list">
                    <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Fiche</th><th>Conservée</th><th>Supprimée(s)</th></tr></thead>
                    <tbody>
                    <?php foreach ($doublons[$k] as $g): ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($k) ?>:<?= (int) $g['ids'][0] ?>" form="dev-doublons-form" class="row-check"></td>
                            <td><?= e($g['libelle']) ?></td>
                            <td>#<?= (int) $g['ids'][0] ?></td>
                            <td><?= e(implode(', #', array_map('strval', array_slice($g['ids'], 1)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>

        <form method="post" action="?p=dev" class="mt-16" id="dev-doublons-form"
              onsubmit="return confirm('Fusionner les groupes de doublons cochés ci-dessus ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="doublons_fusionner">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <button type="submit"><?= icon('merge') ?> Fusionner les doublons cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant la fusion.</p>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Mise à jour des dates depuis un CSV</h2>
    <p class="muted small">
        Met à jour uniquement les dates « mise à jour », « dernier contact » et « dernier concert » d'après
        un export CSV, sans toucher au reste des fiches. Rapprochement par e-mail exact, sinon par nom + ville
        (jamais d'homonyme deviné).
    </p>

    <?php if ($datesErr): ?><p class="err"><?= e($datesErr) ?></p><?php endif; ?>

    <?php if ($datesAppliqueN !== null): ?>
        <p class="ok flash"><?= $datesAppliqueN ?> écriture(s) enregistrée(s).</p>
    <?php endif; ?>

    <?php if ($datesEtape === 'upload' || $datesAppliqueN !== null): ?>
        <form method="post" action="?p=dev" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="dates_analyser">
            <label>Fichier CSV <input type="file" name="fichier" accept=".csv,text/csv" required></label>
            <div class="form-actions">
                <button type="submit"><?= icon('search') ?> Analyser (simulation)</button>
            </div>
        </form>
    <?php elseif ($datesEtape === 'analyse' && $datesResultat): ?>
        <p class="muted small">Fichier : <strong><?= e($datesResultat['nom']) ?></strong> — colonnes repérées :
            <?php foreach ($datesResultat['index'] as $champ => $i): ?>
                <?= e($champ) ?>&nbsp;<?= $i === null ? '(absente)' : '« ' . e((string) $datesResultat['entete'][$i]) . ' »' ?><?= ' · ' ?>
            <?php endforeach; ?>
        </p>
        <p>
            <?= $datesResultat['stats']['lignes'] ?> ligne(s) lue(s) ·
            <?= $datesResultat['stats']['sans_correspondance'] ?> sans correspondance ·
            <?= $datesResultat['stats']['ambigues'] ?> ambiguë(s) ·
            <?= $datesResultat['stats']['maj'] ?> mise(s) à jour ·
            <?= $datesResultat['stats']['contact'] ?> dernier(s) contact ·
            <?= $datesResultat['stats']['concert'] ?> dernier(s) concert
        </p>

        <?php if ($datesResultat['aEcrire']): ?>
            <table class="list">
                <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Écriture prévue</th></tr></thead>
                <tbody>
                <?php foreach ($datesResultat['aEcrire'] as $op): ?>
                    <tr>
                        <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($op[0]) ?>:<?= (int) $op[1] ?>" form="dev-dates-form" class="row-check"></td>
                        <td><?= e($op[3]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="?p=dev" class="mt-16" id="dev-dates-form"
                  onsubmit="return confirm('Enregistrer les écritures cochées ci-dessus ? Une sauvegarde de la base sera faite automatiquement avant.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="dates_appliquer">
                <input type="hidden" name="depuis_session" value="1">
                <button type="submit"><?= icon('save') ?> Enregistrer les écritures cochées</button>
            </form>
            <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'enregistrement.</p>
        <?php else: ?>
            <p class="muted">Rien à écrire — aucune date nouvelle ou différente détectée.</p>
        <?php endif; ?>

        <?php if ($datesResultat['ambigues']): ?>
            <p class="muted small">Homonymes ambigus (ville absente du fichier, non traités) : <?= e(implode(', ', $datesResultat['ambigues'])) ?></p>
        <?php endif; ?>
        <?php if ($datesResultat['nonTrouvees']): ?>
            <p class="muted small">Sans correspondance dans la base : <?= e(implode(', ', $datesResultat['nonTrouvees'])) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Grandes régions déduites du département/canton</h2>
    <p class="muted small">
        Recalcule la grande région (structures, lieux, événements) à partir du département français ou du canton
        suisse déjà renseigné, et compare avec la valeur actuellement enregistrée. Jamais pour les cantons
        bilingues (Fribourg, Valais, Berne — la déduction n'y est pas assez fiable, ces fiches restent inchangées)
        ni pour les autres pays.
    </p>

    <?php if ($grandesRegionsErr): ?><p class="err"><?= e($grandesRegionsErr) ?></p><?php endif; ?>
    <?php if ($grandesRegionsAppliqueN !== null): ?>
        <p class="ok flash"><?= $grandesRegionsAppliqueN ?> fiche(s) mise(s) à jour.</p>
    <?php endif; ?>

    <?php if (!$grandesRegions): ?>
        <p class="muted">Aucun écart trouvé — les grandes régions déjà enregistrées correspondent au référentiel.</p>
    <?php else: ?>
        <p><?= count($grandesRegions) ?> fiche(s) avec un écart.</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Table</th><th>Fiche</th><th>Pays</th><th>Dép./canton</th><th>Actuelle</th><th>Déduite</th></tr></thead>
            <tbody>
            <?php foreach ($grandesRegions as $l): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($l['table']) ?>:<?= (int) $l['id'] ?>" form="dev-gr-form" class="row-check"></td>
                    <td class="muted small"><?= e($libellesTable[$l['table']] ?? $l['table']) ?></td>
                    <td><?= e($l['nom']) ?></td>
                    <td class="muted small"><?= e($l['pays']) ?></td>
                    <td class="muted small"><?= e($l['departement_canton']) ?></td>
                    <td class="muted small"><?= $l['actuelle'] !== '' ? e($l['actuelle']) : '—' ?></td>
                    <td><?= e($l['deduite']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=dev" class="mt-16" id="dev-gr-form"
              onsubmit="return confirm('Mettre à jour les fiches cochées ci-dessus avec la grande région déduite ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="grandes_regions_appliquer">
            <button type="submit"><?= icon('map-pin') ?> Appliquer aux fiches cochées</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Rattacher les événements à un lieu</h2>
    <p class="muted small">
        Un événement stocke le nom de la salle en texte libre, sans être automatiquement rattaché à une fiche
        lieu de la base. Rapproche par ville (+ département/canton + pays) puis par nom de salle — jamais deviné
        en cas de doute (plusieurs lieux candidats) : ces événements restent à traiter à la main, sur leur fiche.
    </p>

    <?php if ($evenementsLieuxErr): ?><p class="err"><?= e($evenementsLieuxErr) ?></p><?php endif; ?>
    <?php if ($evenementsLieuxLiesN !== null): ?>
        <p class="ok flash"><?= $evenementsLieuxLiesN ?> événement(s) rattaché(s) à un lieu existant.</p>
    <?php endif; ?>
    <?php if ($evenementsLieuxCreesN !== null): ?>
        <p class="ok flash"><?= $evenementsLieuxCreesN ?> structure(s)+lieu(x) créé(s), <?= $evenementsLieuxCreesEvN ?> événement(s) rattaché(s).</p>
    <?php endif; ?>

    <h3>Correspondances trouvées</h3>
    <?php if (!$evenementsLieuxUnivoques): ?>
        <p class="muted">Aucune correspondance univoque trouvée.</p>
    <?php else: ?>
        <p><?= count($evenementsLieuxUnivoques) ?> événement(s) avec un lieu candidat unique.</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Date</th><th>Salle (événement)</th><th>Ville</th><th>Lieu proposé</th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxUnivoques as $d): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= (int) $d['evenement_id'] ?>" form="dev-evlier-form" class="row-check"></td>
                    <td class="muted small"><?= e($d['date']) ?></td>
                    <td><?= e($d['salle']) ?></td>
                    <td class="muted small"><?= e($d['ville']) ?></td>
                    <td><?= e($d['candidats'][0]['nom']) ?> <span class="muted small">(<?= e($d['candidats'][0]['type']) ?>)</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=dev" class="mt-16" id="dev-evlier-form"
              onsubmit="return confirm('Rattacher les événements cochés ci-dessus au lieu proposé ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="evenements_lieux_lier">
            <button type="submit"><?= icon('merge') ?> Rattacher les événements cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>

    <h3 class="mt-22">Correspondances ambiguës</h3>
    <?php if (!$evenementsLieuxAmbigues): ?>
        <p class="muted">Aucune.</p>
    <?php else: ?>
        <p class="muted small">Plusieurs lieux candidats trouvés — jamais deviné : à choisir à la main sur la fiche événement (champ « Lieu »).</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th>Date</th><th>Salle (événement)</th><th>Ville</th><th>Lieux candidats</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxAmbigues as $d): ?>
                <tr>
                    <td class="muted small"><?= e($d['date']) ?></td>
                    <td><?= e($d['salle']) ?></td>
                    <td class="muted small"><?= e($d['ville']) ?></td>
                    <td class="muted small"><?= e(implode(', ', array_map(fn ($c) => $c['nom'], $d['candidats']))) ?></td>
                    <td><a href="?p=evenement&id=<?= (int) $d['evenement_id'] ?>" class="btn-sm">Ouvrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <h3 class="mt-22">Aucune correspondance</h3>
    <?php if (!$evenementsLieuxAucuneGroupes): ?>
        <p class="muted">Aucune.</p>
    <?php else: ?>
        <?php $nbEvAucune = array_sum(array_map(fn ($g) => count($g['evenements']), $evenementsLieuxAucuneGroupes)); ?>
        <p class="muted small">
            Aucun lieu trouvé dans la même ville pour ces salles — une nouvelle structure et un nouveau lieu
            (catégorie « organisateur » par défaut, à préciser ensuite) peuvent être créés, une seule paire par
            salle même si plusieurs événements la partagent.
        </p>
        <p><?= count($evenementsLieuxAucuneGroupes) ?> salle(s) sans correspondance, <?= $nbEvAucune ?> événement(s) concerné(s).</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Salle</th><th>Ville</th><th>Dép./canton</th><th>Pays</th><th>Événement(s)</th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxAucuneGroupes as $g): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= (int) $g['evenements'][0]['id'] ?>" form="dev-evcreer-form" class="row-check"></td>
                    <td><?= e($g['nom']) ?></td>
                    <td class="muted small"><?= e($g['ville']) ?></td>
                    <td class="muted small"><?= e($g['departement_canton']) ?></td>
                    <td class="muted small"><?= e($g['pays']) ?></td>
                    <td class="muted small">
                        <?php foreach ($g['evenements'] as $i => $ev): ?>
                            <?= $i > 0 ? ', ' : '' ?><a href="?p=evenement&id=<?= (int) $ev['id'] ?>"><?= e($ev['date']) ?></a>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=dev" class="mt-16" id="dev-evcreer-form"
              onsubmit="return confirm('Créer une structure+lieu pour chaque salle cochée ci-dessus et y rattacher ses événements ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="evenements_lieux_creer">
            <button type="submit"><?= icon('building-2') ?> Créer les structures+lieux cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>
</div>

<script>
// Case « tout cocher » de chaque tableau de l'onglet Dev : ne coche/décoche
// que les cases de SON PROPRE tableau (plusieurs tableaux indépendants sur
// cette page, contrairement aux listes lieux/structures/événements qui n'en
// ont qu'un seul) — un seul script générique plutôt qu'un bloc dupliqué par tableau.
document.querySelectorAll('.check-all').forEach(function (toutCocher) {
    var table = toutCocher.closest('table');
    toutCocher.addEventListener('change', function () {
        table.querySelectorAll('.row-check').forEach(function (c) { c.checked = toutCocher.checked; });
    });
});
</script>
