<?php /** @var bool $saved */ /** @var array $lignes */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php $peutEcrireTags = peut_ecrire('booking'); ?>

<div class="section-head mt-0">
    <h2 class="mt-0">Étiquettes<?= info_tip("Étiquettes libres posées sur les structures (« À contacter en cas de tournée »,
    « Ne pas contacter »…), utilisées dans les filtres et le ciblage des mailings. Renommer une étiquette la met à jour partout ;
    la supprimer la retire des fiches qui la portent (les fiches elles-mêmes ne sont pas touchées).") ?></h2>
    <?php if ($peutEcrireTags): ?>
    <button type="button" class="btn btn-sm ml-auto" data-show="tag-add"><?= icon('plus') ?> Nouvelle étiquette</button>
    <?php endif; ?>
</div>
<div class="card form table-scroll" id="tags-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucune étiquette.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $t): $tid = (int) $t['id']; $nb = (int) $t['nb']; $couleur = (string) ($t['couleur'] ?? ''); ?>
        <tr class="tag-row" data-id="<?= $tid ?>">
            <td>
                <div class="inline-edit tag-view">
                    <span class="badge tag-apercu"<?= badge_style_html($couleur) ?>><?= e($t['nom']) ?></span>
                    <span class="muted small"><?= $nb > 0 ? $nb . ' structure' . ($nb > 1 ? 's' : '') : 'inutilisée' ?></span>
                </div>
                <?php if ($peutEcrireTags): ?>
                <form method="post" action="?p=parametres_tags" class="inline-edit tag-edit-form" hidden>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="edit">
                    <input type="hidden" name="id" value="<?= $tid ?>">
                    <input type="color" name="couleur" value="<?= e($couleur ?: '#2563eb') ?>" title="Couleur" class="tag-input-couleur">
                    <input name="nom" value="<?= e($t['nom']) ?>" class="grow tag-input-nom" required aria-label="Nom">
                    <button type="submit" class="btn" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                </form>
                <?php endif; ?>
            </td>
            <td class="actions nowrap">
                <?php if ($peutEcrireTags): ?>
                <button type="button" class="btn ghost btn-sm icon-only tag-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <button type="button" class="btn ghost btn-sm icon-only tag-cancel-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                <form method="post" action="?p=parametres_tags" class="d-inline tag-delete-form" hidden
                      data-confirm="<?= e($nb > 0
                          ? "Supprimer l'étiquette « " . $t['nom'] . " » ? Elle sera retirée de $nb structure(s)."
                          : "Supprimer l'étiquette « " . $t['nom'] . " » ?") ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $tid ?>">
                    <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
                <?php endif; ?>
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
                    <input type="color" name="couleur" value="#2563eb" title="Couleur">
                    <input name="nom" placeholder="ex. À relancer en septembre" required class="grow" aria-label="Nom">
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="tag-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Le crayon révèle les champs éditables (nom + couleur) et les actions
    // Enregistrer/Supprimer/Annuler ; Annuler restaure les valeurs d'origine.
    document.querySelectorAll('#tags-card .tag-row').forEach(function (row) {
        var editBtn = row.querySelector('.tag-edit-btn');
        var cancelBtn = row.querySelector('.tag-cancel-btn');
        var deleteForm = row.querySelector('.tag-delete-form');
        var view = row.querySelector('.tag-view');
        var form = row.querySelector('.tag-edit-form');
        var nomInput = form.querySelector('.tag-input-nom');
        var couleurInput = form.querySelector('.tag-input-couleur');
        var nomOrig = nomInput.value;
        var couleurOrig = couleurInput.value;
        editBtn.addEventListener('click', function () {
            view.hidden = true;
            form.hidden = false;
            editBtn.hidden = true;
            cancelBtn.hidden = false;
            deleteForm.hidden = false;
            nomInput.focus();
            nomInput.select();
        });
        cancelBtn.addEventListener('click', function () {
            nomInput.value = nomOrig;
            couleurInput.value = couleurOrig;
            view.hidden = false;
            form.hidden = true;
            editBtn.hidden = false;
            cancelBtn.hidden = true;
            deleteForm.hidden = true;
        });
    });
})();
</script>
