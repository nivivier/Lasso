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
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <?php if ($lignes): ?>
        <label class="search-label">
            <input type="search" id="spectacles-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher">
        </label>
        <?php endif; ?>
        <div class="head-actions">
            <?php if ($lignes): ?>
            <button type="button" class="btn ghost icon-only export-copy" onclick="event.stopPropagation()"
                                data-url="<?= e(evenements_export_url('evenements_json', $token)) ?>"
                                title="Copier le lien de synchronisation JSON" aria-label="Copier le lien de synchronisation JSON"><?= icon('file-braces') ?></button>
            <button type="button" class="btn ghost icon-only export-copy" onclick="event.stopPropagation()"
                                data-url="<?= e(evenements_export_url('evenements_ical', $token)) ?>"
                                title="Copier le lien de synchronisation iCal" aria-label="Copier le lien de synchronisation iCal"><?= icon('calendar-sync') ?></button>
            <?php endif; ?>
            <button type="button" class="btn" data-show="spectacle-add"><?= icon('plus') ?><span class="lbl"> Nouveau <?= e($termeSingulier) ?></span></button>
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
        <input name="nom" placeholder="ex. Nom de l'artiste ou du spectacle" required class="grow" aria-label="Nom du spectacle">
        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
        <button type="button" class="btn ghost btn-sm" data-hide="spectacle-add"><?= icon('x') ?> Annuler</button>
    </form>
<?php else: ?>
<div class="form table-scroll" id="spectacles-card">
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
                            <input name="nom" value="<?= e($s['nom']) ?>" class="grow plan-libelle" required aria-label="Nom du spectacle">
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
                        <input name="nom" placeholder="ex. Nom de l'artiste ou du spectacle" required class="grow" aria-label="Nom du spectacle">
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
</div></div>

<script>
(function () {
    lassoPlanArbre({
        containerSelector: '#spectacles-card',
        rowsSelector: '.spectacles-table .plan-row',
        scrollKey: 'spectaclesScroll',
        formAction: '?p=spectacles',
    });

    document.querySelectorAll('.export-copy').forEach(btn => {
        const original = btn.innerHTML;
        btn.addEventListener('click', () => {
            navigator.clipboard.writeText(btn.dataset.url).then(() => {
                btn.innerHTML = <?= json_encode(icon('check'), JSON_UNESCAPED_SLASHES) ?>;
                setTimeout(() => { btn.innerHTML = original; }, 1500);
            });
        });
    });

    // Recherche instantanée (insensible à la casse et aux accents).
    const search = document.getElementById('spectacles-search');
    const rows   = Array.from(document.querySelectorAll('.spectacles-table tbody tr'));
    if (search) {
        const apply = () => {
            const q = lassoNorm(search.value.trim());
            rows.forEach(r => {
                r.style.display = (q === '' || lassoNorm(r.textContent).includes(q)) ? '' : 'none';
            });
        };
        search.addEventListener('input', apply);
    }
})();
</script>
