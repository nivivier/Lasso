<?php
// Rend une liste d'entrées d'historique typé. L'appelant définit $histoEntrees
// (nom distinctif pour ne pas heurter d'autres variables de la vue via
// extract()) — chaque entrée : type, contenu, cree_le, u_prenom, u_nom.
// Icône + libellé par type via HISTORIQUE_TYPES (lib/booking.php).
$histoEntrees = $histoEntrees ?? [];
?>
<ul class="hist-list">
    <?php foreach ($histoEntrees as $he): ?>
        <?php $hi = HISTORIQUE_TYPES[$he['type']] ?? ['Entrée', 'message-square']; ?>
        <li class="hist-item hist-<?= e((string) $he['type']) ?>">
            <span class="hist-ico" title="<?= e($hi[0]) ?>" aria-hidden="true"><?= icon($hi[1]) ?></span>
            <div>
                <div class="muted small">
                    <?= e($he['cree_le'] ? date('d.m.Y H:i', strtotime((string) $he['cree_le'])) : '') ?>
                    <?php $auteur = trim((string) ($he['u_prenom'] ?? '') . ' ' . (string) ($he['u_nom'] ?? '')); ?>
                    <?php if ($auteur !== ''): ?> · <?= e($auteur) ?><?php endif; ?>
                    · <?= e($hi[0]) ?>
                    <?php if (($he['source_label'] ?? '') !== ''): ?> <span class="badge muted-badge"><?= e((string) $he['source_label']) ?></span><?php endif; ?>
                </div>
                <?php if ((string) $he['contenu'] !== ''): ?><div class="small"><?= nl2br(e((string) $he['contenu'])) ?></div><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
