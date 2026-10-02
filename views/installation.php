<?php /** @var ?string $err */ /** @var string $email */ ?>
<div class="card auth">
    <h1>Installation</h1>
    <p class="muted">Créez le premier compte administrateur. C'est la seule fois où cet écran apparaît.</p>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="?p=setup" class="form auth-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if (!empty($key)): ?><input type="hidden" name="key" value="<?= e($key) ?>"><?php endif; ?>
        <label>E-mail
            <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
        </label>
        <label>Mot de passe (<?= PASSWORD_MIN ?> caractères min.)
            <input type="password" name="mot_de_passe" required minlength="<?= PASSWORD_MIN ?>">
        </label>
        <button type="submit">Créer le compte</button>
    </form>
</div>
