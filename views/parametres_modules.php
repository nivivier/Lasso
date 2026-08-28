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

    <?php
    // Ordre d'affichage : chaque module suivi de ceux qui en dépendent, décalés
    // d'un cran — la dépendance se lit alors sans avoir à ouvrir l'infobulle
    // (« Comptabilité analytique » sous « Comptabilité », « Envois groupés »
    // sous « Booking »). MODULES les déclare déjà dans cet ordre ; on le
    // reconstruit quand même, pour que l'écran ne dépende pas de l'ordre de
    // déclaration.
    $ordonnes = [];
    foreach (MODULES as $id => $def) {
        if ($def['requires'] === []) {
            $ordonnes[$id] = 0;
            foreach (MODULES as $sousId => $sousDef) {
                if (in_array($id, $sousDef['requires'], true)) {
                    $ordonnes[$sousId] = 1;
                }
            }
        }
    }
    foreach (MODULES as $id => $def) { $ordonnes[$id] ??= 0; }
    ?>
    <?php foreach ($ordonnes as $id => $niveau):
        $def    = MODULES[$id];
        $actif  = in_array($id, $actifs, true);
        $manque = array_diff($def['requires'], $actifs);
        $bloque = !$actif && $manque !== [];
        // Le nom du module manquant, et non « la comptabilité » en dur : il y a
        // désormais deux modules dépendants, de deux parents différents.
        $nomsManquants = implode(' et ', array_map(fn ($m) => MODULES[$m]['label'], $manque));
    ?>
    <div class="module-row<?= $niveau ? ' module-row-sous' : '' ?>">
        <form method="post" action="?p=parametres_modules">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="module" value="<?= e($id) ?>">
            <label class="regle-toggle" title="<?= $actif ? 'Désactiver' : ($bloque ? e('Activer d\'abord ' . $nomsManquants) : 'Activer') ?>">
                <input type="checkbox" name="actif" value="1" <?= $actif ? 'checked' : '' ?> <?= $bloque ? 'disabled' : '' ?>
                       class="regle-actif-cb" data-submit-on-change>
                <span class="regle-toggle-pill"></span>
            </label>
        </form>
        <div>
            <strong><?= e($def['label']) ?></strong>
            <?php if ($bloque): ?><span class="badge muted-badge">nécessite <?= e($nomsManquants) ?></span><?php endif; ?>
            <p class="muted small mb-0"><?= e($def['description']) ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
