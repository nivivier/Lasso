<?php
/** @var array $comptes */ /** @var ?string $err */ /** @var bool $saved */ /** @var ?string $flagErr */
?>
<div class="page-head page-head-sub">
    <?= lien_retour('?p=compta_bilan', 'Comptes annuels') ?>
    <h1>Comptes bancaires</h1>
</div>
<?php if ($saved): ?><p class="ok flash">Compte bancaire enregistré.</p><?php endif; ?>
<?php if ($flagErr === 'used'): ?><p class="err flash">Suppression impossible : des écritures sont rattachées à ce compte.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <div class="card-head">
        <p class="muted small mb-0">Un compte bancaire par IBAN. L'IBAN sert à rattacher automatiquement les fichiers importés et à cibler les règles de lettrage.</p>
        <button type="button" id="btn-new-compte" class="btn btn-sm"><?= icon('plus') ?> Ajouter un compte</button>
    </div>
    <table class="list" style="margin-top:10px">
        <thead><tr><th>Compte bancaire</th><th></th></tr></thead>
        <tbody>
        <?php if (!$comptes): ?>
            <tr><td colspan="2" class="muted small">Aucun compte bancaire.</td></tr>
        <?php endif; ?>
        <?php foreach ($comptes as $c): ?>
            <tr>
                <td>
                    <div class="compte-read">
                        <strong><?= e($c['libelle']) ?></strong>
                        <?php if ($c['iban']): ?><span class="muted small"> · <?= e($c['iban']) ?></span><?php endif; ?>
                    </div>
                    <form method="post" action="?p=compta_comptes" class="inline-edit compte-edit-form" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input name="libelle" value="<?= e($c['libelle']) ?>" class="grow" required>
                        <input name="iban" value="<?= e($c['iban']) ?>" placeholder="CH…" class="w-iban">
                        <button type="submit" class="btn ghost btn-sm" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </td>
                <td class="actions nowrap">
                    <button type="button" class="btn ghost btn-sm icon-only compte-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only compte-cancel-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                    <form method="post" action="?p=compta_comptes" onsubmit="return confirm('Supprimer ce compte ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="compte-add-row" hidden>
            <tr>
                <td>
                    <form method="post" action="?p=compta_comptes" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="add">
                        <input name="libelle" placeholder="ex. Compte PostFinance — Local" required class="grow">
                        <input name="iban" placeholder="CH86 0900 0000 1587 1688 5" class="w-iban">
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" id="cancel-new-compte"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
(function () {
    // Basculer une ligne compte entre lecture et édition.
    document.querySelectorAll('.compte-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            row.querySelector('.compte-read').hidden = true;
            row.querySelector('.compte-edit-form').hidden = false;
            btn.hidden = true;
            row.querySelector('.compte-cancel-btn').hidden = false;
            row.querySelector('input[name="libelle"]')?.focus();
        });
    });
    document.querySelectorAll('.compte-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            row.querySelector('.compte-read').hidden = false;
            row.querySelector('.compte-edit-form').hidden = true;
            btn.hidden = true;
            row.querySelector('.compte-edit-btn').hidden = false;
        });
    });

    // Bouton « Nouveau » → affiche la ligne d'ajout.
    document.getElementById('btn-new-compte')?.addEventListener('click', () => {
        const tfoot = document.getElementById('compte-add-row');
        tfoot.hidden = false;
        tfoot.querySelector('input[name="libelle"]')?.focus();
    });
    document.getElementById('cancel-new-compte')?.addEventListener('click', () => {
        document.getElementById('compte-add-row').hidden = true;
    });
})();
</script>
