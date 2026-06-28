<?php /** @var bool $saved */ /** @var array $tauxHoraires */ /** @var array $unites */
$hnum = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Modifications enregistrées.</p><?php endif; ?>

<h2 class="mt-0">Salaires horaires</h2>
<div class="card form">
    <p class="muted small">Proposés lors de la création d'une fiche de salaire. Un taux manuel reste toujours possible.</p>
    <?php if ($tauxHoraires): ?>
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Montant</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tauxHoraires as $th): ?>
            <tr>
                <td><?= e($th['libelle']) ?></td>
                <td class="num"><?= chf((float) $th['montant']) ?> CHF/h</td>
                <td class="actions">
                    <form method="post" action="?p=taux_horaires" onsubmit="return confirm('Supprimer ce taux horaire ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
                        <input type="hidden" name="id" value="<?= (int) $th['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="muted small mb-16">Aucun taux prédéfini pour l'instant.</p>
    <?php endif; ?>
    <form method="post" action="?p=taux_horaires">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="add">
        <div class="add-row">
            <label>Libellé <input name="th_libelle" placeholder="ex. Standard, Animation, Direction" required></label>
            <label>Montant (CHF/h) <input name="th_montant" type="text" inputmode="decimal" placeholder="30.00" required></label>
            <button type="submit">Ajouter</button>
        </div>
    </form>
</div>

<h2>Unités de temps</h2>
<div class="card form">
    <p class="muted small">Utilisées dans les fiches de salaire. Chaque unité vaut un nombre d'heures (le calcul du salaire se fait toujours sur le total d'heures). Supprimer une unité ne modifie pas les fiches déjà créées.</p>
    <?php if ($unites): ?>
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Équivaut à</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unites as $u): ?>
            <tr>
                <td><?= e($u['libelle']) ?></td>
                <td class="num"><?= $hnum($u['heures']) ?> h</td>
                <td class="actions">
                    <form method="post" action="?p=taux_horaires" onsubmit="return confirm('Supprimer cette unité ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="unite_del">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="muted small mb-16">Aucune unité définie. Ajoutez au moins « Heure » (1 h).</p>
    <?php endif; ?>
    <form method="post" action="?p=taux_horaires">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="unite_add">
        <div class="add-row">
            <label>Libellé <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required></label>
            <label>Équivaut à (heures) <input name="u_heures" type="text" inputmode="decimal" placeholder="8" required></label>
            <button type="submit">Ajouter</button>
        </div>
    </form>
</div>
