<?php
/** @var array $axes */ /** @var bool $saved */
?>
<div class="page-head page-head-sub">
    <?= lien_retour('?p=compta_analyse', 'Analyse') ?>
    <h1>Axes analytiques</h1>
</div>
<?php if ($saved): ?><p class="ok flash">Axe enregistré.</p><?php endif; ?>

<div class="card form">
    <div class="card-head">
        <p class="muted small mb-0">Un axe analytique permet de ventiler les écritures selon un critère transversal (projet, secteur, activité…). Associez un axe à chaque écriture pour obtenir le résultat par axe dans les comptes annuels.</p>
        <button type="button" id="btn-new-axe" class="btn btn-sm"><?= icon('plus') ?> Ajouter un axe</button>
    </div>
    <table class="list" style="margin-top:10px">
        <thead><tr><th style="width:36px"></th><th>Axe analytique</th><th></th></tr></thead>
        <tbody>
        <?php if (!$axes): ?>
            <tr><td colspan="3" class="muted small">Aucun axe analytique. Exemples : Label, Tour, Stages, Local.</td></tr>
        <?php endif; ?>
        <?php foreach ($axes as $a): ?>
            <tr>
                <td style="padding:0 0 0 12px">
                    <form method="post" action="?p=compta_axes">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="toggle_actif">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <label class="regle-toggle" title="<?= $a['actif'] ? 'Désactiver' : 'Activer' ?>">
                            <input type="checkbox" name="actif" value="1" <?= $a['actif'] ? 'checked' : '' ?>
                                   class="regle-actif-cb" onchange="this.closest('form').submit()">
                            <span class="regle-toggle-pill"></span>
                        </label>
                    </form>
                </td>
                <td>
                    <div class="axe-read">
                        <strong><?= e($a['libelle']) ?></strong>
                        <?php if ($a['code']): ?><span class="muted small"> · <?= e($a['code']) ?></span><?php endif; ?>
                        <?php if (!$a['actif']): ?><span class="badge muted-badge" style="margin-left:6px">inactif</span><?php endif; ?>
                    </div>
                    <form method="post" action="?p=compta_axes" class="inline-edit axe-edit-form" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="update">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <input name="libelle" value="<?= e($a['libelle']) ?>" class="grow" required placeholder="Libellé">
                        <input name="code" value="<?= e($a['code']) ?>" placeholder="Code court" class="w-iban" style="max-width:120px" title="Code court optionnel (ex. LAB, TOU, STA)">
                        <button type="submit" class="btn ghost btn-sm" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </td>
                <td class="actions nowrap">
                    <form method="post" action="?p=compta_axes" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="move_up">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter"><?= icon('chevron-up') ?></button>
                    </form>
                    <form method="post" action="?p=compta_axes" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="move_down">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre"><?= icon('chevron-down') ?></button>
                    </form>
                    <button type="button" class="btn ghost btn-sm icon-only axe-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only axe-cancel-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                    <form method="post" action="?p=compta_axes" onsubmit="return confirm('Supprimer cet axe ? Les écritures associées ne seront pas supprimées.');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="axe-add-row" hidden>
            <tr>
                <td colspan="2">
                    <form method="post" action="?p=compta_axes" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="create">
                        <input name="libelle" placeholder="ex. Label, Tour, Stages, Local" required class="grow">
                        <input name="code" placeholder="Code court" class="w-iban" style="max-width:120px" title="Code court optionnel">
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" id="cancel-new-axe"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
(function () {
    document.querySelectorAll('.axe-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            row.querySelector('.axe-read').hidden = true;
            row.querySelector('.axe-edit-form').hidden = false;
            btn.hidden = true;
            row.querySelector('.axe-cancel-btn').hidden = false;
            row.querySelector('input[name="libelle"]')?.focus();
        });
    });
    document.querySelectorAll('.axe-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            row.querySelector('.axe-read').hidden = false;
            row.querySelector('.axe-edit-form').hidden = true;
            btn.hidden = true;
            row.querySelector('.axe-edit-btn').hidden = false;
        });
    });
    document.getElementById('btn-new-axe')?.addEventListener('click', () => {
        const tfoot = document.getElementById('axe-add-row');
        tfoot.hidden = false;
        tfoot.querySelector('input[name="libelle"]')?.focus();
    });
    document.getElementById('cancel-new-axe')?.addEventListener('click', () => {
        document.getElementById('axe-add-row').hidden = true;
    });
})();
</script>
