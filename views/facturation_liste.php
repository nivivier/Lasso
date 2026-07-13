<?php /** @var array $factures */ /** @var string $statut */ /** @var int $annee */ /** @var array $annees */
/** @var bool $avecEvenements */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Facturation</h1>
        <form method="get">
            <input type="hidden" name="p" value="facturation_liste">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
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
        <a class="btn ghost btn-sm" href="?p=compta_comptes"><?= icon('landmark') ?> <span class="lbl">Comptes bancaires</span></a>
        <a class="btn ghost btn-sm" href="?p=facturation_debiteurs"><?= icon('users') ?> <span class="lbl">Débiteurs</span></a>
        <a class="btn" href="?p=facturation_form"><?= icon('file-plus') ?><span class="lbl"> Nouvelle facture</span></a>
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
        <label class="search-label"><span>Rechercher <span id="facturation-search-count" class="muted small"></span></span>
            <input type="search" id="facturation-search" placeholder="Numéro, débiteur, montant, statut…" autocomplete="off" aria-label="Rechercher" value="<?= e($_GET['q'] ?? '') ?>">
        </label>
    </form>
</div>
</div>

<?php if (!$factures): ?>
    <p class="muted">Aucune facture pour cette sélection.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr>
        <th>Numéro</th><th>Débiteur</th><th>Émission</th><th>Échéance</th>
        <?php if ($avecEvenements): ?><th>Événement</th><?php endif; ?>
        <th class="num">Montant</th><th>Paiement</th>
    </tr></thead>
    <tbody>
    <?php
    $prevMois = null;
    $nbCols = 6 + ($avecEvenements ? 1 : 0);
    foreach ($factures as $f):
        $moisCle = substr((string) ($f['date_emission'] ?: $f['cree_le']), 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="<?= $nbCols ?>"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $f['id'] ?>">
            <td><?= $f['numero'] !== '' ? e($f['numero']) : '<span class="muted">(brouillon)</span>' ?></td>
            <td><strong><?= e($f['debiteur_nom']) ?></strong></td>
            <td class="muted small"><?= $f['date_emission'] !== '' ? e(date('d.m.Y', strtotime($f['date_emission']))) : '—' ?></td>
            <td class="muted small"><?= $f['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($f['date_echeance']))) : '—' ?></td>
            <?php if ($avecEvenements): ?>
                <td class="muted small">
                    <?php if (!empty($f['evenement_date'])): ?>
                        <?= e(date('d.m.Y', strtotime($f['evenement_date']))) ?><?= $f['spectacle_nom'] ? ' — ' . e($f['spectacle_nom']) : '' ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            <?php endif; ?>
            <td class="num strong"><?= chf((float) $f['montant_total']) ?></td>
            <td><?= facturation_badge($f) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/_pagination.php'; ?>
<script>
(function () {
    const search = document.getElementById('facturation-search');
    const count  = document.getElementById('facturation-search-count');
    const rows   = Array.from(document.querySelectorAll('.list-wide tbody tr'));
    if (search) {
        const apply = () => {
            const q = lassoNorm(search.value.trim());
            let visibles = 0;
            rows.forEach(r => {
                const ok = q === '' || lassoNorm(r.textContent).includes(q);
                r.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            count.textContent = q === '' ? '' : visibles + ' / ' + rows.length + ' affichée(s)';
        };
        search.addEventListener('input', apply);
        if (search.value.trim() !== '') apply();
    }
})();
</script>
<?php endif; ?>
