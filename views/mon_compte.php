<?php /** @var array $u */ /** @var ?string $err */ /** @var ?string $saved */ /** @var ?int $secours */ /** @var int $nbCodes */
$messagesOk = ['1' => 'Compte mis à jour.', 'deux_facteurs_off' => 'Validation en deux étapes désactivée.'];
?>
<div class="page-head"><h1>Mon compte</h1></div>
<?php if ($saved && isset($messagesOk[$saved])): ?><p class="ok flash"><?= e($messagesOk[$saved]) ?></p><?php endif; ?>
<?php if ($secours !== null): ?>
    <p class="warn">Connexion par un code de secours : il vous en reste <?= (int) $secours ?>.
        Si le téléphone est perdu, désactivez puis réactivez la validation pour en enregistrer un autre.</p>
<?php endif; ?>
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
                <td><?php $nom = utilisateur_nom($u); ?>
                    <?= $nom !== '' && $nom !== (string) $u['email'] ? e($nom) : '<span class="muted">—</span>' ?>
                    <?php if ((int) ($u['personne_id'] ?? 0) > 0): ?>
                        <span class="muted small">— depuis le carnet d'adresses</span>
                    <?php endif; ?></td>
            </tr>
            <tr><th>E-mail du compte</th><td><?= e($u['email']) ?></td></tr>
            <tr><th>Mot de passe</th><td class="muted">Modifiable ici, en confirmant l'actuel.</td></tr>
        </table>
    </div>

    <form method="post" action="?p=mon_compte" id="compte-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php // Rattaché à une personne, le nom vient d'elle : le modifier ici
              // ferait deux identités qui divergent. Il se change sur sa fiche. ?>
        <?php if ((int) ($u['personne_id'] ?? 0) === 0): ?>
        <div class="grid2">
            <label>Prénom
                <input type="text" name="prenom" value="<?= e($u['prenom'] ?? '') ?>" autocomplete="given-name">
            </label>
            <label>Nom
                <input type="text" name="nom" value="<?= e($u['nom'] ?? '') ?>" autocomplete="family-name">
            </label>
        </div>
        <?php endif; ?>

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

<?php // Validation en deux étapes : activée par le titulaire seul, jamais
      // imposée (docs/DECISIONS.md). Désactiver ou tirer de nouveaux codes de
      // secours se confirme par le mot de passe ou un code de l'application. ?>
<div class="card" id="deux-facteurs">
    <div class="card-head-row">
        <h2 class="mt-0">Validation en deux étapes</h2>
        <?php if (!deux_facteurs_active($u)): ?>
        <div class="head-actions">
            <a class="btn" href="?p=deux_facteurs"><?= icon('lock') ?> Activer</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if (deux_facteurs_active($u)): ?>
        <table class="kv-table">
            <tr><th>État</th><td><span class="badge ok-badge">Activée</span>
                <span class="muted small">depuis le <?= e(horodatage((string) $u['totp_active_le'], 'd.m.Y')) ?></span></td></tr>
            <tr><th>Codes de secours</th><td><?= $nbCodes ?> restant<?= $nbCodes > 1 ? 's' : '' ?></td></tr>
        </table>
        <form method="post" action="?p=deux_facteurs_codes_regenerer" class="inline-edit mt-22">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="password" name="confirmation" class="grow" required autocomplete="current-password"
                   placeholder="Mot de passe ou code de l'application" aria-label="Mot de passe ou code de l'application">
            <button type="submit" class="btn ghost"><?= icon('refresh-cw') ?> Nouveaux codes de secours</button>
            <button type="submit" class="btn danger" formaction="?p=deux_facteurs_desactiver"
                    data-confirm="Désactiver la validation en deux étapes ? La connexion ne demandera plus que le mot de passe."><?= icon('x') ?> Désactiver</button>
        </form>
    <?php else: ?>
        <p class="muted">Désactivée : la connexion ne demande que le mot de passe.</p>
    <?php endif; ?>
</div>
