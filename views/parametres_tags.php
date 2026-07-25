<?php /** @var bool $saved */ /** @var array $lignes */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>

<div class="section-head mt-0">
    <h2 class="mt-0">Étiquettes<?= info_tip("Étiquettes libres posées sur les structures (« À contacter en cas de tournée »,
    « Ne pas contacter »…), utilisées dans les filtres et le ciblage des mailings. Renommer une étiquette la met à jour partout ;
    la supprimer la retire des fiches qui la portent (les fiches elles-mêmes ne sont pas touchées).") ?></h2>
    <button type="button" class="btn btn-sm ml-auto" data-show="tag-add"><?= icon('plus') ?> Nouvelle étiquette</button>
</div>
<div class="card form table-scroll" id="tags-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucune étiquette.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $t): $tid = (int) $t['id']; $nb = (int) $t['nb']; ?>
        <tr class="plan-row" data-id="<?= $tid ?>">
            <td>
                <div class="inline-edit">
                    <span class="plan-puce" aria-hidden="true">•</span>
                    <span class="plan-nom"><?= e($t['nom']) ?></span>
                    <span class="muted small"><?= $nb > 0 ? $nb . ' structure' . ($nb > 1 ? 's' : '') : 'inutilisée' ?></span>
                    <form method="post" action="?p=parametres_tags" class="inline-edit plan-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <input name="nom" value="<?= e($t['nom']) ?>" class="grow plan-libelle" required aria-label="Nom">
                        <button type="submit" class="btn ghost btn-sm" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                </div>
            </td>
            <td class="actions nowrap">
                <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                <form method="post" action="?p=parametres_tags" class="d-inline"
                      onsubmit="return confirm(<?= e(json_encode($nb > 0
                          ? "Supprimer l'étiquette « " . $t['nom'] . " » ? Elle sera retirée de $nb structure(s)."
                          : "Supprimer l'étiquette « " . $t['nom'] . " » ?", JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $tid ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot id="tag-add" hidden>
        <tr>
            <td colspan="2">
                <form method="post" action="?p=parametres_tags" class="inline-edit">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="add">
                    <input name="nom" placeholder="ex. À relancer en septembre" required class="grow" aria-label="Nom">
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="tag-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<script>
(function () {
    // Renommage inline : le crayon révèle le champ (pas de glisser-déposer ici,
    // la liste est triée par nom).
    document.querySelectorAll('#tags-card .plan-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.plan-row');
            row.classList.toggle('editing');
            var inp = row.querySelector('.plan-libelle');
            if (row.classList.contains('editing') && inp) { inp.focus(); inp.select(); }
        });
    });
})();
</script>
