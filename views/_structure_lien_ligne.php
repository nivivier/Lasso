<?php
// UNE structure liée, telle qu'elle apparaît sur la fiche d'une autre : son nom,
// son type, sa ville, et le bouton qui les délie.
//
// Sorti de la boucle pour être rendu AUSSI tout seul : quand on lie une
// structure, la route renvoie cette ligne-là et le script l'insère, sans
// recharger la page (docs/UI.md § 5).
//
// Attendu de l'appelant : $l (la structure liée, avec son sens), $sid, $depuisQs.
$depuisQs = $depuisQs ?? '';
?>
            <div class="linked-add">
                <span>
                    <strong><?= icon($l['sens'] === 'organise' ? 'blocks' : 'building') ?> <a href="<?= url_avec_retour('?p=structure&id=' . (int) $l['id'], 'structure', $sid) ?>"><?= e($l['nom']) ?></a></strong>
                    <div class="muted small"><?= e((string) $l['type']) ?>
                    <?php if ($l['ville']): ?> · <?= e($l['ville']) ?><?php endif; ?></div>
                </span>
                <form method="post" action="?p=structure_lieu_delier<?= $depuisQs ?>" class="edit-only" data-confirm="Délier ?">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="lieu_id" value="<?= (int) $l['id'] ?>">
                    <input type="hidden" name="sens" value="<?= e((string) $l['sens']) ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Délier" aria-label="Délier"><?= icon('unlink') ?></button>
                </form>
            </div>
