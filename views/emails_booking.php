<?php /** @var ?bool $saved */ /** @var ?string $err */ /** @var array $expediteurs */

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
    <form method="post" action="?p=emails_booking" class="form cadre-edit exp-edit" hidden<?= $edition ? '' : ' id="exp-nouvelle"' ?>>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="expediteur_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="cadre-edit-head">
            <span class="cadre-edit-titre"><?= $edition ? e(mailing_expediteur_libelle($exp)) : 'Nouvelle boîte' ?></span>
            <div class="cadre-edit-actions">
                <button type="button" class="btn ghost btn-sm icon-only exp-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                <button type="submit" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            </div>
        </div>
        <div class="grid2">
            <label>Nom affiché <input name="exp[nom]" value="<?= e($v('nom')) ?>" placeholder="ex. Diffusion"></label>
            <label>Adresse <input name="exp[email]" type="email" value="<?= e($v('email')) ?>" required placeholder="diffusion@exemple.ch"></label>
        </div>
        <p class="muted small mb-8">Serveur d'envoi de cette boîte. Champs laissés vides : l'envoi retombe sur le serveur des envois généraux.</p>
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
    <form method="post" action="?p=emails_booking" id="exp-del-<?= $id ?>" data-confirm="Supprimer la boîte « <?= e(mailing_expediteur_libelle($exp)) ?> » ? Les modèles et campagnes qui l'utilisaient repasseront à la boîte par défaut." hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="expediteur_delete">
        <input type="hidden" name="id" value="<?= $id ?>">
    </form>
    <?php endif;
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Réglages d'envoi du booking enregistrés.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!peut_ecrire('coeur')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <div class="card-head-row">
        <h2 class="mt-0">Boîtes d'expédition</h2>
        <div class="exp-tete-actions">
            <a class="btn ghost" href="?p=mailing_exclusions"><?= icon('list-x') ?> Liste d'exclusion</a>
            <button type="button" class="btn" data-show="exp-nouvelle" data-focus="input[name='exp[nom]']"><?= icon('plus') ?> Nouvelle boîte</button>
        </div>
    </div>
    <p class="muted small">Autant de boîtes que l'association a de voix — diffusion, production, direction — chacune avec
        son adresse ET son serveur d'envoi, qui peut être chez un autre hébergeur. Une campagne choisit la sienne au
        moment de l'écrire, et un modèle de message peut en proposer une par défaut. La première de la liste sert de
        proposition.</p>

    <?php if (!$expediteurs): ?>
        <p class="muted small">Aucune boîte déclarée : les campagnes partent de l'adresse d'expédition des envois généraux.</p>
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
                        : 'Serveur des envois généraux' ?></div>
                </span>
                <button type="button" class="btn ghost btn-sm icon-only exp-editer" title="Modifier" aria-label="Modifier la boîte"><?= icon('pencil') ?></button>
            </div>
            <?php $cadreExpediteur($exp); ?>
        </div>
    <?php endforeach; ?>

    <?php $cadreExpediteur(); ?>

    <h3 class="sub">Débit d'envoi</h3>
    <p class="muted small">Une campagne n'est pas expédiée d'un bloc : elle remplit une file, vidée petit à petit par
        la tâche planifiée de l'hébergeur.</p>
    <form method="post" action="?p=emails_booking">
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
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>

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
