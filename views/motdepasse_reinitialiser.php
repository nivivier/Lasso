<?php /** @var string $jeton */ /** @var ?string $err */ /** @var bool $invalide */ ?>
<div class="card auth">
    <h1>Nouveau mot de passe</h1>
    <?php if ($invalide): ?>
        <?php // Lien expiré, déjà utilisé, ou inventé : un seul message pour les
              // trois, il n'y a rien à apprendre de la différence. ?>
        <p class="err">Ce lien n'est plus valable. Il expire au bout de <?= (int) round(RESET_TTL / 60) ?> minutes et ne sert qu'une fois.</p>
        <p><a href="?p=motdepasse_oublie">Demander un nouveau lien</a></p>
    <?php else: ?>
        <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
        <form method="post" action="?p=motdepasse_reinit" class="form auth-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
            <label>Nouveau mot de passe
                <input type="password" name="mot_de_passe" required autofocus autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>">
            </label>
            <label>Confirmer
                <input type="password" name="confirmer" required autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>">
            </label>
            <p class="muted small"><?= PASSWORD_MIN ?> caractères au minimum.</p>
            <button type="submit">Enregistrer le nouveau mot de passe</button>
        </form>
    <?php endif; ?>
</div>
