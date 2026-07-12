<?php
/** @var array $lignes */ /** @var array $map */ /** @var array $comptes */ /** @var string $token */ /** @var ?string $flagErr */
$termePluriel = evenements_terme_spectacle();
$termeSingulier = mb_strtolower(evenements_terme_spectacle(false));

$flashErr = [
    'children' => 'Suppression impossible : ce ' . $termeSingulier . ' contient des sous-' . mb_strtolower($termePluriel) . '.',
    'used'     => 'Suppression impossible : des événements sont rattachés à ce ' . $termeSingulier . '.',
];
$parentOptions = function (int $excludeId) use ($map): string {
    $h = '<option value="">— Racine (nouvel artiste) —</option>';
    foreach (plan_liste_ordonnee($map) as $r) {
        $rid = (int) $r['id'];
        if ($rid === $excludeId) {
            continue;
        }
        $h .= '<option value="' . $rid . '">' . e(spectacle_chemin($rid, $map)) . '</option>';
    }
    return $h;
};
?>
<?= lien_retour('?p=evenements_liste', 'Événements') ?>
<div class="page-head">
    <h1><?= e($termePluriel) ?></h1>
    <div class="head-actions">
        <button type="button" class="btn btn-sm" data-show="spectacle-add"><?= icon('plus') ?><span class="lbl"> Nouveau <?= e($termeSingulier) ?></span></button>
    </div>
</div>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>

<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=spectacles" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>

<?php if (!$lignes): ?>
    <p class="muted">Aucun <?= e($termeSingulier) ?> pour l'instant. Commencez par en ajouter un.</p>
    <form method="post" action="?p=spectacles" class="inline-edit card form" id="spectacle-add" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="add">
        <input name="nom" placeholder="ex. Nom de l'artiste ou du spectacle" required class="grow">
        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
        <button type="button" class="btn ghost btn-sm" data-hide="spectacle-add"><?= icon('x') ?> Annuler</button>
    </form>
