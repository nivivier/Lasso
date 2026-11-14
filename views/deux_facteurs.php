<?php
/** @var array $u */ /** @var ?array $codes */ /** @var ?string $secret */ /** @var ?string $uri */ /** @var ?string $err */
// Deux états, une page (route_deux_facteurs()) :
//  - préparation : la clé à recopier dans l'application, puis le premier code
//    qui confirme qu'elle y est bien ;
//  - les codes de secours qu'on vient de tirer, montrés UNE fois.
?>
<div class="page-head"><h1>Validation en deux étapes</h1></div>

<?php if (is_array($codes)): ?>
<div class="card">
    <h2 class="mt-0">Codes de secours</h2>
    <p class="warn">Notez-les maintenant : ils ne seront plus jamais affichés.</p>
    <p class="muted small">Chacun ouvre une seule connexion quand le téléphone n'est pas là.
        Rangez-les hors de l'ordinateur (papier, gestionnaire de mots de passe).</p>
    <ol class="codes-secours">
        <?php foreach ($codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?>
    </ol>
    <p><a class="btn" href="?p=mon_compte"><?= icon('check') ?> J'ai noté mes codes</a></p>
</div>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<div class="card">
    <h2 class="mt-0">1. Ajouter le compte dans l'application</h2>
    <p class="muted small">Dans Google Authenticator, Aegis, 1Password ou une autre application
        d'authentification, scannez ce QR code :</p>
    <?php // SVG construit par totp_qr_svg() à partir de la seule matrice : aucune
          // donnée saisie n'y entre, il ne passe donc pas par e(). ?>
    <?= totp_qr_svg((string) $uri) ?>
    <p class="muted small">Ou saisissez cette clé (type « basé sur l'heure ») :</p>
    <p class="cle-totp"><code><?= e(totp_secret_affiche((string) $secret)) ?></code></p>
    <p class="muted small">Sur le téléphone même, ce lien l'ajoute directement :
        <a href="<?= e((string) $uri) ?>">ouvrir dans l'application</a></p>
</div>
<div class="card">
    <h2 class="mt-0">2. Confirmer avec un premier code</h2>
    <form method="post" action="?p=deux_facteurs_activer" class="inline-edit">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="text" name="code" class="grow" required autocomplete="one-time-code" inputmode="numeric"
               maxlength="7" placeholder="Code à 6 chiffres affiché par l'application" aria-label="Code à 6 chiffres"
               spellcheck="false">
        <button type="submit"><?= icon('check') ?> Activer</button>
    </form>
</div>
<p><a href="?p=mon_compte">Annuler</a></p>
<?php endif; ?>
