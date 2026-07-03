<?php
/** @var array $evenements */ /** @var int $annee */ /** @var array $annees */
/** @var string $statutSuisa */ /** @var int $spectacleId */ /** @var string $statut */
/** @var string $visibilite */ /** @var array $spectacles */
$statutsSuisa = [
    'tous' => 'Tous', 'a_faire' => 'À faire', 'envoye' => 'Envoyé', 'manquant' => 'Manquant',
    'decompte_recu' => 'Décompte reçu', 'ne_sapplique_pas' => "Ne s'applique pas",
];
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
        <a class="btn ghost" href="?p=spectacles"><?= icon('music') ?> <span class="lbl">Spectacles</span></a>
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
        <label>Visibilité
            <select name="visibilite" onchange="this.form.submit()">
                <option value="tous" <?= $visibilite === 'tous' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>" <?= $visibilite === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Spectacle
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
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr><th>Date</th><th>Spectacle</th><th>Ville / salle</th><th>Visibilité</th><th>Statut</th><th>SUISA</th></tr></thead>
    <tbody>
    <?php foreach ($evenements as $ev): ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=evenement&id=<?= (int) $ev['id'] ?>">
            <td><?= e(date('d.m.Y', strtotime($ev['date']))) ?></td>
            <td><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
            <td class="muted small"><?= e(trim(($ev['salle'] ? $ev['salle'] . ', ' : '') . $ev['ville'])) ?: '—' ?></td>
            <td><?= evenement_badge_visibilite($ev) ?></td>
            <td><?= evenement_badge_statut($ev) ?></td>
            <td><?= evenement_suisa_badge($ev) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
