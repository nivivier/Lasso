<?php /** @var ?bool $saved */ /** @var ?string $err */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres d'envoi enregistrés.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!peut_ecrire('coeur')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <p class="muted small">Ces réglages pilotent l'envoi automatique des fiches de salaire par e-mail.</p>
    <form method="post" action="?p=emails">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub no-mt">Adresses</h3>
        <div class="grid2">
            <label>E-mail d'expéditeur (envois automatiques) <input name="employeur_email_expediteur" type="email" value="<?= e(param('employeur_email_expediteur')) ?>" placeholder="salaires@exemple.ch"></label>
            <label>E-mail de contact (reply-to) <input name="employeur_email_contact" type="email" value="<?= e(param('employeur_email_contact')) ?>" placeholder="contact@exemple.ch"></label>
        </div>

        <h3 class="sub">Serveur d'envoi (SMTP) <?= info_tip(
            "L'envoi passe par un serveur SMTP authentifié. Indiquez une boîte e-mail réelle (idéalement la même que "
            . "l'expéditeur ci-dessus). Laissez vide pour utiliser la fonction mail() de l'hébergeur si elle est disponible."
        ) ?></h3>
        <?php $secure = param('smtp_secure') ?: 'ssl'; ?>
        <?php $hasPass = ((string) param('smtp_pass', '') !== '') || (defined('SMTP_PASS') && SMTP_PASS !== ''); ?>
        <div class="grid2">
            <label>Identifiant (adresse complète de la boîte) <input name="smtp_user" type="email" value="<?= e(param('smtp_user')) ?>" placeholder="salaires@exemple.ch" autocomplete="off"></label>
            <label>Mot de passe <input name="smtp_pass" type="password" value="" placeholder="<?= $hasPass ? '•••••••• (inchangé)' : 'mot de passe de la boîte' ?>" autocomplete="new-password"></label>
        </div>
        <div class="grid3">
            <label>Serveur SMTP <input name="smtp_host" value="<?= e(param('smtp_host')) ?>" placeholder="mail.votre-hebergeur.ch"></label>
            <label>Port <input name="smtp_port" type="text" inputmode="numeric" value="<?= e(param('smtp_port')) ?>" placeholder="465"></label>
            <label>Sécurité
                <select name="smtp_secure">
                    <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                    <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                </select>
            </label>
        </div>

        <?php if (module_actif('booking')): ?>
        <div class="section-head">
            <h3 class="sub">Mailing booking <?= info_tip(
                "Boîte d'envoi et débit distincts pour le mailing ciblé du module booking. Les champs SMTP "
                . "laissés vides retombent sur le serveur d'envoi général ci-dessus."
            ) ?></h3>
            <a class="btn ghost btn-sm ml-auto" href="?p=mailing_exclusions"><?= icon('list-x') ?> Liste d'exclusion</a>
        </div>
        <?php $secureBooking = param('smtp_booking_secure') ?: 'ssl'; ?>
        <?php $hasPassBooking = (string) param('smtp_booking_pass', '') !== ''; ?>
        <div class="grid2">
            <label>Identifiant (optionnel) <input name="smtp_booking_user" type="email" value="<?= e(param('smtp_booking_user')) ?>" placeholder="booking@exemple.ch" autocomplete="off"></label>
            <label>Mot de passe <input name="smtp_booking_pass" type="password" value="" placeholder="<?= $hasPassBooking ? '•••••••• (inchangé)' : 'mot de passe de la boîte' ?>" autocomplete="new-password"></label>
        </div>
        <div class="grid3">
            <label>Serveur SMTP <input name="smtp_booking_host" value="<?= e(param('smtp_booking_host')) ?>" placeholder="mail.votre-hebergeur.ch"></label>
            <label>Port <input name="smtp_booking_port" type="text" inputmode="numeric" value="<?= e(param('smtp_booking_port')) ?>" placeholder="465"></label>
            <label>Sécurité
                <select name="smtp_booking_secure">
                    <option value="ssl" <?= $secureBooking === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                    <option value="tls" <?= $secureBooking === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                </select>
            </label>
        </div>
        <div class="grid2">
            <label>Délai entre deux e-mails (secondes) <input name="mailing_delai_secondes" type="number" min="0" value="<?= e(param('mailing_delai_secondes', '10')) ?>"></label>
            <label>Plafond d'envoi par 24h <input name="mailing_max_par_jour" type="number" min="1" value="<?= e(param('mailing_max_par_jour', '200')) ?>"></label>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
<?php endif; ?>
