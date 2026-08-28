<?php /** @var ?bool $saved */ /** @var ?string $err */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres d'envoi enregistrés.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!peut_ecrire('coeur')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <form method="post" action="?p=emails">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub no-mt">Fiches de salaire et factures</h3>
        <p class="muted small">Les deux seuls envois qui passent par ici : la <strong>fiche de salaire</strong> d'un
            employé (bouton « Envoyer par e-mail » sur une fiche) et la <strong>facture</strong> d'un débiteur, avec son
            PDF joint (bouton « Envoyer » sur une facture).</p>
        <div class="grid2">
            <label><span>Expéditeur <?= info_tip(
                "Adresse qui figure en « De : » sur les fiches de salaire et les factures envoyées depuis "
                . "l'application. Sans elle, le bouton d'envoi d'une fiche refuse de partir."
            ) ?></span><input name="employeur_email_expediteur" type="email" value="<?= e(param('employeur_email_expediteur')) ?>" placeholder="salaires@exemple.ch"></label>
            <label><span>Adresse de réponse <?= info_tip(
                "Posée en « Répondre à : » sur ces mêmes envois — c'est là qu'arrivent les réponses d'un employé "
                . "ou d'un débiteur. Vide : les réponses reviennent à l'expéditeur ci-contre."
            ) ?></span><input name="employeur_email_contact" type="email" value="<?= e(param('employeur_email_contact')) ?>" placeholder="contact@exemple.ch"></label>
        </div>

        <p class="muted small mt-16 mb-8"><strong>Boîte d'envoi (SMTP)</strong> — celle par laquelle partent ces deux
            envois. Indiquez une boîte réelle et authentifiée, idéalement celle de l'expéditeur ci-dessus. Laissée vide,
            l'application se rabat sur la fonction <code>mail()</code> de l'hébergeur, si elle est disponible.</p>
        <?php $secure = param('smtp_secure') ?: 'ssl'; ?>
        <?php $hasPass = ((string) param('smtp_pass', '') !== '') || (defined('SMTP_PASS') && SMTP_PASS !== ''); ?>
        <div class="grid2">
            <?php // Pas de type="email" : l'identifiant SMTP n'est pas toujours une
                  // adresse (« u123456 » chez certains hébergeurs). ?>
            <label>Identifiant <input name="smtp_user" value="<?= e(param('smtp_user')) ?>" placeholder="salaires@exemple.ch ou u123456" autocomplete="off"></label>
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

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
<?php endif; ?>
