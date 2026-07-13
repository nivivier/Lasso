<?php /** @var array $employes */ /** @var array $derniere */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<div class="page-head">
    <h1>Employés</h1>
    <div class="head-actions">
        <?php if ($employes): ?>
        <label class="search-label">
            <input type="search" id="employes-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher">
        </label>
        <?php endif; ?>
        <a class="btn" href="?p=employe" title="Nouvel employé"><?= icon('user-plus') ?> <span class="lbl">Nouvel employé</span></a>
    </div>
</div>

<?php if (!$employes): ?>
    <p class="muted">Aucun employé pour l'instant. Commencez par en ajouter un.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr>
            <th>Nom</th><th>Adresse</th><th>E-mail</th><th>Dernière fiche</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($employes as $emp): ?>
        <tr class="row-link <?= $emp['actif'] ? '' : 'inactif' ?>" tabindex="0" role="link" data-href="?p=employe_voir&id=<?= (int) $emp['id'] ?>">
            <td>
                <strong><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></strong>
                <?php if (!$emp['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
            </td>
            <td class="muted small">
                <?= e($emp['rue']) ?><?= $emp['rue'] && $emp['npa_localite'] ? '<br>' : '' ?><?= e($emp['npa_localite']) ?>
                <?= !$emp['rue'] && !$emp['npa_localite'] ? '—' : '' ?>
            </td>
            <td class="muted small"><?= $emp['email'] ? e($emp['email']) : '—' ?></td>
            <td>
                <?php $d = $derniere[(int) $emp['id']] ?? null; ?>
                <?php if (!$d): ?>
                    <span class="muted small">—</span>
                <?php else: ?>
                    <span class="mini-fiche">
                        <span class="mf-mois"><?= e(mois_nom((int) $d['mois'])) ?> <?= (int) $d['annee'] ?></span>
                        <span class="mf-mont">brut <?= chf((float) $d['salaire_brut']) ?> · net <?= chf((float) $d['salaire_net']) ?></span>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/_pagination.php'; ?>
<script>
(function () {
    const search = document.getElementById('employes-search');
    const rows   = Array.from(document.querySelectorAll('.list-wide tbody tr'));
    if (search) {
        const apply = () => {
            const q = lassoNorm(search.value.trim());
            rows.forEach(r => {
                r.style.display = (q === '' || lassoNorm(r.textContent).includes(q)) ? '' : 'none';
            });
        };
        search.addEventListener('input', apply);
    }
})();
</script>
<?php endif; ?>
