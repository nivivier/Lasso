<?php
/** @var array $evenements */ /** @var int $annee */ /** @var array $annees */
/** @var string $statutSuisa */ /** @var int $spectacleId */ /** @var string $statut */
/** @var string $visibilite */ /** @var array $spectacles */
$statutsSuisa = [
    'tous' => 'Tous', 'a_faire' => 'À faire', 'envoye' => 'Envoyé', 'manquant' => 'Manquant',
    'decompte_recu' => 'Décompte reçu', 'ne_sapplique_pas' => "Ne s'applique pas",
];
$termePluriel = evenements_terme_spectacle();
$termeSingulier = evenements_terme_spectacle(false);
?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Événements</h1>
        <form method="get">
            <input type="hidden" name="p" value="evenements_liste">
            <input type="hidden" name="statut_suisa" value="<?= e($statutSuisa) ?>">
            <input type="hidden" name="spectacle_id" value="<?= (int) $spectacleId ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="hidden" name="visibilite" value="<?= e($visibilite) ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php $opts = array_unique(array_merge([$annee, (int) date('Y')], $annees)); $opts = array_diff($opts, [0]); rsort($opts);
                foreach ($opts as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <a class="btn ghost btn-sm" href="?p=spectacles"><?= icon('music') ?> <span class="lbl"><?= e($termePluriel) ?></span></a>
        <a class="btn" href="?p=evenement"><?= icon('file-plus') ?> Nouvel événement</a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="evenements_liste">
        <input type="hidden" name="annee" value="<?= (int) $annee ?>">
        <label>Statut SUISA
            <select name="statut_suisa" onchange="this.form.submit()">
                <?php foreach ($statutsSuisa as $val => $lib): ?>
                    <option value="<?= $val ?>" <?= $statutSuisa === $val ? 'selected' : '' ?>><?= $lib ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut" onchange="this.form.submit()">
                <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $statut === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type d'audience
            <select name="visibilite" onchange="this.form.submit()">
                <option value="tous" <?= $visibilite === 'tous' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>" <?= $visibilite === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= e($termeSingulier) ?>
            <select name="spectacle_id" onchange="this.form.submit()">
                <option value="0">Tous</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $spectacleId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>
</div>

<?php if (!$evenements): ?>
    <p class="muted">Aucun événement pour cette sélection.</p>
<?php else: ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <div class="bulk-group">
        <span class="bulk-titre"><?= e($termeSingulier) ?> :</span>
        <form method="post" id="bulk-spectacle-form" action="?p=evenements_liste">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="spectacle">
            <select name="bulk_spectacle_id" class="inline-year-select">
                <option value="">— Aucun —</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Appliquer</button>
        </form>
    </div>
    <div class="bulk-group">
        <span class="bulk-titre">Type d'audience :</span>
        <form method="post" id="bulk-visibilite-form" action="?p=evenements_liste">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="visibilite">
            <select name="bulk_visibilite" class="inline-year-select">
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>"><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Appliquer</button>
        </form>
    </div>
    <div class="bulk-group">
        <span class="bulk-titre">Statut :</span>
        <form method="post" id="bulk-statut-form" action="?p=evenements_liste">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="statut">
            <select name="bulk_statut" class="inline-year-select">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>"><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Appliquer</button>
        </form>
    </div>
</div>
<div class="table-scroll">
<table class="list list-wide evenements-liste">
    <thead>
        <tr>
            <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
            <th>Date</th><th><?= e($termeSingulier) ?></th><th>Ville / salle</th><th>Audience</th><th>Statut</th><th>SUISA</th>
        </tr>
    </thead>
    <tbody>
    <?php $moisPrecedent = null; foreach ($evenements as $ev):
        $moisCle = substr((string) $ev['date'], 0, 7); // "AAAA-MM"
        if ($moisCle !== $moisPrecedent):
            $moisPrecedent = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="7"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <?php
            $estAnnule = $ev['statut'] === 'annule';
            $drapeau = pays_drapeau((string) $ev['pays']);
            $festivalSalle = implode(', ', array_filter([$ev['festival'], $ev['salle']], fn ($v) => $v !== ''));
        ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=evenement&id=<?= (int) $ev['id'] ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $ev['id'] ?>" form="bulk-spectacle-form" class="row-check"></td>
            <td<?= $estAnnule ? ' class="text-strike"' : '' ?>><?= e(date('d.m.Y', strtotime($ev['date']))) ?></td>
            <td class="small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
            <td class="<?= $estAnnule ? 'text-strike' : '' ?>">
                <?php if ($ev['ville'] !== ''): ?><strong><?= e($ev['ville']) ?></strong><?php endif; ?>
                <?php if ($drapeau !== ''): ?> <span class="tiny"><?= $drapeau ?></span><?php endif; ?>
                <?php if ($ev['region'] !== ''): ?> <span class="tiny muted">(<?= e($ev['region']) ?>)</span><?php endif; ?>
                <?php if ($festivalSalle !== ''): ?> <span class="muted small"><?= e($festivalSalle) ?></span><?php endif; ?>
                <?php if ($ev['ville'] === '' && $festivalSalle === ''): ?>—<?php endif; ?>
            </td>
            <td><?= evenement_badge_visibilite($ev) ?></td>
            <td><?= evenement_badge_statut($ev) ?></td>
            <td><?= evenement_suisa_badge($ev) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<script>
(function () {
    const bulkBar = document.getElementById('bulk-bar');
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => { c.checked = all.checked; });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    // Les cases ne sont natively liées qu'au formulaire « Spectacle » (attribut form) —
    // on injecte les mêmes ids dans les deux autres formulaires juste avant l'envoi.
    ['bulk-visibilite-form', 'bulk-statut-form'].forEach(formId => {
        const form = document.getElementById(formId);
        form.addEventListener('submit', e => {
            form.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
            const checked = document.querySelectorAll('.row-check:checked');
            checked.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
                form.appendChild(inp);
            });
            if (!checked.length) e.preventDefault();
        });
    });
})();
</script>
<?php endif; ?>