<?php else: ?>
<div class="form" id="spectacles-card">
    <table class="list mb-16 plan-table spectacles-table">
        <thead>
            <tr><th></th><th class="num">Confirmés</th><th class="num">En option</th><th class="num">Annulés</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($lignes as $s): $sid = (int) $s['id']; $prof = (int) $s['profondeur'];
            $c = $comptes[$sid] ?? ['confirme' => 0, 'option' => 0, 'annule' => 0];
            $total = $c['confirme'] + $c['option'] + $c['annule'];
            $compteLien = function (string $statut, int $n) use ($s, $sid): string {
                if ($n === 0) {
                    return '<span class="muted">0</span>';
                }
                if ($s['a_enfants']) {
                    return (string) $n; // groupe : total agrégé, pas de filtre direct possible
                }
                return '<a href="?p=evenements_liste&spectacle_id=' . $sid . '&statut=' . e($statut) . '">' . $n . '</a>';
            };
        ?>
            <tr class="plan-row row-link <?= $s['a_enfants'] ? 'plan-groupe' : '' ?>" tabindex="0" role="link"
                data-id="<?= $sid ?>" data-depth="<?= $prof ?>" data-parent="<?= (int) plan_pid($s['parent_id'] ?? null) ?>" data-href="?p=spectacle&id=<?= $sid ?>">
                <td>
                    <div class="inline-edit" style="--depth:<?= $prof ?>">
                        <span class="plan-grip" draggable="true" onclick="event.stopPropagation()" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                        <span class="plan-puce" aria-hidden="true"><?= $s['a_enfants'] ? icon('chevron-down') : '•' ?></span>
                        <a class="plan-nom" href="?p=spectacle&id=<?= $sid ?>"><?= e($s['nom']) ?></a>
                        <form method="post" action="?p=spectacles" class="inline-edit plan-edit">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="section" value="rename">
                            <input type="hidden" name="id" value="<?= $sid ?>">
                            <input name="nom" value="<?= e($s['nom']) ?>" class="grow plan-libelle" required>
                            <button type="submit" class="btn ghost btn-sm" title="Enregistrer"><?= icon('save') ?></button>
                        </form>
                        <?php if ($s['suisa_feuille_fichier']): ?>
                            <a class="muted small" href="<?= e($s['suisa_feuille_fichier']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">PDF</a>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="num"><?= $compteLien('confirme', $c['confirme']) ?></td>
                <td class="num"><?= $compteLien('option', $c['option']) ?></td>
                <td class="num"><?= $compteLien('annule', $c['annule']) ?></td>
                <td class="actions nowrap">
                    <button type="button" class="btn ghost btn-sm icon-only export-copy" onclick="event.stopPropagation()"
                            data-url="<?= e(evenements_export_url('evenements_json', $token, $sid)) ?>"
                            title="Copier le lien de synchronisation JSON" aria-label="Copier le lien de synchronisation JSON"><?= icon('file-braces') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only export-copy" onclick="event.stopPropagation()"
                            data-url="<?= e(evenements_export_url('evenements_ical', $token, $sid)) ?>"
                            title="Copier le lien de synchronisation iCal" aria-label="Copier le lien de synchronisation iCal"><?= icon('calendar-sync') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                    <a class="btn ghost btn-sm icon-only" href="?p=spectacle&id=<?= $sid ?>" title="Modifier (notes, PDF, parent)" aria-label="Modifier"><?= icon('file-text') ?></a>
                    <?php if (!$s['a_enfants'] && $total === 0): ?>
                    <form method="post" action="?p=spectacle_delete" onsubmit="return confirm('Supprimer ce <?= e($termeSingulier) ?> ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="spectacle-add" hidden>
            <tr>
                <td colspan="5">
                    <form method="post" action="?p=spectacles" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="add">
                        <input name="nom" placeholder="ex. Nom de l'artiste ou du spectacle" required class="grow">
                        <select name="parent_id" title="Spectacle parent (artiste)"><?= $parentOptions(0) ?></select>
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" data-hide="spectacle-add"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<script>
(function () {
    // Conserve la position de défilement à travers les rechargements (drag-and-drop,
    // renommage…) pour ne pas « remonter en haut » à chaque action.
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const SCROLL_KEY = 'spectaclesScroll';
    const sc = sessionStorage.getItem(SCROLL_KEY);
    if (sc !== null) { sessionStorage.removeItem(SCROLL_KEY); window.scrollTo(0, parseInt(sc, 10) || 0); }
    const saveScroll = () => sessionStorage.setItem(SCROLL_KEY, window.scrollY);
    document.querySelectorAll('form[action="?p=spectacles"]').forEach(f => f.addEventListener('submit', saveScroll));

    // JS actif : lecture seule par défaut (nom + crayon), formulaire de
    // renommage masqué jusqu'au clic (voir .dnd-on dans app.css). Absent si
    // la liste est vide (aucun tableau à afficher).
    document.getElementById('spectacles-card')?.classList.add('dnd-on');

    document.querySelectorAll('.export-copy').forEach(btn => {
        const original = btn.innerHTML;
        btn.addEventListener('click', () => {
            navigator.clipboard.writeText(btn.dataset.url).then(() => {
                btn.innerHTML = <?= json_encode(icon('check'), JSON_UNESCAPED_SLASHES) ?>;
                setTimeout(() => { btn.innerHTML = original; }, 1500);
            });
        });
    });

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

    const INDENT = 22; // px par niveau
    let dragId = null, startX = 0, indic = null;
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

    document.querySelectorAll('.plan-grip').forEach(g => {
        g.addEventListener('dragstart', e => {
            const row = g.closest('.plan-row');
            dragId = row.dataset.id; startX = e.clientX;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
        });
    });

    function visibles() {
        const all = [...document.querySelectorAll('.spectacles-table .plan-row')]
            .map(r => ({ id: r.dataset.id, parent: r.dataset.parent || '0', depth: +r.dataset.depth, el: r }));
        const byId = {}; all.forEach(i => byId[i.id] = i);
        const estDescendant = id => { let c = byId[id]; while (c) { if (c.id === dragId) return true; c = byId[c.parent]; } return false; };
        return { liste: all.filter(i => i.id !== dragId && !estDescendant(i.id)), byId };
    }

    function projeter(e) {
        if (!dragId) return null;
        const over = e.target.closest('.plan-row');
        if (!over) return null;
        const { liste, byId } = visibles();
        const idx = liste.findIndex(i => i.id === over.dataset.id);
        if (idx < 0) return null;

        const rOver = over.getBoundingClientRect();
        const avantPremier = idx === 0 && e.clientY < rOver.top + rOver.height * 0.38;

        let prev, next, depth, parent, anchor;
        if (avantPremier) {
            depth = 0; parent = '0'; anchor = null;
            next = liste[0];
        } else {
            prev = liste[idx]; next = liste[idx + 1];
            const dragDepth = Math.round((e.clientX - startX) / INDENT);
            const maxDepth = prev.depth + 1;
            const minDepth = next ? next.depth : 0;
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
        const y = p.avantPremier ? r.top : r.bottom;
        indic.style.display = 'block';
        indic.style.top = (y - 1) + 'px';
        indic.style.left = (r.left + off) + 'px';
        indic.style.width = Math.max(40, r.right - r.left - off - 12) + 'px';
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
