<?php /** @var array $factures */ /** @var string $statut */ /** @var int $annee */ /** @var array $annees */ ?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Facturation</h1>
        <form method="get">
            <input type="hidden" name="p" value="facturation_liste">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <?php $opts = array_unique(array_merge([$annee, (int) date('Y')], $annees)); rsort($opts);
                foreach ($opts as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <a class="btn ghost" href="?p=facturation_debiteurs"><?= icon('users') ?> <span class="lbl">Débiteurs</span></a>
        <a class="btn" href="?p=facturation_form"><?= icon('file-plus') ?> Nouvelle facture</a>
    </div>
</div>

<form method="get" class="filters">
    <input type="hidden" name="p" value="facturation_liste">
    <input type="hidden" name="annee" value="<?= (int) $annee ?>">
    <label>Statut
        <select name="statut" onchange="this.form.submit()">
            <?php foreach (['tous' => 'Tous', 'brouillon' => 'Brouillons', 'emise' => 'Émises', 'en_retard' => 'En retard', 'payee' => 'Payées', 'annulee' => 'Annulées'] as $val => $lib): ?>
                <option value="<?= $val ?>" <?= $statut === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<?php if (!$factures): ?>
    <p class="muted">Aucune facture pour cette sélection.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr><th>Numéro</th><th>Débiteur</th><th>Émission</th><th>Échéance</th><th class="num">Montant</th><th>Statut</th></tr></thead>
    <tbody>
    <?php foreach ($factures as $f): ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $f['id'] ?>">
            <td><?= $f['numero'] !== '' ? e($f['numero']) : '<span class="muted">(brouillon)</span>' ?></td>
            <td><?= e($f['debiteur_nom']) ?></td>
            <td><?= $f['date_emission'] !== '' ? e(date('d.m.Y', strtotime($f['date_emission']))) : '—' ?></td>
            <td><?= $f['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($f['date_echeance']))) : '—' ?></td>
            <td class="num strong"><?= chf((float) $f['montant_total']) ?></td>
            <td><?= facturation_badge($f) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
