<?php /** @var ?bool $saved */ /** @var ?string $err */ /** @var array $expediteurs */
/** @var bool $voirBoites */ /** @var bool $voirDebit */

// Un seul écran pour tout ce qui part de l'application (route_emails()) : les
// boîte d'envois internes, puis — pour qui voit le booking et les envois
// groupés — les boîtes d'envois externes et le débit de la file.

// Cadre d'édition d'une boîte d'expédition — le même pour une boîte existante
// et pour une nouvelle, c'est ce qui garantit que les deux ne divergent pas
// (même parti que le formulaire de contact d'une structure et que les modèles
// de mailing, views/_structure_contact_form.php).
$cadreExpediteur = function (array $exp = []) : void {
    $v = fn (string $k): string => (string) ($exp[$k] ?? '');
    $id = (int) ($exp['id'] ?? 0);
    $edition = $id > 0;
    $secure = $v('smtp_secure');
    $aMdp = $v('smtp_pass') !== '';
    ?>
    <form method="post" action="?p=emails" class="form cadre-edit exp-edit" hidden<?= $edition ? '' : ' id="exp-nouvelle"' ?>>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="expediteur_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="cadre-edit-head">
            <span class="cadre-edit-titre"><?= $edition ? e(mailing_expediteur_libelle($exp)) : 'Nouvelle boîte' ?></span>
            <div class="cadre-edit-actions">
                <button type="submit" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                <button type="button" class="btn ghost btn-sm icon-only exp-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
            </div>
        </div>
        <div class="grid2">
            <label>Nom affiché <input name="exp[nom]" value="<?= e($v('nom')) ?>" placeholder="ex. Diffusion"></label>
            <label>Adresse <input name="exp[email]" type="email" value="<?= e($v('email')) ?>" required placeholder="diffusion@exemple.ch"></label>
        </div>
        <p class="muted small mb-8">Serveur d'envoi de cette boîte. Champs laissés vides : l'envoi passe par la boîte d'envois internes.</p>
        <div class="grid2">
            <?php // type="text" et non "email" : beaucoup d'hébergeurs authentifient
                  // sur un identifiant qui n'est pas une adresse (« u123456 »,
                  // « souka »). type="email" refusait purement de soumettre. ?>
            <label>Identifiant <input name="exp[smtp_user]" value="<?= e($v('smtp_user')) ?>" placeholder="diffusion@exemple.ch ou u123456" autocomplete="off"></label>
            <label>Mot de passe <input name="exp[smtp_pass]" type="password" value="" placeholder="<?= $aMdp ? '•••••••• (inchangé)' : 'mot de passe de la boîte' ?>" autocomplete="new-password"></label>
        </div>
        <div class="grid3">
            <label>Serveur SMTP <input name="exp[smtp_host]" value="<?= e($v('smtp_host')) ?>" placeholder="mail.votre-hebergeur.ch"></label>
            <label>Port <input name="exp[smtp_port]" type="text" inputmode="numeric" value="<?= e($v('smtp_port')) ?>" placeholder="465"></label>
            <label>Sécurité
                <select name="exp[smtp_secure]">
                    <option value="" <?= $secure === '' ? 'selected' : '' ?>>Comme le serveur général</option>
                    <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                    <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                </select>
            </label>
        </div>
        <?php // Supprimer pilote un <form> posé À CÔTÉ de celui-ci (attribut
              // form=…) : deux formulaires ne peuvent pas s'imbriquer. ?>
        <?php if ($edition): ?>
        <div class="cadre-edit-pied">
            <button type="submit" form="exp-del-<?= $id ?>" class="btn danger btn-sm"><?= icon('trash') ?> Supprimer la boîte</button>
        </div>
        <?php endif; ?>
    </form>
    <?php if ($edition): ?>
    <form method="post" action="?p=emails" id="exp-del-<?= $id ?>" data-confirm="Supprimer la boîte « <?= e(mailing_expediteur_libelle($exp)) ?> » ? Les modèles et campagnes qui l'utilisaient repasseront à la boîte par défaut." hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="expediteur_delete">
        <input type="hidden" name="id" value="<?= $id ?>">
    </form>
    <?php endif;
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Réglages d'envoi enregistrés.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!peut_ecrire('coeur')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Boîte d'envois internes</h2>
        <?= carte_actions_html(['form' => 'emails-form']) ?>
    </div>
    <p class="muted small">Fiches de salaire, factures, feuilles de route et mots de passe oubliés.</p>

    <?php $secureLu = param('smtp_secure') ?: 'ssl'; ?>
    <?php $motDePasseConnu = ((string) param('smtp_pass', '') !== '') || (defined('SMTP_PASS') && SMTP_PASS !== ''); ?>
    <?php // Chiffré avec une clé propre au serveur (lib/secrets.php) : une base
          // restaurée depuis un autre serveur arrive avec des mots de passe
          // qu'on ne sait plus lire. ?>
    <?php $motDePasseIllisible = secret_illisible((string) param('smtp_pass', '')); ?>
    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Expéditeur</th><td><?= param('employeur_email_expediteur') !== '' ? e(param('employeur_email_expediteur')) : '<span class="muted">— aucun envoi possible</span>' ?></td></tr>
            <tr><th>Adresse de réponse</th><td><?= param('employeur_email_contact') !== '' ? e(param('employeur_email_contact')) : '<span class="muted">l\'expéditeur</span>' ?></td></tr>
            <tr>
                <th>Boîte d'envoi</th>
                <td><?php if (param('smtp_host') !== ''): ?>
                    <?= e(param('smtp_user')) ?> · <?= e(param('smtp_host')) ?>:<?= e(param('smtp_port')) ?>
                    · <?= $secureLu === 'tls' ? 'STARTTLS' : 'SSL' ?>
                    <?= $motDePasseIllisible ? '<span class="badge warn-badge">mot de passe à ressaisir</span>'
                        : ($motDePasseConnu ? '<span class="badge ok-badge">mot de passe enregistré</span>' : '<span class="badge warn-badge">sans mot de passe</span>') ?>
                <?php else: ?>
                    <span class="muted">aucune — repli sur la fonction <code>mail()</code> de l'hébergeur</span>
                <?php endif; ?></td>
            </tr>
        </table>
    </div>

    <form method="post" action="?p=emails" id="emails-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="internes">

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

        <p class="muted small mt-16 mb-8"><strong>Boîte d'envoi (SMTP)</strong> — celle par laquelle partent ces
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
    </form>
</div>

<?php if ($voirBoites): ?>
<div class="card form mt-22">
    <div class="card-head-row">
        <h2 class="mt-0">Boîtes d'envois externes</h2>
        <div class="exp-tete-actions">
            <a class="btn ghost" href="?p=mailing_exclusions"><?= icon('list-x') ?> Liste d'exclusion</a>
            <button type="button" class="btn" data-show="exp-nouvelle" data-focus="input[name='exp[nom]']"><?= icon('plus') ?> Nouvelle boîte</button>
        </div>
    </div>
    <p class="muted small">Envois groupés et messages aux structures du booking. La première sert par défaut.</p>

    <?php if (!$expediteurs): ?>
        <p class="muted small">Aucune boîte déclarée : les envois partent de la boîte d'envois internes.</p>
    <?php endif; ?>
    <?php foreach ($expediteurs as $exp): ?>
        <div class="exp-bloc">
            <div class="linked-add exp-lu">
                <span>
                    <strong><?= $exp['nom'] !== '' ? e($exp['nom']) : e($exp['email']) ?></strong>
                    <?php if ($exp['nom'] !== ''): ?><span class="muted small"> — <?= e($exp['email']) ?></span><?php endif; ?>
                    <div class="muted small"><?= $exp['smtp_host'] !== '' || $exp['smtp_user'] !== ''
                        ? e(($exp['smtp_host'] ?: 'serveur général') . ($exp['smtp_port'] !== '' ? ':' . $exp['smtp_port'] : '')
                            . ($exp['smtp_user'] !== '' ? ' — ' . $exp['smtp_user'] : ''))
                        : 'Serveur de la boîte d\'envois internes' ?>
                        <?php if (secret_illisible((string) $exp['smtp_pass'])): ?> <span class="badge warn-badge">mot de passe à ressaisir</span><?php endif; ?></div>
                </span>
                <button type="button" class="btn ghost btn-sm icon-only exp-editer" title="Modifier" aria-label="Modifier la boîte"><?= icon('pencil') ?></button>
            </div>
            <?php $cadreExpediteur($exp); ?>
        </div>
    <?php endforeach; ?>

    <?php $cadreExpediteur(); ?>

</div>

<?php endif; ?>

<?php if ($voirDebit): ?>
<?php // Le débit d'envoi est un réglage à part, pas un détail des boîtes : sa
      // propre carte, qui se lit et s'ouvre au crayon comme les autres. ?>
<div class="card card-editable mt-22">
    <div class="card-head-row">
        <h2 class="mt-0">Débit d'envoi</h2>
        <?= carte_actions_html(['form' => 'debit-form']) ?>
    </div>
    <p class="muted small">Une campagne n'est pas expédiée d'un bloc : elle remplit une file, vidée petit à petit par
        la tâche planifiée de l'hébergeur.</p>

    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Délai entre deux e-mails</th><td><?= (int) param('mailing_delai_secondes', '10') ?> secondes</td></tr>
            <tr><th>Plafond par 24 h</th><td><?= (int) param('mailing_max_par_jour', '200') ?> envois</td></tr>
        </table>
    </div>

    <form method="post" action="?p=emails" id="debit-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="debit">
        <div class="grid2">
            <label><span>Délai entre deux e-mails (secondes) <?= info_tip(
                "Les campagnes ne partent pas d'un bloc : la file d'attente est vidée petit à petit par la tâche "
                . "planifiée, à ce rythme, pour ne pas passer pour un envoi de masse."
            ) ?></span><input name="mailing_delai_secondes" type="number" min="0" value="<?= e(param('mailing_delai_secondes', '10')) ?>"></label>
            <label><span>Plafond d'envoi par 24 h <?= info_tip(
                "Au-delà, la file s'arrête et reprend le lendemain. Compte tous les envois de mailing réussis des "
                . "dernières 24 heures, toutes campagnes confondues."
            ) ?></span><input name="mailing_max_par_jour" type="number" min="1" value="<?= e(param('mailing_max_par_jour', '200')) ?>"></label>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($voirBoites): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    lassoInitBlocEdition({
        bloc: '.exp-bloc', lecture: '.exp-lu', edition: '.exp-edit',
        ouvrir: '.exp-editer', annuler: '.exp-annuler',
    });
    // La boîte neuve n'a pas de ligne de lecture : sa croix la referme, tout
    // simplement (le bouton « Nouvelle boîte » la révèle, via data-show).
    var neuve = document.getElementById('exp-nouvelle');
    if (neuve) {
        neuve.querySelector('.exp-annuler').addEventListener('click', function () {
            neuve.reset();
            neuve.hidden = true;
        });
    }
})();
</script>
<?php endif; ?>
<?php endif; ?>
