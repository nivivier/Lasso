<?php /** @var bool $saved */ /** @var array $tauxHoraires */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Taux horaires mis à jour.</p><?php endif; ?>

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
