<?php
/** @var string $type */ /** @var array $doublons */ /** @var ?string $doublonsErr */
/** @var ?string $ok */ /** @var int $ns */ /** @var int $nl */ /** @var int $nc */
/** @var string $datesEtape */ /** @var ?string $datesErr */ /** @var ?array $datesResultat */
/** @var ?int $datesAppliqueN */
/** @var array $grandesRegions */ /** @var ?string $grandesRegionsErr */ /** @var ?int $grandesRegionsAppliqueN */

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
                    <thead><tr><th>Fiche</th><th>Conservée</th><th>Supprimée(s)</th></tr></thead>
                    <tbody>
                    <?php foreach ($doublons[$k] as $g): ?>
                        <tr>
                            <td><?= e($g['libelle']) ?></td>
                            <td>#<?= (int) $g['ids'][0] ?></td>
                            <td><?= e(implode(', #', array_map('strval', array_slice($g['ids'], 1)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>

        <form method="post" action="?p=dev" class="mt-16"
              onsubmit="return confirm('Fusionner les <?= $totalGroupes ?> groupe(s) de doublons ci-dessus (<?= $totalSurnum ?> fiche(s) supprimée(s)) ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="doublons_fusionner">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <button type="submit"><?= icon('merge') ?> Fusionner les doublons détectés</button>
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
                <thead><tr><th>Écriture prévue</th></tr></thead>
                <tbody>
                <?php foreach ($datesResultat['aEcrire'] as $op): ?>
                    <tr><td><?= e($op[3]) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="?p=dev" class="mt-16"
                  onsubmit="return confirm('Enregistrer ces <?= count($datesResultat['aEcrire']) ?> écriture(s) ? Une sauvegarde de la base sera faite automatiquement avant.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="dates_appliquer">
                <input type="hidden" name="depuis_session" value="1">
                <button type="submit"><?= icon('save') ?> Enregistrer ces écritures</button>
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
            <thead><tr><th>Table</th><th>Fiche</th><th>Pays</th><th>Dép./canton</th><th>Actuelle</th><th>Déduite</th></tr></thead>
            <tbody>
            <?php foreach ($grandesRegions as $l): ?>
                <tr>
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
        <form method="post" action="?p=dev" class="mt-16"
              onsubmit="return confirm('Mettre à jour ces <?= count($grandesRegions) ?> fiche(s) avec la grande région déduite ? Une sauvegarde de la base sera faite automatiquement avant.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="grandes_regions_appliquer">
            <button type="submit"><?= icon('map-pin') ?> Appliquer les grandes régions déduites</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>
</div>
