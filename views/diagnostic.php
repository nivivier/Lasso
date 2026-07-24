<?php
/** @var bool $gitDispo */ /** @var bool $dlDispo */ /** @var bool $zipDispo */
/** @var bool $targzDispo */ /** @var bool $appWritable */ /** @var bool $archivePossible */
$oui = fn(bool $b) => $b
    ? '<span class="badge ok-badge">disponible</span>'
    : '<span class="badge warn-badge">non</span>';
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<div class="card">
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
