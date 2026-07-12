<?php /** @var ?array $msg */ /** @var string $actionUrl */ ?>
<?php if ($msg): ?>
    <?php if ($msg['err']): ?>
        <p class="err flash"><?= e($msg['err']) ?></p>
    <?php elseif ($msg['ok'] !== null): ?>
        <p class="ok flash"><?= e($msg['ok']) ?></p>
    <?php elseif ($msg['preview'] !== null): ?>
        <?php $p = $msg['preview']; ?>
        <div class="card mt-22 import-confirm">
            <p class="mb-0"><strong>Simulation</strong> — rien n'a été enregistré.
                <?php if ((int) $p['nouvelles'] > 0): ?>
                    <?= (int) $p['nouvelles'] ?> écriture(s) seraient ajoutée(s)<?php if ((int) $p['doublons'] > 0): ?>, <?= (int) $p['doublons'] ?> doublon(s) ignoré(s)<?php endif; ?>.
                <?php else: ?>
                    Aucune écriture nouvelle — <?= (int) $p['doublons'] ?> doublon(s), déjà importées.
                <?php endif; ?>
                <?php if (!$p['compte']): ?>
                    Compte inconnu (IBAN <?= e($p['iban']) ?>) — il sera créé.
                <?php else: ?>
                    Compte reconnu : <strong><?= e($p['compte']['libelle']) ?></strong>.
                <?php endif; ?>
            </p>
            <?php if ((int) $p['nouvelles'] > 0): ?>
                <form method="post" action="<?= e($actionUrl) ?>" class="mt-16">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="depuis_session" value="1">
                    <?php if (!$p['compte']): ?>
                        <label>Nom du compte <input type="text" name="nom_compte" value="<?= e($p['nomSuggere']) ?>" required></label>
                    <?php endif; ?>
                    <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement ces écritures ?');"><?= icon('import') ?> Importer réellement</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
