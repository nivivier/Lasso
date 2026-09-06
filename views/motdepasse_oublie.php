<?php /** @var bool $envoye */ ?>
<div class="card auth">
    <h1>Mot de passe oublié</h1>
    <?php if ($envoye): ?>
        <?php // Réponse volontairement identique que le compte existe ou non :
              // ce formulaire ne doit pas révéler quelles adresses ont un compte. ?>
        <p class="ok">Si un compte correspond à cette adresse, un lien de réinitialisation vient d'y être envoyé.</p>
        <p class="muted small">Le lien est valable <?= (int) round(RESET_TTL / 60) ?> minutes et ne fonctionne qu'une fois. Pensez à regarder les indésirables.</p>
        <p><a href="?p=login">Retour à la connexion</a></p>
    <?php else: ?>
        <p class="muted small">Indiquez l'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
        <form method="post" action="?p=motdepasse_oublie" class="form auth-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>E-mail
                <input type="email" name="email" required autofocus autocomplete="email">
            </label>
            <button type="submit">Envoyer le lien</button>
        </form>
        <p class="muted small"><a href="?p=login">Retour à la connexion</a></p>
    <?php endif; ?>
</div>
