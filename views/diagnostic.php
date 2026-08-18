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

<div class="card">
    <h2 class="mt-0">Environnement et performance</h2>
    <p class="muted small">À contrôler après chaque changement d'hébergement.</p>
    <dl class="info-grid">
        <div>
            <dt>Environnement détecté</dt>
            <dd>
                <span class="badge <?= $appEnv === 'prod' ? 'ok-badge' : 'warn-badge' ?>"><?= e($appEnv) ?></span>
                <?php if ($appEnv !== 'prod'): ?>
                    <span class="muted small">— les erreurs sont affichées et les e-mails seulement journalisés. Sur un serveur public, définissez <code>APP_ENV</code> sur <code>prod</code>.</span>
                <?php endif; ?>
            </dd>
        </div>
        <div><dt>Redirection HTTPS forcée</dt><dd><?= $oui($httpsForce) ?></dd></div>
        <div>
            <dt>Écran d'installation protégé <span class="muted small">(<code>SETUP_SECRET</code>)</span></dt>
            <dd><?= $oui($setupProtege) ?></dd>
        </div>
        <div><dt>Version de PHP</dt><dd><code><?= e($phpVersion) ?></code></dd></div>
        <div>
            <dt>OPcache <span class="muted small">(cache de bytecode)</span></dt>
            <dd>
                <?= $oui($opcache['actif']) ?>
                <span class="muted small">— <?= e($opcache['detail']) ?></span>
            </dd>
        </div>
    </dl>
    <?php if (!$opcache['actif']): ?>
    <p class="muted small">
        Sans OPcache, PHP réanalyse le code de l'application à chaque requête. C'est le
        réglage qui pèse le plus sur la vitesse ; s'il est disponible chez votre
        hébergeur, activez-le (souvent une case à cocher dans le panneau de
        configuration, ou <code>opcache.enable=1</code> dans le <code>php.ini</code>).
    </p>
    <?php endif; ?>
</div>
