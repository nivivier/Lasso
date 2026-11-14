<?php /** @var ?string $err */ ?>
<?php // Seconde étape de la connexion (route_connexion_code()). Un seul champ
      // pour les deux sortes de code : six chiffres = l'application, le reste
      // est lu comme un code de secours. ?>
<div class="card auth">
    <h1>Code de vérification</h1>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="?p=connexion_code" class="form auth-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Code de l'application
            <input type="text" name="code" required autofocus autocomplete="one-time-code"
                   inputmode="numeric" maxlength="20" placeholder="123 456" spellcheck="false">
        </label>
        <button type="submit">Valider</button>
    </form>
    <p class="muted small auth-lien">Sans le téléphone, saisissez l'un de vos codes de secours dans le même champ.</p>
    <p class="muted small auth-lien"><a href="?p=connexion">Recommencer la connexion</a></p>
</div>
