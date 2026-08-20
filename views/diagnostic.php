<?php
/** @var bool $gitDispo */ /** @var bool $dlDispo */ /** @var bool $zipDispo */
/** @var bool $targzDispo */ /** @var bool $appWritable */ /** @var bool $archivePossible */
/** @var int $seuilClient */ /** @var array $volumes */ /** @var bool $enregistre */
$oui = fn(bool $b) => $b
    ? '<span class="badge ok-badge">disponible</span>'
    : '<span class="badge warn-badge">non</span>';
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($enregistre): ?><p class="ok flash">Seuil enregistré.</p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Mise à jour automatique</h2>
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

<div class="card mt-22">
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

<div class="card mt-22">
    <h2 class="mt-0">Recherche dans les listes <?= info_tip(
        "En dessous du seuil, toute la liste est envoyée au navigateur : la recherche et le "
        . "changement de page sont instantanés, sans aller-retour. Au-dessus, la liste est paginée "
        . "par le serveur et la recherche part sur Entrée."
    ) ?></h2>
    <p class="muted small">
        Nombre de lignes en dessous duquel une liste est filtrée dans le navigateur.
        Au-delà, elle est paginée et recherchée côté serveur.
    </p>

    <?php if ($volumes): ?>
    <table class="list mb-16">
        <thead><tr><th>Liste</th><th class="num">Lignes</th><th>Mode actuel</th></tr></thead>
        <tbody>
        <?php foreach ($volumes as $nom => $n): ?>
            <tr>
                <td><?= e($nom) ?></td>
                <td class="num"><?= number_format($n, 0, ',', ' ') ?></td>
                <td><?= $n <= $seuilClient
                        ? '<span class="badge ok-badge">navigateur</span>'
                        : '<span class="badge">serveur</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <form method="post" action="?p=diagnostic" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Seuil (lignes)
            <input type="number" name="pagination_seuil_client" min="0" max="<?= PAGINATION_SEUIL_MAX ?>"
                   step="100" value="<?= (int) $seuilClient ?>" style="max-width:160px">
        </label>
        <p class="muted small">
            <strong>0</strong> force toutes les listes côté serveur. Maximum <?= number_format(PAGINATION_SEUIL_MAX, 0, ',', ' ') ?>.
            Mesuré sur les 2 965 structures en mode navigateur : 207 Ko transférés,
            268 ms de chargement, 21 ms par frappe — mais 89 600 éléments gardés en
            mémoire, ce qui peut peser sur un appareil modeste.
        </p>
        <div class="form-actions"><button type="submit"><?= icon('save') ?> Enregistrer</button></div>
    </form>
</div>
