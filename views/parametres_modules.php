<?php /** @var array $actifs */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<p class="muted small mb-16">Active ou désactive les fonctions dont l'association a besoin : les fonctions
désactivées disparaissent du menu, sans perte de données. Les réactiver restitue l'accès tel quel.</p>

<div class="card">
    <div class="module-row module-locked">
        <label class="regle-toggle" title="Toujours actif">
            <input type="checkbox" checked disabled class="regle-actif-cb">
            <span class="regle-toggle-pill"></span>
        </label>
        <div>
            <strong><?= e(MODULE_COEUR['label']) ?></strong>
            <span class="badge muted-badge"><?= icon('lock') ?> toujours actif</span>
            <p class="muted small mb-0"><?= e(MODULE_COEUR['description']) ?></p>
        </div>
    </div>

    <?php foreach (MODULES as $id => $def):
        $actif  = in_array($id, $actifs, true);
        $manque = array_diff($def['requires'], $actifs);
        $bloque = !$actif && $manque !== [];
    ?>
    <div class="module-row">
        <form method="post" action="?p=parametres_modules">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="module" value="<?= e($id) ?>">
            <label class="regle-toggle" title="<?= $actif ? 'Désactiver' : ($bloque ? 'Active d\'abord la comptabilité' : 'Activer') ?>">
                <input type="checkbox" name="actif" value="1" <?= $actif ? 'checked' : '' ?> <?= $bloque ? 'disabled' : '' ?>
                       class="regle-actif-cb" onchange="this.closest('form').submit()">
                <span class="regle-toggle-pill"></span>
            </label>
        </form>
        <div>
            <strong><?= e($def['label']) ?></strong>
            <?php if ($bloque): ?><span class="badge muted-badge">nécessite Comptabilité</span><?php endif; ?>
            <p class="muted small mb-0"><?= e($def['description']) ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
