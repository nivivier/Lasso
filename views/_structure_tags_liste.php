<?php
// Le contenu de la liste d'étiquettes d'une structure : les pastilles, le « + »
// qui ouvre le champ d'ajout, et le « Aucun tag. » quand il n'y en a pas.
//
// Rendu seul lors d'un ajout parti en arrière-plan : la route renvoie ce bloc
// entier et le script remplace celui de la page (data-remplace, docs/UI.md § 5).
// Une étiquette se glisse entre ses voisines ET avant le « + » — insérer une
// ligne ne suffirait pas.
//
// Attendu de l'appelant : $tags, $sid, $peutEcrireBooking, $depuisQs.
$depuisQs = $depuisQs ?? '';
?>
        <?php foreach ($tags as $t): ?>
            <span class="badge"<?= badge_style_html((string) ($t['couleur'] ?? '')) ?>><?= e($t['nom']) ?>
                <?php if ($peutEcrireBooking): ?>
                <form method="post" action="?p=structure_tag_retirer<?= $depuisQs ?>" class="d-inline" data-confirm="Retirer ce tag ?">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn-tag-x" aria-label="Retirer">×</button>
                </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        <?php if (!$tags): ?><span class="muted small">Aucun tag.</span><?php endif; ?>
        <?php if ($peutEcrireBooking): ?>
        <button type="button" class="badge tag-ajouter-btn" data-show="tag-ajouter-form" data-focus="input[name=nom]" title="Ajouter un tag" aria-label="Ajouter un tag">+</button>
        <?php endif; ?>
    
