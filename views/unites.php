<?php /** @var bool $saved */ /** @var array $unites */
$hnum = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Unités de temps mises à jour.</p><?php endif; ?>

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
                    <form method="post" action="?p=unites" onsubmit="return confirm('Supprimer cette unité ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
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
    <form method="post" action="?p=unites">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="add">
        <div class="add-row">
            <label>Libellé <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required></label>
            <label>Équivaut à (heures) <input name="u_heures" type="text" inputmode="decimal" placeholder="8" required></label>
            <button type="submit">Ajouter</button>
        </div>
    </form>
</div>
