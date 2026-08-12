<?php
/** @var array $lignes */ /** @var array $plan */ /** @var array $feuilles */ /** @var array $ecrCounts */
/** @var bool $saved */ /** @var ?string $flagErr */

// Toutes les catégories par sens (pour les <select> de parent), avec leur chemin.
$parentsParSens = ['produit' => [], 'charge' => []];
foreach (plan_liste_ordonnee($plan) as $r) {
    $parentsParSens[$r['sens']][] = ['id' => (int) $r['id'], 'chemin' => plan_chemin((int) $r['id'], $plan)];
}
$parentOptions = function (string $sens, ?int $selected, int $excludeId) use ($parentsParSens): string {
    $h = '<option value="">— Catégorie principale —</option>';
    foreach ($parentsParSens[$sens] as $c) {
        if ($c['id'] === $excludeId) {
            continue;
        }
        $h .= '<option value="' . $c['id'] . '"' . ($selected === $c['id'] ? ' selected' : '') . '>' . e($c['chemin']) . '</option>';
    }
    return $h;
};
$flashErr = [
    'children' => 'Action impossible : cette catégorie contient des sous-catégories.',
];
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php if ($saved): ?><p class="ok flash">Plan comptable mis à jour.</p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>

<div class="module-content"><div class="module-content-inner">
<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=compta_plan" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>

<div class="form" id="plan-card">
    <?php foreach (['produit' => 'Produits (recettes)', 'charge' => 'Charges (dépenses)'] as $sens => $titre):
        $rows = array_values(array_filter($lignes, fn($l) => $l['sens'] === $sens)); ?>
    <div class="section-head <?= $sens === 'produit' ? 'mt-0' : '' ?>">
        <h2 class="mt-0"><?= e($titre) ?></h2>
        <button type="button" class="btn btn-sm ml-auto"
                data-show="plan-add-<?= $sens ?>"><?= icon('plus') ?> Nouveau</button>
    </div>
    <div class="table-scroll">
    <table class="list mb-16 plan-table" data-sens="<?= $sens ?>">
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="2" class="muted small">Aucune catégorie.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $p): $pid = (int) $p['id']; $prof = (int) $p['profondeur']; $actif = (int) $p['actif'] === 1; ?>
            <tr class="plan-row <?= $p['a_enfants'] ? 'plan-groupe' : '' ?> <?= $actif ? '' : 'plan-archive' ?>"
                data-id="<?= $pid ?>" data-sens="<?= $sens ?>" data-depth="<?= $prof ?>" data-parent="<?= (int) plan_pid($p['parent_id'] ?? null) ?>">
                <td>
                    <div class="inline-edit" style="--depth:<?= $prof ?>">
                        <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                        <span class="plan-puce" aria-hidden="true"><?= $p['a_enfants'] ? icon('chevron-down') : '•' ?></span>
                        <span class="plan-nom"><?= e($p['libelle']) ?></span>
                        <form method="post" action="?p=compta_plan" class="inline-edit plan-edit">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="section" value="edit">
                            <input type="hidden" name="id" value="<?= $pid ?>">
                            <input name="libelle" value="<?= e($p['libelle']) ?>" class="grow plan-libelle" required>
                            <label class="plan-parent plan-fallback">dans
                                <select name="parent_id"><?= $parentOptions($sens, plan_pid($p['parent_id'] ?? null) ?: null, $pid) ?></select>
                            </label>
                            <button type="submit" class="btn ghost btn-sm plan-fallback" title="Enregistrer"><?= icon('save') ?></button>
                        </form>
                        <?php if (!$actif): ?><span class="badge warn-badge">archivée</span><?php endif; ?>
                    </div>
                </td>
                <td class="actions nowrap">
                    <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    <form method="post" action="?p=compta_plan" class="d-inline plan-fallback">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="move">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $p['est_premier'] ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                        <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $p['est_dernier'] ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                    </form>
                    <?php if (!$p['a_enfants']): ?>
                    <form method="post" action="?p=compta_plan" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="archive">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="<?= $actif ? 'Archiver' : 'Réactiver' ?>" aria-label="<?= $actif ? 'Archiver' : 'Réactiver' ?>"><?= icon($actif ? 'archive' : 'check') ?></button>
                    </form>
                    <?php endif; ?>
                    <?php $nbEcr = $ecrCounts[$pid] ?? 0; if (!$p['a_enfants'] && $nbEcr > 0): ?>
                    <button type="button" class="btn ghost btn-sm icon-only plan-del-btn" title="Supprimer"
                            aria-label="Supprimer" data-id="<?= $pid ?>" data-nom="<?= e($p['libelle']) ?>" data-nb="<?= $nbEcr ?>"><?= icon('trash') ?></button>
                    <?php else: ?>
                    <form method="post" action="?p=compta_plan" onsubmit="return confirm('Supprimer cette catégorie ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="plan-add-<?= $sens ?>" hidden>
            <tr>
                <td colspan="2">
                    <form method="post" action="?p=compta_plan" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="add">
                        <input type="hidden" name="sens" value="<?= $sens ?>">
                        <input name="libelle" placeholder="<?= $sens === 'produit' ? 'ex. Dons privés' : 'ex. Loyer' ?>" required class="grow">
                        <select name="parent_id" title="Catégorie parente"><?= $parentOptions($sens, null, 0) ?></select>
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" data-hide="plan-add-<?= $sens ?>"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endforeach; ?>
</div>
</div></div>

