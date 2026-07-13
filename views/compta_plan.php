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
<div class="page-head page-head-sub">
    <?= lien_retour('?p=compta_bilan', 'Comptes annuels') ?>
    <h1>Plan comptable</h1>
</div>
<?php if ($saved): ?><p class="ok flash">Plan comptable mis à jour.</p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>

<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=compta_plan" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>

<div class="card form" id="plan-card">
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
                <button type="submit" class="btn btn-danger"><?= icon('trash') ?> Supprimer</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // Conserve la position de défilement à travers les rechargements (drag-and-drop,
    // renommage, archivage…) pour ne pas « remonter en haut » à chaque action.
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const SCROLL_KEY = 'planScroll';
    const sc = sessionStorage.getItem(SCROLL_KEY);
    if (sc !== null) { sessionStorage.removeItem(SCROLL_KEY); window.scrollTo(0, parseInt(sc, 10) || 0); }
    const saveScroll = () => sessionStorage.setItem(SCROLL_KEY, window.scrollY);
    document.querySelectorAll('form[action="?p=compta_plan"]').forEach(f => f.addEventListener('submit', saveScroll));

    // JS actif : lecture seule par défaut, contrôles de repli masqués.
    document.getElementById('plan-card').classList.add('dnd-on');
    // Crayon → passe la ligne en édition ; le libellé s'enregistre à la validation.
    document.querySelectorAll('.plan-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('.plan-row');
            row.classList.add('editing');
            const inp = row.querySelector('.plan-libelle');
            inp.dataset.orig = inp.value;
            inp.focus(); inp.select();
        });
    });
    document.querySelectorAll('.plan-libelle').forEach(inp => {
        const finir = () => inp.closest('.plan-row').classList.remove('editing');
        inp.addEventListener('change', () => {
            const f = inp.closest('form');
            (f.requestSubmit ? f.requestSubmit() : f.submit());
        });
        inp.addEventListener('blur', finir);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Escape') { inp.value = inp.dataset.orig ?? inp.value; finir(); inp.blur(); }
            else if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
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

    const INDENT = 22; // px par niveau
    let dragId = null, dragSens = null, startX = 0, indic = null;
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

    document.querySelectorAll('.plan-grip').forEach(g => {
        g.addEventListener('dragstart', e => {
            const row = g.closest('.plan-row');
            dragId = row.dataset.id; dragSens = row.dataset.sens; startX = e.clientX;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
        });
    });

    // Lignes du même sens, hors catégorie déplacée et ses descendants.
    function visibles() {
        const all = [...document.querySelectorAll('.plan-table[data-sens="' + dragSens + '"] .plan-row')]
            .map(r => ({ id: r.dataset.id, parent: r.dataset.parent || '0', depth: +r.dataset.depth, el: r }));
        const byId = {}; all.forEach(i => byId[i.id] = i);
        const estDescendant = id => { let c = byId[id]; while (c) { if (c.id === dragId) return true; c = byId[c.parent]; } return false; };
        return { liste: all.filter(i => i.id !== dragId && !estDescendant(i.id)), byId };
    }

    // Projette (parent, ancre, profondeur) pour un dépôt à la ligne survolée.
    // Si le curseur est dans le tiers supérieur de la première ligne visible, insère
    // avant elle (premier rang), ce qui corrige le bug "impossible de placer en tête".
    function projeter(e) {
        if (!dragId) return null;
        const over = e.target.closest('.plan-row');
        if (!over || over.dataset.sens !== dragSens) return null;
        const { liste, byId } = visibles();
        const idx = liste.findIndex(i => i.id === over.dataset.id);
        if (idx < 0) return null;

        // Détecte si on veut insérer AVANT la première ligne (premier rang).
        const rOver = over.getBoundingClientRect();
        const avantPremier = idx === 0 && e.clientY < rOver.top + rOver.height * 0.38;

        let prev, next, depth, parent, anchor;
        if (avantPremier) {
            // Premier rang : toujours à la racine (depth 0, parent nul).
            depth  = 0; parent = '0'; anchor = null;
            next   = liste[0];
        } else {
            prev = liste[idx]; next = liste[idx + 1];
            const dragDepth = Math.round((e.clientX - startX) / INDENT);
            const maxDepth  = prev.depth + 1;
            const minDepth  = next ? next.depth : 0;
            depth = clamp(prev.depth + dragDepth, minDepth, maxDepth);
            if (depth === prev.depth + 1) {
                parent = prev.id; anchor = null;
            } else {
                let cur = prev;
                while (cur && cur.depth > depth) cur = byId[cur.parent];
                parent = cur ? cur.parent : '0'; anchor = cur ? cur.id : null;
            }
        }

        const freres = liste.filter(i => i.parent === parent).map(i => i.id);
        let order;
        if (anchor === null) order = [dragId, ...freres];
        else { const k = freres.indexOf(anchor); order = [...freres.slice(0, k + 1), dragId, ...freres.slice(k + 1)]; }
        return { parent: parent === '0' ? '' : parent, order, depth, afterEl: over, avantPremier };
    }

    function showIndic(p) {
        if (!indic) {
            indic = document.createElement('div');
            indic.className = 'plan-indic';
            document.body.appendChild(indic);
        }
        const r = p.afterEl.getBoundingClientRect();
        const off = p.depth * INDENT;
        const y   = p.avantPremier ? r.top : r.bottom; // au-dessus si premier rang
        indic.style.display = 'block';
        indic.style.top     = (y - 1) + 'px';
        indic.style.left    = (r.left + off) + 'px';
        indic.style.width   = Math.max(40, r.right - r.left - off - 12) + 'px';
    }
    function hideIndic() { if (indic) indic.style.display = 'none'; }

    document.addEventListener('dragover', e => {
        const p = projeter(e);
        if (!p) { hideIndic(); return; }
        e.preventDefault();
        showIndic(p);
    });
    document.addEventListener('drop', e => {
        const p = projeter(e);
        hideIndic();
        if (!p) return;
        e.preventDefault();
        const f = document.getElementById('reorder-form');
        f.querySelector('[name=id]').value = dragId;
        f.querySelector('[name=parent_id]').value = p.parent;
        f.querySelector('[name=order]').value = p.order.join(',');
        saveScroll();
        f.submit();
    });
    document.addEventListener('dragend', hideIndic);
})();
</script>
