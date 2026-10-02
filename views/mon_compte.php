<?php /** @var array $u */ /** @var ?string $err */ /** @var ?string $saved */ ?>
<div class="page-head"><h1>Mon compte</h1></div>
<?php if ($saved): ?><p class="ok flash">Compte mis à jour.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php // Une carte qui se LIT, et qu'on ouvre pour la modifier : même geste que
      // sur une fiche d'employé ou d'événement (.card-editable + le trio
      // crayon / enregistrer / annuler, carte_actions_html()). Le mot de passe
      // ne se lit évidemment pas — il ne se montre qu'en édition. ?>
<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Identité</h2>
        <?= carte_actions_html(['form' => 'compte-form']) ?>
    </div>

    <div class="card-disp">
        <table class="kv-table">
            <tr>
                <th>Prénom et nom</th>
                <td><?php $nom = trim(trim((string) ($u['prenom'] ?? '')) . ' ' . trim((string) ($u['nom'] ?? ''))); ?>
                    <?= $nom !== '' ? e($nom) : '<span class="muted">—</span>' ?></td>
            </tr>
            <tr><th>E-mail du compte</th><td><?= e($u['email']) ?></td></tr>
            <tr><th>Mot de passe</th><td class="muted">Modifiable ici, en confirmant l'actuel.</td></tr>
        </table>
    </div>

    <form method="post" action="?p=compte" id="compte-form" class="card-edit form" hidden>
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
    </form>
</div>
