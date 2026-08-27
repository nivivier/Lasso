<?php /** @var bool $saved */ /** @var ?int $sync */ /** @var ?string $err */ /** @var array $lignes */ /** @var array $map */

// Options de catégorie racine pour le <select> de repli (reparenter une
// sous-catégorie sans glisser-déposer) — jamais de « racine » ici, une
// sous-catégorie a toujours une catégorie racine pour parent.
$parentOptions = function (?int $selected) use ($map): string {
    $h = '';
    foreach ($map as $r) {
        if (plan_pid($r['parent_id'] ?? null) !== 0) {
            continue;
        }
        $rid = (int) $r['id'];
        $h .= '<option value="' . $rid . '"' . ($selected === $rid ? ' selected' : '') . '>' . e($r['nom']) . '</option>';
    }
    return $h;
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($sync !== null): ?><p class="ok flash"><?= $sync > 0 ? $sync . ' sous-catégorie(s) ajoutée(s) depuis les structures.' : 'Taxonomie déjà à jour — aucune sous-catégorie à ajouter.' ?></p><?php endif; ?>
<?php if ($err === 'cat_used'): ?><p class="err flash">Suppression impossible : au moins une structure utilise cette catégorie (ou c'est la dernière catégorie restante).</p><?php endif; ?>
<?php if ($err === 'cat_a_des_enfants'): ?><p class="err flash">Suppression impossible : cette catégorie a encore des sous-catégories — les supprimer ou les déplacer d'abord.</p><?php endif; ?>
<?php if ($err === 'souscat_used'): ?><p class="err flash">Suppression impossible : au moins une structure utilise cette sous-catégorie.</p><?php endif; ?>

<p class="muted small">
    Catégories et sous-catégories des structures (booking), utilisées dans les listes de choix des
    fiches et des filtres. Une sous-catégorie est toujours imbriquée dans une catégorie — glissez une
    ligne pour la réordonner ou la déplacer vers une autre catégorie. Renommer une entrée met aussi à
    jour les structures qui l'utilisent déjà.
</p>

<?php $peutEcrireCat = peut_ecrire('booking'); ?>
<?php if ($peutEcrireCat): ?>
<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=parametres_structures" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>
<?php endif; ?>

<div class="section-head mt-0">
    <h2 class="mt-0">Catégories</h2>
    <?php if ($peutEcrireCat): ?>
    <form method="post" action="?p=parametres_structures" class="d-inline ml-auto">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="sync">
        <button type="submit" class="btn ghost btn-sm" title="Ajouter à la taxonomie les sous-catégories présentes sur des structures (import) mais manquantes ici"><?= icon('calendar-sync') ?> Synchroniser depuis les structures</button>
    </form>
    <button type="button" class="btn btn-sm" data-show="cat-add"><?= icon('plus') ?> Nouvelle catégorie</button>
    <?php endif; ?>
</div>
<div class="card form table-scroll" id="categories-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucune catégorie.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $c): $cid = (int) $c['id']; $prof = (int) $c['profondeur']; $estRacine = $prof === 0; $nbUsage = (int) ($usage[$cid] ?? 0); ?>
        <tr class="plan-row <?= $c['a_enfants'] ? 'plan-groupe' : '' ?>"
            data-id="<?= $cid ?>" data-depth="<?= $prof ?>" data-parent="<?= (int) plan_pid($c['parent_id'] ?? null) ?>">
            <td>
                <div class="inline-edit" style="--depth:<?= $prof ?>">
                    <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                    <span class="plan-puce" aria-hidden="true"><?= $c['a_enfants'] ? icon('chevron-down') : '•' ?></span>
                    <span class="plan-nom"><?= e($c['nom']) ?></span>
                    <?php if ($peutEcrireCat): ?>
                    <form method="post" action="?p=parametres_structures" class="inline-edit plan-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input name="nom" value="<?= e($c['nom']) ?>" class="grow plan-libelle" required aria-label="Nom">
                        <?php if (!$estRacine): ?>
                            <label class="plan-parent plan-fallback">dans
                                <select name="parent_id"><?= $parentOptions(plan_pid($c['parent_id'] ?? null)) ?></select>
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn ghost btn-sm plan-fallback" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($nbUsage > 0): ?>
                        <a class="badge muted-badge" href="<?= e(lien_structures_categorie($cid)) ?>"><?= $nbUsage ?> structure<?= $nbUsage > 1 ? 's' : '' ?></a>
                    <?php endif; ?>
                </div>
            </td>
            <td class="actions nowrap">
                <?php if ($peutEcrireCat): ?>
                <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                <form method="post" action="?p=parametres_structures" class="d-inline plan-fallback">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="move">
                    <input type="hidden" name="id" value="<?= $cid ?>">
                    <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $c['est_premier'] ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                    <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $c['est_dernier'] ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                </form>
                <?php if ($nbUsage === 0 || $c['a_enfants']): ?>
                <form method="post" action="?p=parametres_structures" data-confirm="Supprimer <?= $estRacine ? 'cette catégorie' : 'cette sous-catégorie' ?> ?" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $cid ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
                <?php else: ?>
                <button type="button" class="btn ghost btn-sm icon-only cat-del-btn" title="Supprimer" aria-label="Supprimer"
                        data-id="<?= $cid ?>" data-nom="<?= e($c['nom']) ?>" data-nb="<?= $nbUsage ?>"
                        data-kind="<?= $estRacine ? 'root' : 'sub' ?>" data-parent="<?= (int) plan_pid($c['parent_id'] ?? null) ?>"><?= icon('trash') ?></button>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot id="cat-add" hidden>
        <tr>
            <td colspan="2">
                <form method="post" action="?p=parametres_structures" class="inline-edit">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="add">
                    <input name="nom" placeholder="ex. Festival, Salle de concert…" required class="grow" aria-label="Nom">
                    <select name="parent_id" title="Catégorie parente (vide = nouvelle catégorie racine)">
                        <option value="">— Nouvelle catégorie racine —</option>
                        <?= $parentOptions(null) ?>
                    </select>
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="cat-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<!-- Suppression d'une catégorie/sous-catégorie utilisée : réaffecter d'abord -->
<div id="cat-del-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <h3 class="mt-0">Supprimer « <span id="cat-del-nom"></span> »</h3>
        <p class="muted small"><strong id="cat-del-nb"></strong> structure(s) utilisent <span id="cat-del-type">cette catégorie</span>. Réaffectez-les avant de supprimer.</p>
        <form method="post" action="?p=parametres_structures" id="cat-del-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="delete">
            <input type="hidden" name="id" id="cat-del-id" value="">
            <label>Réaffecter vers
                <select name="reaffecter_vers" id="cat-del-cible">
                    <option value="" data-kind="aucune">(aucune sous-catégorie)</option>
                    <?php foreach ($lignes as $c): $estR = (int) $c['profondeur'] === 0; ?>
                        <option value="<?= e($c['nom']) ?>" data-id="<?= (int) $c['id'] ?>" data-kind="<?= $estR ? 'root' : 'sub' ?>" data-parent="<?= (int) plan_pid($c['parent_id'] ?? null) ?>"><?= str_repeat("\u{00A0}\u{00A0}", (int) $c['profondeur']) ?><?= e($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" id="cat-del-cancel" class="btn ghost">Annuler</button>
                <button type="submit" class="btn danger"><?= icon('trash') ?> Réaffecter et supprimer</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    lassoPlanArbre({
        containerSelector: '#categories-card',
        rowsSelector: '.plan-row',
        scrollKey: 'categoriesScroll',
        formAction: '?p=parametres_structures',
    });

    // Modale de suppression avec réaffectation : cible = une racine (pour une
    // catégorie) ou une autre sous-catégorie du même parent / aucune (pour une
    // sous-catégorie).
    var modal = document.getElementById('cat-del-modal');
    if (modal) {
        var cible = document.getElementById('cat-del-cible');
        var open = function (btn) {
            document.getElementById('cat-del-id').value = btn.dataset.id;
            document.getElementById('cat-del-nom').textContent = btn.dataset.nom;
            document.getElementById('cat-del-nb').textContent = btn.dataset.nb;
            document.getElementById('cat-del-type').textContent = btn.dataset.kind === 'root' ? 'cette catégorie' : 'cette sous-catégorie';
            var premierVisible = -1;
            Array.from(cible.options).forEach(function (o, idx) {
                var ok;
                if (btn.dataset.kind === 'root') {
                    ok = o.dataset.kind === 'root' && o.dataset.id !== btn.dataset.id;
                } else {
                    ok = o.dataset.kind === 'aucune'
                        || (o.dataset.kind === 'sub' && o.dataset.parent === btn.dataset.parent && o.dataset.id !== btn.dataset.id);
                }
                o.hidden = !ok; o.disabled = !ok;
                if (ok && premierVisible < 0) premierVisible = idx;
            });
            if (premierVisible >= 0) cible.selectedIndex = premierVisible;
            modal.removeAttribute('hidden');
        };
        var close = function () { modal.setAttribute('hidden', ''); };
        document.querySelectorAll('.cat-del-btn').forEach(function (b) { b.addEventListener('click', function () { open(b); }); });
        document.getElementById('cat-del-cancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    }
})();
</script>
