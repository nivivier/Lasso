<?php /** @var bool $saved */ /** @var ?string $err */ ?>
<div class="page-head"><h1>Employeur</h1></div>
<?php if ($saved): ?><p class="ok flash">Coordonnées enregistrées.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <p class="muted small">Nom et adresse apparaissent en tête de chaque fiche de salaire. L'e-mail d'expéditeur sert à l'envoi automatique des fiches par e-mail.</p>
    <form method="post" action="?p=employeur" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub">Nom de l'employeur</h3>
	        <input name="employeur_nom" value="<?= e(param('employeur_nom')) ?>">

        <h3 class="sub">Logos de l'employeur</h3>

        <p class="muted small">Formats acceptés : PNG, JPG, GIF ou WebP (2 Mo max). Laissez vide pour conserver le logo actuel.</p>
        <div class="grid2">
            <label>Logo sur fond clair <span class="muted small">(fiches de salaire, e-mail)</span>
                <?php $lc = param_logo('clair'); ?>
                <?php if ($lc !== ''): ?><span class="logo-preview clair"><img src="<?= e($lc) ?>" alt=""></span><?php endif; ?>
                <input type="file" name="logo_clair" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <label>Logo sur fond sombre <span class="muted small">(connexion, barre latérale)</span>
                <?php $ls = param_logo('sombre'); ?>
                <?php if ($ls !== ''): ?><span class="logo-preview sombre"><img src="<?= e($ls) ?>" alt=""></span><?php endif; ?>
                <input type="file" name="logo_sombre" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
        </div>

        <h3 class="sub">Coordonnées</h3>
        <p class="muted small">Ces coordonnées seront affichées sur les fiches de salaire</p>
        <div class="grid3">
            <label>Rue <input name="employeur_rue" value="<?= e(param('employeur_rue')) ?>"></label>
            <label>NPA, localité <input name="employeur_npa" value="<?= e(param('employeur_npa')) ?>"></label>
            <label>Pays <input name="employeur_pays" value="<?= e(param('employeur_pays')) ?>"></label>
        </div>
    
        <h3 class="sub">Emails</h3>

        <div class="grid2">
            <label>E-mail d'expéditeur (envois automatiques) <input name="employeur_email_expediteur" type="email" value="<?= e(param('employeur_email_expediteur')) ?>" placeholder="salaires@exemple.ch"></label>
   			<label>E-mail de contact (reply-to)<input name="employeur_email_contact" type="email" value="<?= e(param('employeur_email_contact')) ?>" placeholder="contact@exemple.ch"></label>
        </div>
        <p class="muted small">L'envoi passe par un serveur SMTP authentifié. Indiquez une boîte e-mail réelle (idéalement la même que l'expéditeur ci-dessus). Laissez vide pour utiliser la fonction <code>mail()</code> de l'hébergeur si elle est disponible.</p>
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

        <h3 class="sub">Certificat de salaire (eCS CSI)</h3>
        <p class="muted small">Ces champs ne servent qu'à l'export XML destiné à l'application « eCertificat de salaire CSI ».</p>
        <div class="grid2">
            <label>Téléphone <input name="employeur_telephone" value="<?= e(param('employeur_telephone')) ?>" placeholder="022 111 22 33"></label>
            <label>Heures hebdomadaires de référence <input name="employeur_heures_hebdo" type="text" inputmode="decimal" value="<?= e(param('employeur_heures_hebdo')) ?>" placeholder="40.00"></label>
        </div>
        <div class="grid2">
            <label>Personne de contact (nom) <input name="employeur_contact_nom" value="<?= e(param('employeur_contact_nom')) ?>"></label>
            <label>Personne de contact (téléphone) <input name="employeur_contact_tel" value="<?= e(param('employeur_contact_tel')) ?>"></label>
        </div>

        <div class="form-actions">
            <button type="submit">Enregistrer</button>
        </div>
    </form>
</div>
