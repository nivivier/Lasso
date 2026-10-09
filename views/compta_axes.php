<?php
/** @var array $axes */ /** @var bool $saved */
$peutEcrireAxes = peut_ecrire('analytique');
?>
<?php // Le bandeau du module, comme ?p=compta_analyse et ?p=compta_analyse_axe :
      // cet écran vit SOUS l'onglet « Analyse » (nav_groupes() le compte déjà
      // parmi ses routes), et le quitter des yeux donnait l'impression de
      // sortir de la comptabilité pour un réglage perdu ailleurs. Le <h1> de la
      // page nomme l'écran, pas le module — il ne porte donc pas
      // .page-head-titre-module et reste sur téléphone. ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour('?p=compta_analyse', 'Analyse') ?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Axes analytiques</h1>
    </div>
    <?php if ($peutEcrireAxes): ?>
    <div class="head-actions">
        <button type="button" id="btn-new-axe" class="btn"><?= icon('plus') ?><span class="lbl"> Ajouter un axe</span></button>
    </div>
    <?php endif; ?>
</div>
<?php if ($saved): ?><p class="ok flash">Axe enregistré.</p><?php endif; ?>

    <?php if ($peutEcrireAxes): ?>
    <?php // Exemplaire unique du formulaire de repositionnement : le script y
          // écrit l'ordre complet au dépôt et l'envoie (docs/UI.md § 4). ?>
    <form method="post" action="?p=compta_axes" id="reorder-form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="reorder">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="order" value="">
    </form>
    <?php endif; ?>

    <div class="table-scroll">
    <?php // Mêmes lignes que les règles de lettrage et les lignes du décompte :
          // poignée à gauche, la ligne se lit, le crayon l'ouvre (docs/UI.md
          // § 2 et § 4). L'ordre des axes est celui de tous les menus qui les
          // proposent — le glisser-déposer range donc une liste qu'on relit
          // ailleurs, pas seulement cet écran. ?>
    <table class="list mt-10 plan-table axes-table">
        <thead><tr><th class="col-icon"></th><th>Axe analytique</th><th></th></tr></thead>
        <tbody>
        <?php if (!$axes): ?>
            <tr><td colspan="3" class="muted small">Aucun axe analytique. Exemples : Label, Tour, Stages, Local.</td></tr>
        <?php endif; ?>
        <?php foreach ($axes as $a): ?>
            <?php $aid = (int) $a['id']; ?>
            <tr class="plan-row <?= $a['actif'] ? '' : 'plan-archive' ?>" data-id="<?= $aid ?>">
                <td class="td-toggle">
                    <?php if ($peutEcrireAxes): ?>
                    <span class="plan-grip" draggable="true" title="Glisser pour changer l'ordre" aria-hidden="true"><?= icon('grip') ?></span>
                    <?php // data-ajax : l'interrupteur n'engage que sa ligne, l'envoi
                          // part donc en arrière-plan (docs/UI.md § 14). Il ne paraît
                          // qu'une ligne ouverte, comme celui d'une règle : au repos,
                          // c'est l'atténuation de la ligne qui dit qu'un axe est
                          // éteint. ?>
                    <form method="post" action="?p=compta_axes" data-ajax="Axe mis à jour." class="cell-edition">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="toggle_actif">
                        <input type="hidden" name="id" value="<?= $aid ?>">
                        <label class="regle-toggle" title="<?= $a['actif'] ? 'Désactiver' : 'Activer' ?>">
                            <input type="checkbox" name="actif" value="1" <?= $a['actif'] ? 'checked' : '' ?>
                                   class="regle-actif-cb" data-submit-on-change
                                   aria-label="<?= $a['actif'] ? 'Désactiver' : 'Activer' ?> cet axe">
                            <span class="regle-toggle-pill"></span>
                        </label>
                    </form>
                    <?php else: ?>
                    <span class="badge <?= $a['actif'] ? 'ok-badge' : 'muted-badge' ?>"><?= $a['actif'] ? 'Actif' : 'Inactif' ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="inline-edit">
                        <span class="plan-nom">
                            <strong><?= e($a['libelle']) ?></strong>
                            <?php if ($a['code']): ?><span class="muted small"> · <?= e($a['code']) ?></span><?php endif; ?>
                            <?php // Le badge est rendu quoi qu'il arrive et c'est le CSS
                                  // qui le montre ou le cache d'après la case : il suit
                                  // alors une bascule partie en arrière-plan, sans rien
                                  // recharger. ?>
                            <?php if ($peutEcrireAxes || !$a['actif']): ?>
                            <span class="badge muted-badge axe-badge-inactif">inactif</span>
                            <?php endif; ?>
                        </span>
                        <?php if ($peutEcrireAxes): ?>
                        <form method="post" action="?p=compta_axes" class="plan-edit" id="axe-edit-<?= $aid ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="section" value="update">
                            <input type="hidden" name="id" value="<?= $aid ?>">
                            <input name="libelle" value="<?= e($a['libelle']) ?>" class="grow" required placeholder="Libellé" aria-label="Libellé de l'axe">
                            <input name="code" value="<?= e($a['code']) ?>" placeholder="Code court" class="w-iban input-code" title="Code court optionnel (ex. LAB, TOU, STA)" aria-label="Code court">
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="actions nowrap">
                    <?php if ($peutEcrireAxes): ?>
                    <?php // Repli sans glisser-déposer (et sur téléphone, où il
                          // n'existe pas) : les deux flèches, rattachées au
                          // formulaire de la ligne par form=. formnovalidate parce
                          // qu'elles ne soumettent pas le libellé — le laisser
                          // valider bloquerait un déplacement sur un champ vidé. ?>
                    <button type="submit" form="axe-edit-<?= $aid ?>" name="section" value="move_up" formnovalidate
                            class="btn ghost btn-sm icon-only plan-fallback" title="Monter" aria-label="Monter"><?= icon('chevron-up') ?></button>
                    <button type="submit" form="axe-edit-<?= $aid ?>" name="section" value="move_down" formnovalidate
                            class="btn ghost btn-sm icon-only plan-fallback" title="Descendre" aria-label="Descendre"><?= icon('chevron-down') ?></button>
                    <?php // En édition, le crayon cède la place au trio : enregistrer
                          // (mis en évidence), supprimer (rouge) et annuler. La croix
                          // se pose exactement là où était le crayon, tout à droite. ?>
                    <button type="submit" form="axe-edit-<?= $aid ?>" name="section" value="update"
                            class="btn btn-sm cell-edition" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                    <button type="submit" form="axe-edit-<?= $aid ?>" name="section" value="delete" formnovalidate
                            class="btn danger btn-sm icon-only cell-edition plan-supprimer" title="Supprimer" aria-label="Supprimer cet axe"
                            data-confirm="Supprimer cet axe ? Les écritures associées ne seront pas supprimées."><?= icon('trash') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Modifier" aria-label="Modifier cet axe"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only plan-annuler-btn cell-edition" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="axe-add-row" hidden>
            <tr>
                <td colspan="2">
                    <form method="post" action="?p=compta_axes" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="create">
                        <input name="libelle" placeholder="ex. Label, Tour, Stages, Local" required class="grow" aria-label="Libellé de l'axe">
                        <input name="code" placeholder="Code court" class="w-iban input-code" title="Code court optionnel" aria-label="Code court">
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" id="cancel-new-axe"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div></div>

<?php // Toujours exécuté, même sans droit d'écriture : c'est « dnd-on » qui
      // bascule la liste en mode lecture — sans lui, les formulaires d'édition
      // resteraient dépliés sur chaque ligne. ?>
<script nonce="<?= e(csp_nonce()) ?>">
lassoOrdreListe({
    containerSelector: '.axes-table',
    rowsSelector: '.axes-table .plan-row',
    scrollKey: 'axesScroll',
    formAction: '?p=compta_axes',
});
(function () {
    // Le seul geste propre à cet écran : déplier la rangée d'ajout.
    document.getElementById('btn-new-axe')?.addEventListener('click', () => {
        const tfoot = document.getElementById('axe-add-row');
        tfoot.hidden = false;
        tfoot.querySelector('input[name="libelle"]')?.focus();
    });
    document.getElementById('cancel-new-axe')?.addEventListener('click', () => {
        document.getElementById('axe-add-row').hidden = true;
    });
})();
</script>
