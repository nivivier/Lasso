<?php
/** @var string $canal */ /** @var string $locale */ /** @var ?string $distante */
/** @var ?string $shaLocal */ /** @var ?string $shaDist */ /** @var string $etat */
/** @var bool $execDispo */ /** @var bool $gitDispo */
/** @var bool $dlDispo */ /** @var bool $zipDispo */ /** @var bool $targzDispo */
/** @var bool $appWritable */ /** @var bool $archivePossible */
/** @var bool $downgrade */ /** @var ?array $resultat */ /** @var bool $webActive */
$oui = fn(bool $b) => $b
    ? '<span class="badge ok-badge">disponible</span>'
    : '<span class="badge warn-badge">non</span>';
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?php if ($resultat !== null): ?>
    <?php if ($resultat['ok']): ?>
        <p class="ok flash">Mise à jour effectuée : <?= e($resultat['ancienne']) ?>
            <?= !empty($resultat['sha_avant']) ? '(' . e($resultat['sha_avant']) . ')' : '' ?>
            → <strong><?= e($resultat['nouvelle']) ?>
            <?= !empty($resultat['sha_apres']) ? '(' . e($resultat['sha_apres']) . ')' : '' ?></strong>.</p>
    <?php else: ?>
        <p class="err">Échec de la mise à jour : <?= e($resultat['message']) ?></p>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">Version installée</h2>
    <dl class="info-grid">
        <div><dt>Version</dt><dd><strong><?= e($locale) ?></strong><?= $shaLocal ? ' <span class="muted small">(' . e($shaLocal) . ')</span>' : '' ?></dd></div>
        <div><dt>Canal suivi</dt><dd><?= $canal === 'stable' ? 'Stable' : 'Test' ?></dd></div>
        <div><dt>Version disponible</dt><dd>
            <?php if ($distante === null): ?>
                <span class="muted">indéterminée (pas de réseau ?)</span>
            <?php else: ?>
                <?= e($distante) ?><?= $shaDist ? ' <span class="muted small">(' . e($shaDist) . ')</span>' : '' ?>
            <?php endif; ?>
        </dd></div>
        <div><dt>État</dt><dd>
            <?php switch ($etat):
                case 'a_jour': ?><span class="badge ok-badge">À jour</span><?php break; ?>
            <?php case 'retard': ?><span class="badge warn-badge">Mise à jour disponible</span><?php break; ?>
            <?php case 'avance': ?><span class="badge warn-badge">Installée plus récente que ce canal</span><?php break; ?>
            <?php case 'diverge': ?><span class="badge warn-badge">Historique divergent</span><?php break; ?>
            <?php default: ?><span class="badge warn-badge">Vérification impossible</span><?php endswitch; ?>
        </dd></div>
    </dl>
    <p class="muted small"><a href="https://github.com/nivivier/Lasso/blob/<?= $canal === 'stable' ? 'stable' : 'main' ?>/CHANGELOG.md" target="_blank" rel="noopener">Voir le journal des versions ↗</a></p>

    <?php if (!$webActive): ?>
        <p class="muted small">La mise à jour en un clic est désactivée sur ce serveur (<code>ALLOW_WEB_UPDATE</code>).</p>
    <?php elseif (!$archivePossible): ?>
        <p class="muted small">Ce serveur ne supporte pas la mise à jour automatique (voir diagnostic ci-dessous) — mise à jour par SSH / <code>deploy.sh</code>.</p>
    <?php else: ?>
        <?php
        $confirm = $downgrade
            ? "Attention : la version du canal $canal (" . $distante . ") est ANTÉRIEURE à la version installée ($locale). Un retour en arrière peut être incompatible avec la base déjà migrée. Continuer ?"
            : 'Télécharger et installer la dernière version du canal ' . $canal . ' ?';
        ?>
        <form method="post" action="?p=maj" class="mt-18">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="maj_go" value="1">
            <button type="submit" onclick="return confirm(<?= e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                <?= icon('download') ?> <?= $etat === 'a_jour' ? 'Réinstaller la dernière version' : ($downgrade ? 'Installer la version du canal (retour en arrière)' : 'Mettre à jour maintenant') ?>
            </button>
            <?php if ($downgrade): ?><span class="muted small">⚠️ Cette installation serait un retour en arrière.</span><?php endif; ?>
        </form>
        <p class="muted small mt-18">Une sauvegarde de la base est faite automatiquement avant chaque mise à jour. Vos données et votre configuration sont préservées.</p>
    <?php endif; ?>
</div>

<div class="card form mt-22">
    <h2 class="mt-0">Canal</h2>
    <p class="muted small">Le canal <strong>test</strong> reçoit toutes les nouveautés en premier ; le canal <strong>stable</strong> ne suit que les versions validées.</p>
    <form method="post" action="?p=maj">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Canal de mise à jour
            <select name="canal" onchange="this.form.submit()">
                <option value="test" <?= $canal === 'test' ? 'selected' : '' ?>>Test (nouveautés en avant-première)</option>
                <option value="stable" <?= $canal === 'stable' ? 'selected' : '' ?>>Stable (versions validées)</option>
            </select>
        </label>
        <noscript><div class="form-actions"><button type="submit"><?= icon('save') ?> Enregistrer</button></div></noscript>
    </form>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Diagnostic du serveur</h2>
    <p class="muted small">Détermine comment la mise à jour automatique pourra s'effectuer.</p>
    <dl class="info-grid">
        <div><dt>Fonction <code>exec()</code> + <code>git</code> <span class="muted small">(MAJ par git)</span></dt><dd><?= $oui($gitDispo) ?></dd></div>
        <div><dt>Téléchargement <span class="muted small">(cURL / allow_url_fopen)</span></dt><dd><?= $oui($dlDispo) ?></dd></div>
        <div><dt>Décompression <span class="muted small">(ZipArchive / PharData)</span></dt><dd><?= $oui($zipDispo || $targzDispo) ?></dd></div>
        <div><dt>Écriture dans le dossier de l'app</dt><dd><?= $oui($appWritable) ?></dd></div>
    </dl>
    <p class="muted small">
        <?php if ($gitDispo): ?>
            ✓ Mise à jour possible par <code>git</code> (méthode la plus robuste).
        <?php elseif ($archivePossible): ?>
            ✓ Mise à jour possible par <strong>téléchargement d'archive</strong> (git indisponible).
        <?php else: ?>
            ✗ Mise à jour automatique impossible sur ce serveur
            <?= !$dlDispo ? '(téléchargement bloqué)' : (!($zipDispo || $targzDispo) ? '(pas de décompression)' : '(dossier non inscriptible par PHP)') ?>
            — la mise à jour reste manuelle (SSH / <code>deploy.sh</code>).
        <?php endif; ?>
    </p>
    <p class="muted small">Astuce : pour désactiver la mise à jour en un clic, ajoutez <code>define('ALLOW_WEB_UPDATE', false);</code> dans <code>lib/config.local.php</code>.</p>
</div>
