<?php /** @var bool $saved */ /** @var ?string $err */ /** @var array $lignes */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($err === 'used'): ?><p class="err flash">Suppression impossible : au moins une structure, un lieu ou l'employeur utilise ce pays (ou c'est le dernier pays restant).</p><?php endif; ?>


<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=parametres_pays" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>

<div class="section-head mt-0">
    <h2 class="mt-0">Pays <?= info_tip("Liste de pays commune à toute l'application (structures, salles/festivals, employeur, événements,
    factures). Renommer un pays met aussi à jour les fiches qui
    l'utilisent déjà.") ?> </h2>
    <button type="button" class="btn btn-sm ml-auto" data-show="pays-add"><?= icon('plus') ?> Nouveau pays</button>
</div>
<div class="card form table-scroll" id="pays-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucun pays.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $i => $p): $pid = (int) $p['id']; ?>
        <tr class="plan-row" data-id="<?= $pid ?>" data-depth="0" data-parent="0">
            <td>
                <div class="inline-edit" style="--depth:0">
                    <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                    <span class="plan-puce" aria-hidden="true">•</span>
                    <span class="plan-nom"><?= pays_drapeau($p['code_iso2']) ?> <?= e($p['nom']) ?></span>
                    <form method="post" action="?p=parametres_pays" class="inline-edit plan-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <input name="nom" value="<?= e($p['nom']) ?>" class="grow plan-libelle" required aria-label="Nom">
                        <input name="code_iso2" value="<?= e($p['code_iso2']) ?>" maxlength="2" style="max-width:4.5rem" required aria-label="Code ISO2" title="Code ISO 3166-1 alpha-2 (ex. CH, FR)" class="plan-code-iso2">
                        <button type="submit" class="btn ghost btn-sm plan-fallback" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </div>
            </td>
            <td class="actions nowrap">
                <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                <form method="post" action="?p=parametres_pays" class="d-inline plan-fallback">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="move">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                    <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $i === count($lignes) - 1 ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                </form>
                <form method="post" action="?p=parametres_pays" onsubmit="return confirm('Supprimer ce pays ?');" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot id="pays-add" hidden>
        <tr>
            <td colspan="2">
                <form method="post" action="?p=parametres_pays" class="inline-edit">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="add">
                    <input name="nom" placeholder="ex. Espagne" required class="grow" aria-label="Nom">
                    <input name="code_iso2" placeholder="ES" maxlength="2" style="max-width:4.5rem" required aria-label="Code ISO2" title="Code ISO 3166-1 alpha-2">
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="pays-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<script>
(function () {
    lassoPlanArbre({
        containerSelector: '#pays-card',
        rowsSelector: '.plan-row',
        scrollKey: 'paysScroll',
        formAction: '?p=parametres_pays',
    });

    // Code ISO2 : soumet le formulaire d'édition dès qu'on le modifie (comme
    // le renommage), sans attendre le bouton de repli (masqué une fois le
    // glisser-déposer actif).
    document.querySelectorAll('.plan-code-iso2').forEach(inp => {
        const finir = () => inp.closest('.plan-row').classList.remove('editing');
        inp.addEventListener('change', () => {
            const f = inp.closest('form');
            (f.requestSubmit ? f.requestSubmit() : f.submit());
        });
        inp.addEventListener('blur', finir);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
    });
})();
</script>