<!-- Boîte de dialogue : suppression d'une catégorie contenant des écritures -->
<div id="del-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <h3 class="mt-0">Supprimer « <span id="del-nom"></span> »</h3>
        <p class="muted small">Cette catégorie contient <strong id="del-nb"></strong> écriture(s) déjà classée(s). Que faire de ces écritures ?</p>
        <form method="post" action="?p=compta_plan" id="del-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="del">
            <input type="hidden" name="id" id="del-id" value="">
            <label class="del-opt"><input type="radio" name="ecritures" value="delettrer" checked>
                <span>Supprimer le lettrage <span class="muted">(les écritures redeviennent « à lettrer »)</span></span></label>
            <label class="del-opt"><input type="radio" name="ecritures" value="reaffecter">
                <span>Réaffecter à une autre catégorie :
                    <select name="cible" id="del-cible">
                        <?php foreach ($feuilles as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['chemin']) ?></option><?php endforeach; ?>
                    </select>
                </span></label>
            <div class="modal-actions">
                <button type="button" id="del-cancel" class="btn ghost">Annuler</button>
                <button type="submit" class="btn danger"><?= icon('trash') ?> Supprimer</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    lassoPlanArbre({
        containerSelector: '#plan-card',
        rowsSelector: '.plan-row',
        scrollKey: 'planScroll',
        formAction: '?p=compta_plan',
        groupAttr: 'sens',
    });

    // Modale de suppression : que faire des écritures déjà classées ?
    const modal = document.getElementById('del-modal');
    if (modal) {
        const cible = document.getElementById('del-cible');
        const open = btn => {
            document.getElementById('del-id').value = btn.dataset.id;
            document.getElementById('del-nom').textContent = btn.dataset.nom;
            document.getElementById('del-nb').textContent = btn.dataset.nb;
            [...cible.options].forEach(o => { const self = o.value === btn.dataset.id; o.hidden = self; o.disabled = self; });
            if (cible.selectedOptions[0] && cible.selectedOptions[0].disabled) {
                const i = [...cible.options].findIndex(o => !o.disabled);
                if (i >= 0) cible.selectedIndex = i;
            }
            modal.removeAttribute('hidden');
        };
        const close = () => modal.setAttribute('hidden', '');
        document.querySelectorAll('.plan-del-btn').forEach(b => b.addEventListener('click', () => open(b)));
        document.getElementById('del-cancel').addEventListener('click', close);
        modal.addEventListener('click', e => { if (e.target === modal) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
        cible.addEventListener('focus', () => {
            const r = document.querySelector('input[name="ecritures"][value="reaffecter"]');
            if (r) r.checked = true;
        });
    }
})();
</script>
