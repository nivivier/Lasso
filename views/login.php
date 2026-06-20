<?php /** @var ?string $err */ /** @var ?string $info */ /** @var string $email */ ?>
<div class="card auth">
    <h1>Connexion</h1>
    <?php if (!empty($info)): ?><p class="ok"><?= e($info) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="?p=login" class="form auth-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>E-mail
            <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
        </label>
        <label>Mot de passe
            <input type="password" name="mot_de_passe" required>
        </label>
        <button type="submit">Se connecter</button>
    </form>
</div>
