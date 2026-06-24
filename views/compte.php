<?php /** @var array $u */ /** @var ?string $err */ /** @var ?string $saved */ ?>
<div class="page-head"><h1>Mon compte</h1></div>
<?php if ($saved): ?><p class="ok flash">Compte mis à jour.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <form method="post" action="?p=compte">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grid2">
            <label>Prénom
                <input type="text" name="prenom" value="<?= e($u['prenom'] ?? '') ?>" autocomplete="given-name">
            </label>
            <label>Nom
                <input type="text" name="nom" value="<?= e($u['nom'] ?? '') ?>" autocomplete="family-name">
            </label>
        </div>

        <label>E-mail du compte
            <input type="email" name="email" value="<?= e($u['email']) ?>" required>
        </label>

        <h3 class="sub">Changer le mot de passe (optionnel)</h3>
        <div class="grid2">
            <label>Nouveau mot de passe
                <input type="password" name="nouveau_mot_de_passe" minlength="8" autocomplete="new-password" placeholder="laisser vide pour ne pas changer">
            </label>
            <label>Confirmer le nouveau mot de passe
                <input type="password" name="confirmer" autocomplete="new-password">
            </label>
        </div>

        <h3 class="sub">Confirmation</h3>
        <label>Mot de passe actuel (requis pour valider)
            <input type="password" name="mot_de_passe_actuel" required autocomplete="current-password">
        </label>

        <div class="form-actions">
            <button type="submit">Enregistrer</button>
        </div>
    </form>
</div>
