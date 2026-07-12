<?php /** @var bool $saved */ /** @var array $tauxHoraires */ /** @var array $unites */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Modifications enregistrées.</p><?php endif; ?>

<div class="card form mb-22">
<div class="section-head mt-0">
    <h2 class="mt-0">Salaires horaires <?= info_tip(
        "Proposés lors de la création d'une fiche de salaire. Un taux manuel reste toujours possible."
    ) ?></h2>
    <button type="button" class="btn btn-sm ml-auto" data-show="th-add"><?= icon('plus') ?> Nouveau</button>
</div>
    <?php if ($tauxHoraires): ?>
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Montant</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tauxHoraires as $th): ?>
            <tr>
                <td>
                    <span class="row-field-disp">
                        <span><?= e($th['libelle']) ?></span>
                        <button type="button" class="row-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    </span>
                    <form method="post" action="?p=taux_horaires" class="row-field-inp inline-edit" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="th_rename">
                        <input type="hidden" name="id" value="<?= (int) $th['id'] ?>">
                        <input type="text" name="th_libelle" value="<?= e($th['libelle']) ?>" required class="grow">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </td>
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
        <tfoot id="th-add" hidden>
            <tr>
                <td colspan="3">
                    <form method="post" action="?p=taux_horaires" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="add">
                        <input name="th_libelle" placeholder="ex. Standard, Animation, Direction" required class="grow">
                        <input name="th_montant" type="text" inputmode="decimal" placeholder="30.00 CHF/h" required>
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                        <button type="button" class="btn ghost btn-sm icon-only" data-hide="th-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <p class="muted small mb-16">Aucun taux prédéfini pour l'instant.</p>
        <form method="post" action="?p=taux_horaires" class="inline-edit" id="th-add" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="add">
            <input name="th_libelle" placeholder="ex. Standard, Animation, Direction" required class="grow">
            <input name="th_montant" type="text" inputmode="decimal" placeholder="30.00 CHF/h" required>
            <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only" data-hide="th-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
    <?php endif; ?>
</div>

<div class="card form">
<div class="section-head mt-0">
    <h2 class="mt-0">Unités de temps <?= info_tip(
        "Utilisées dans les fiches de salaire. Chaque unité vaut un nombre d'heures (le calcul du salaire se fait "
        . "toujours sur le total d'heures). Supprimer une unité ne modifie pas les fiches déjà créées."
    ) ?></h2>
    <button type="button" class="btn btn-sm ml-auto" data-show="u-add"><?= icon('plus') ?> Nouveau</button>
</div>
    <?php if ($unites): ?>
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Équivaut à</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unites as $u): ?>
            <tr>
                <td>
                    <span class="row-field-disp">
                        <span><?= e($u['libelle']) ?></span>
                        <button type="button" class="row-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    </span>
                    <form method="post" action="?p=taux_horaires" class="row-field-inp inline-edit" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="unite_rename">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="text" name="u_libelle" value="<?= e($u['libelle']) ?>" required class="grow">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </td>
                <td class="num"><?= nombre_court($u['heures']) ?> h</td>
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
        <tfoot id="u-add" hidden>
            <tr>
                <td colspan="3">
                    <form method="post" action="?p=taux_horaires" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="unite_add">
                        <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required class="grow">
                        <input name="u_heures" type="text" inputmode="decimal" placeholder="8 h" required>
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                        <button type="button" class="btn ghost btn-sm icon-only" data-hide="u-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <p class="muted small mb-16">Aucune unité définie. Ajoutez au moins « Heure » (1 h).</p>
        <form method="post" action="?p=taux_horaires" class="inline-edit" id="u-add" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="unite_add">
            <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required class="grow">
            <input name="u_heures" type="text" inputmode="decimal" placeholder="8 h" required>
            <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only" data-hide="u-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
    <?php endif; ?>
</div>

<script>
(function () {
    // Crayon → bascule la ligne (salaire horaire ou unité) en mode édition du libellé.
    document.addEventListener('click', e => {
        const btn = e.target.closest('.row-edit-btn');
        if (!btn) return;
        const disp = btn.closest('.row-field-disp');
        if (!disp) return;
        const inp = disp.nextElementSibling;
        if (!inp) return;
        disp.hidden = true;
        inp.hidden = false;
        inp.querySelector('input[type="text"]')?.focus();
    });
})();
</script>
