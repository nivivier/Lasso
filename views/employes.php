<?php /** @var array $employes */ /** @var array $derniere */ /** @var string $recherche */ /** @var bool $modeClient */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <?php if ($employes || $recherche !== ''): ?>
        <?= champ_recherche(['id' => 'employes-search', 'valeur' => $recherche]) ?>
        <?php endif; ?>
        <?php if (peut_ecrire('salaires')): ?>
        <div class="head-actions">
            <a class="btn" href="?p=employe" title="Nouvel employé"><?= icon('user-plus') ?> <span class="lbl">Nouvel employé</span></a>
        </div>
        <?php endif; ?>
    </div>

<?php if (!$employes): ?>
    <?php if ($recherche !== ''): ?>
        <p class="muted">Aucun employé ne correspond à « <?= e($recherche) ?> ».</p>
    <?php else: ?>
        <p class="muted">Aucun employé pour l'instant. Commencez par en ajouter un.</p>
    <?php endif; ?>
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
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>
<?php endif; ?>
</div></div>
<script>
<?php if ($modeClient): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#employes-search',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('employes-search'));
<?php endif; ?>
</script>
