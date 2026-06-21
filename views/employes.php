<?php /** @var array $employes */ /** @var array $derniere */ ?>
<div class="page-head">
    <h1>Employés</h1>
    <a class="btn" href="?p=employe"><?= icon('user-plus') ?> Nouvel employé</a>
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
                <?php if (!$emp['actif']): ?><span class="badge">inactif</span><?php endif; ?>
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
<?php endif; ?>
