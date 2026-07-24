<?php /** @var bool $saved */ /** @var ?string $err */ /** @var array $lignes */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($err === 'used'): ?><p class="err flash">Suppression impossible : au moins une salle/festival utilise cette catégorie (ou c'est la dernière restante).</p><?php endif; ?>

<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=parametres_lieux_categories" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>

<div class="section-head mt-0">
    <h2 class="mt-0">Catégories de lieu <?= info_tip("Nature des salles & festivals (Salle, Festival, Salle de concert, Saison culturelle…),
    utilisée dans le champ « Type » d'un lieu, les filtres et l'import. Taxonomie distincte des catégories de structure. Renommer une
    entrée met aussi à jour les lieux qui l'utilisent déjà.") ?> </h2>
    <button type="button" class="btn btn-sm ml-auto" data-show="cat-add"><?= icon('plus') ?> Nouvelle catégorie</button>
</div>
<div class="card form table-scroll" id="cat-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucune catégorie.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $i => $c): $cid = (int) $c['id']; ?>
        <tr class="plan-row" data-id="<?= $cid ?>" data-depth="0" data-parent="0">
            <td>
                <div class="inline-edit" style="--depth:0">
                    <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                    <span class="plan-puce" aria-hidden="true">•</span>
                    <span class="plan-nom"><?= e($c['nom']) ?></span>
                    <form method="post" action="?p=parametres_lieux_categories" class="inline-edit plan-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input name="nom" value="<?= e($c['nom']) ?>" class="grow plan-libelle" required aria-label="Nom">
                        <button type="submit" class="btn ghost btn-sm plan-fallback" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </div>
            </td>
            <td class="actions nowrap">
                <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                <form method="post" action="?p=parametres_lieux_categories" class="d-inline plan-fallback">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="move">
                    <input type="hidden" name="id" value="<?= $cid ?>">
                    <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                    <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $i === count($lignes) - 1 ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                </form>
                <?php if ((int) $c['nb'] === 0): ?>
                <form method="post" action="?p=parametres_lieux_categories" onsubmit="return confirm('Supprimer cette catégorie de lieu ?');" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $cid ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
                <?php else: ?>
                <button type="button" class="btn ghost btn-sm icon-only lc-del-btn" title="Supprimer" aria-label="Supprimer"
                        data-id="<?= $cid ?>" data-nom="<?= e($c['nom']) ?>" data-nb="<?= (int) $c['nb'] ?>"><?= icon('trash') ?></button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot id="cat-add" hidden>
        <tr>
            <td colspan="2">
                <form method="post" action="?p=parametres_lieux_categories" class="inline-edit">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="add">
                    <input name="nom" placeholder="ex. Salle de spectacle" required class="grow" aria-label="Nom">
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="cat-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<!-- Suppression d'une catégorie utilisée : réaffecter les lieux d'abord -->
<div id="lc-del-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <h3 class="mt-0">Supprimer « <span id="lc-del-nom"></span> »</h3>
        <p class="muted small"><strong id="lc-del-nb"></strong> salle(s)/festival(s) utilisent cette catégorie. Réaffectez-les avant de supprimer.</p>
        <form method="post" action="?p=parametres_lieux_categories" id="lc-del-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="delete">
            <input type="hidden" name="id" id="lc-del-id" value="">
            <label>Réaffecter vers
                <select name="reaffecter_vers" id="lc-del-cible">
                    <?php foreach ($lignes as $c): ?><option value="<?= e($c['nom']) ?>" data-id="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" id="lc-del-cancel" class="btn ghost">Annuler</button>
                <button type="submit" class="btn danger"><?= icon('trash') ?> Réaffecter et supprimer</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    lassoPlanArbre({
        containerSelector: '#cat-card',
        rowsSelector: '.plan-row',
        scrollKey: 'lieuCatScroll',
        formAction: '?p=parametres_lieux_categories',
    });

    var modal = document.getElementById('lc-del-modal');
    var cible = document.getElementById('lc-del-cible');
    function open(btn) {
        document.getElementById('lc-del-id').value = btn.dataset.id;
        document.getElementById('lc-del-nom').textContent = btn.dataset.nom;
        document.getElementById('lc-del-nb').textContent = btn.dataset.nb;
        Array.from(cible.options).forEach(function (o) {
            var self = o.dataset.id === btn.dataset.id;
            o.hidden = self; o.disabled = self;
        });
        if (cible.selectedOptions[0] && cible.selectedOptions[0].disabled) {
            var i = Array.from(cible.options).findIndex(function (o) { return !o.disabled; });
            if (i >= 0) cible.selectedIndex = i;
        }
        modal.removeAttribute('hidden');
    }
    var close = function () { modal.setAttribute('hidden', ''); };
    document.querySelectorAll('.lc-del-btn').forEach(function (b) { b.addEventListener('click', function () { open(b); }); });
    document.getElementById('lc-del-cancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
