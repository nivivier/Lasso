<?php /** @var bool $saved */ /** @var array $tauxHoraires */ /** @var array $unites */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Modifications enregistrées.</p><?php endif; ?>

<div class="card form mb-22">
<div class="section-head mt-0">
    <h2 class="mt-0">Salaires horaires <?= info_tip(
        "Proposés lors de la création d'une fiche de salaire. Un taux manuel reste toujours possible."
    ) ?></h2>
    <?php if (peut_ecrire('salaires')): ?><button type="button" class="btn btn-sm ml-auto" data-show="th-add"><?= icon('plus') ?> Nouveau</button><?php endif; ?>
</div>
    <?php if ($tauxHoraires): ?>
    <div class="table-scroll">
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Montant</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tauxHoraires as $th): ?>
            <tr>
                <td>
                    <?php if (peut_ecrire('salaires')): ?>
                    <span class="row-field-disp">
                        <span><?= e($th['libelle']) ?></span>
                    </span>
                    <form method="post" action="?p=taux_horaires" class="row-field-inp inline-edit" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="th_rename">
                        <input type="hidden" name="id" value="<?= (int) $th['id'] ?>">
                        <input type="text" name="th_libelle" value="<?= e($th['libelle']) ?>" required class="grow" aria-label="Libellé du taux horaire">
                        <button type="submit" class="btn btn-sm" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                    </form>
                    <?php else: ?>
                    <?= e($th['libelle']) ?>
                    <?php endif; ?>
                </td>
                <td class="num"><?= chf((float) $th['montant']) ?> CHF/h</td>
                <td class="actions">
                    <?php if (peut_ecrire('salaires')): ?>
                    <form method="post" action="?p=taux_horaires" data-confirm="Supprimer ce taux horaire ?" class="d-inline ligne-del-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
                        <input type="hidden" name="id" value="<?= (int) $th['id'] ?>">
                        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php // En édition, le crayon cède la place au trio : enregistrer
                          // (dans le formulaire, mis en évidence), supprimer (rouge) et
                          // annuler. La croix se pose exactement là où était le crayon,
                          // tout à droite ; la corbeille se range avant elle. ?>
                    <button type="button" class="btn ghost btn-sm icon-only ligne-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only ligne-annuler-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                    <?php endif; ?>
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
                        <input name="th_libelle" placeholder="ex. Standard, Animation, Direction" required class="grow" aria-label="Libellé du taux horaire">
                        <input name="th_montant" type="text" inputmode="decimal" placeholder="30.00 CHF/h" required aria-label="Montant">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                        <button type="button" class="btn ghost btn-sm icon-only" data-hide="th-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php else: ?>
        <p class="muted small mb-16">Aucun taux prédéfini pour l'instant.</p>
        <form method="post" action="?p=taux_horaires" class="inline-edit" id="th-add" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="add">
            <input name="th_libelle" placeholder="ex. Standard, Animation, Direction" required class="grow" aria-label="Libellé du taux horaire">
            <input name="th_montant" type="text" inputmode="decimal" placeholder="30.00 CHF/h" required aria-label="Montant">
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
    <?php if (peut_ecrire('salaires')): ?><button type="button" class="btn btn-sm ml-auto" data-show="u-add"><?= icon('plus') ?> Nouveau</button><?php endif; ?>
</div>
    <?php if ($unites): ?>
    <div class="table-scroll">
    <table class="list mb-16">
        <thead><tr><th>Libellé</th><th class="num">Équivaut à</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unites as $u): ?>
            <tr>
                <td>
                    <?php if (peut_ecrire('salaires')): ?>
                    <span class="row-field-disp">
                        <span><?= e($u['libelle']) ?></span>
                    </span>
                    <form method="post" action="?p=taux_horaires" class="row-field-inp inline-edit" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="unite_rename">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="text" name="u_libelle" value="<?= e($u['libelle']) ?>" required class="grow" aria-label="Libellé de l'unité">
                        <button type="submit" class="btn btn-sm" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                    </form>
                    <?php else: ?>
                    <?= e($u['libelle']) ?>
                    <?php endif; ?>
                </td>
                <td class="num"><?= nombre_court($u['heures']) ?> h</td>
                <td class="actions">
                    <?php if (peut_ecrire('salaires')): ?>
                    <form method="post" action="?p=taux_horaires" data-confirm="Supprimer cette unité ?" class="d-inline ligne-del-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="unite_del">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php // En édition, le crayon cède la place au trio : enregistrer
                          // (dans le formulaire, mis en évidence), supprimer (rouge) et
                          // annuler. La croix se pose exactement là où était le crayon,
                          // tout à droite ; la corbeille se range avant elle. ?>
                    <button type="button" class="btn ghost btn-sm icon-only ligne-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only ligne-annuler-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                    <?php endif; ?>
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
                        <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required class="grow" aria-label="Libellé de l'unité">
                        <input name="u_heures" type="text" inputmode="decimal" placeholder="8 h" required aria-label="Équivaut à (heures)">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                        <button type="button" class="btn ghost btn-sm icon-only" data-hide="u-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php else: ?>
        <p class="muted small mb-16">Aucune unité définie. Ajoutez au moins « Heure » (1 h).</p>
        <form method="post" action="?p=taux_horaires" class="inline-edit" id="u-add" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="unite_add">
            <input name="u_libelle" placeholder="ex. Jour, Demi-journée, Service" required class="grow" aria-label="Libellé de l'unité">
            <input name="u_heures" type="text" inputmode="decimal" placeholder="8 h" required aria-label="Équivaut à (heures)">
            <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only" data-hide="u-add" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // La corbeille n'appartient qu'au mode édition : un geste irréversible n'a
    // pas à être à portée de clic quand on ne fait que lire la liste. Masquée
    // ici plutôt qu'en HTML, pour qu'elle reste atteignable sans JavaScript —
    // où la ligne n'a de toute façon pas de mode édition.
    document.querySelectorAll('.ligne-del-form').forEach(f => { f.hidden = true; });

    // Bascule d'une ligne (salaire horaire ou unité) entre lecture et édition.
    const bascule = (tr, edition) => {
        const disp = tr.querySelector('.row-field-disp');
        const inp = tr.querySelector('.row-field-inp');
        if (!disp || !inp) return null;
        disp.hidden = edition;
        inp.hidden = !edition;
        tr.querySelector('.ligne-del-form').hidden = !edition;
        tr.querySelector('.ligne-edit-btn').hidden = edition;
        tr.querySelector('.ligne-annuler-btn').hidden = !edition;
        return inp;
    };
    document.addEventListener('click', e => {
        const btn = e.target.closest('.ligne-edit-btn');
        if (btn) {
            const tr = btn.closest('tr');
            if (tr) bascule(tr, true)?.querySelector('input[type="text"]')?.focus();
            return;
        }
        // La croix referme la ligne sans rien changer, à l'emplacement même du
        // crayon : un seul endroit pour ouvrir l'édition et pour la quitter.
        const annul = e.target.closest('.ligne-annuler-btn');
        if (!annul) return;
        const tr = annul.closest('tr');
        if (!tr) return;
        tr.querySelector('.row-field-inp')?.reset();
        bascule(tr, false);
    });
})();
</script>
