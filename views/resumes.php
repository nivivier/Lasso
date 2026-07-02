<?php
/** @var array $aPayer */ /** @var array $facturesEmises */
?>
<div class="page-head"><h1>Tableau de bord</h1></div>

<h2 class="mt-0">Salaires à verser</h2>
<?php if (!$aPayer): ?>
    <p class="muted">Vous êtes à jour.</p>
<?php else: ?>
<table class="list">
    <thead>
        <tr><th>Mois</th><th>Employé</th><th class="num">Net à payer</th></tr>
    </thead>
    <tbody>
    <?php $totAPayer = 0; foreach ($aPayer as $f): $totAPayer += (float) $f['salaire_net']; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>">
            <td><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
            <td><?= e($f['employe_nom']) ?></td>
            <td class="num strong net-apayer"><?= chf((float) $f['salaire_net']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="total-row apayer-row"><td><strong>Total à verser</strong></td><td></td><td class="num strong net-apayer"><?= chf($totAPayer) ?></td></tr>
    </tfoot>
</table>
<?php endif; ?>

<?php if (module_actif('facturation')): ?>
<h2>Factures émises</h2>
<?php if (!$facturesEmises): ?>
    <p class="muted">Aucune facture émise en attente de paiement.</p>
<?php else: ?>
<table class="list">
    <thead>
        <tr><th>Échéance</th><th>Débiteur</th><th></th><th class="num">Montant</th></tr>
    </thead>
    <tbody>
    <?php $totEmises = 0; foreach ($facturesEmises as $fac): $totEmises += (float) $fac['montant_total']; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $fac['id'] ?>">
            <td><?= $fac['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($fac['date_echeance']))) : '—' ?></td>
            <td><?= e($fac['debiteur_nom']) ?></td>
            <td><?= facturation_badge($fac) ?></td>
            <td class="num strong net-apayer"><?= chf((float) $fac['montant_total']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="total-row apayer-row"><td><strong>Total émis</strong></td><td></td><td></td><td class="num strong net-apayer"><?= chf($totEmises) ?></td></tr>
    </tfoot>
</table>
<?php endif; ?>
<?php endif; ?>
