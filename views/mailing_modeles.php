<?php /** @var array $modeles */ /** @var bool $saved */ ?>
<?php require __DIR__ . '/_mailing_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Modèles mis à jour.</p><?php endif; ?>

<div class="card form">
    <div class="section-head mt-0">
        <h2 class="mt-0">Modèles de message</h2>
        <button type="button" class="btn btn-sm ml-auto" data-show="modele-add"><?= icon('plus') ?> Nouveau modèle</button>
    </div>
    <p class="muted small">Sujets et corps réutilisables, chargeables en un clic dans « Nouvelle campagne ».
        Variables : <code>{{prenom}}</code> (contact), <code>{{nom_structure}}</code>.</p>

    <div id="modele-add" hidden class="fieldset-groupe mb-16">
        <form method="post" action="?p=mailing_modeles">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="modele_save">
            <label>Nom du modèle <input name="nom" required placeholder="ex. Relance festivals"></label>
            <label>Sujet <input name="sujet"></label>
            <label>Corps <textarea name="corps" rows="6" placeholder="Bonjour {{prenom}},&#10;&#10;…"></textarea></label>
            <div class="form-actions">
                <button type="submit"><?= icon('save') ?> Enregistrer</button>
                <button type="button" class="btn ghost" data-hide="modele-add"><?= icon('x') ?> Annuler</button>
            </div>
        </form>
    </div>

    <?php if (!$modeles): ?>
        <p class="muted">Aucun modèle pour l'instant.</p>
    <?php else: ?>
        <?php foreach ($modeles as $m): ?>
        <div class="fieldset-groupe mb-16">
            <div class="card-head-row">
                <strong><?= e($m['nom']) ?></strong>
                <form method="post" action="?p=mailing_modeles" class="d-inline ml-auto" onsubmit="return confirm('Supprimer le modèle « <?= e($m['nom']) ?> » ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="modele_delete">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
            </div>
            <form method="post" action="?p=mailing_modeles">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="modele_save">
                <input type="hidden" name="nom" value="<?= e($m['nom']) ?>">
                <label>Sujet <input name="sujet" value="<?= e($m['sujet']) ?>"></label>
                <label>Corps <textarea name="corps" rows="5"><?= e($m['corps']) ?></textarea></label>
                <div class="form-actions">
                    <button type="submit" class="btn ghost btn-sm"><?= icon('save') ?> Enregistrer les modifications</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
