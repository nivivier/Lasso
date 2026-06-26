<?php /** @var array $fiches */ /** @var int $annee */ /** @var array $annees */ /** @var string $statut */
/** @var array $employes */ /** @var int $employeId */ /** @var array $axesParFiche */ ?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Fiches de salaire</h1>
        <form method="get">
            <input type="hidden" name="p" value="fiches">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="hidden" name="employe_id" value="<?= (int) $employeId ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <?php
                $opts = array_unique(array_merge([$annee, (int) date('Y')], array_map('intval', $annees)));
                rsort($opts);
                foreach ($opts as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <a class="btn" href="?p=fiche_new"><?= icon('file-plus') ?> Nouvelle fiche</a>
</div>

<form method="get" class="filters">
    <input type="hidden" name="p" value="fiches">
    <input type="hidden" name="annee" value="<?= (int) $annee ?>">
    <label>Statut
        <select name="statut" onchange="this.form.submit()">
            <?php foreach (['tous' => 'Toutes', 'apayer' => 'À payer', 'payees' => 'Payées'] as $val => $lib): ?>
                <option value="<?= $val ?>" <?= $statut === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Employé
        <select name="employe_id" onchange="this.form.submit()">
            <option value="0">Tous</option>
            <?php foreach ($employes as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>" <?= $employeId === (int) $emp['id'] ? 'selected' : '' ?>>
                    <?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<?php if (!$fiches): ?>
    <p class="muted">Aucune fiche pour cette sélection.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr>
            <th>Mois</th><th>Employé</th><th>Axes</th>
            <th class="num">Brut</th><th class="num">Net</th><th>Paiement</th><th class="num">Coût employeur</th>
            <th class="center">Envoyée</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($fiches as $f): $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f); ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>">
            <td><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
            <td><?= e($f['employe_nom']) ?></td>
            <td class="muted small"><?= e($axesParFiche[(int) $f['id']] ?? '') ?></td>
            <td class="num col-brut"><?= chf((float) $f['salaire_brut']) ?></td>
            <td class="num strong <?= $apayer ? 'net-apayer' : (fiche_a_venir($f) ? 'net-avenir' : '') ?>"><?= chf((float) $f['salaire_net']) ?></td>
            <td><?= badge_paiement($f) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
