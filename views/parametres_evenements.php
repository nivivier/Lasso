<?php /** @var int $delai */ /** @var int $delaiAbandon */ /** @var string $lienTexteDefaut */ /** @var string $termeSpectacle */
/** @var ?bool $saved */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres enregistrés.</p><?php endif; ?>

<?php if (!peut_ecrire('evenements')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <h2 class="mt-0">Valeurs par défaut</h2>
    <form method="post" action="?p=parametres_evenements">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Délai avant qu'une date envoyée sans décompte soit marquée « manquante » (mois)
            <input name="suisa_delai_decompte_mois" type="text" inputmode="numeric" value="<?= (int) $delai ?>" style="max-width:120px">
        </label>
        <label>Délai avant qu'un événement sans décompte soit marqué « abandonné » (mois, depuis la date de l'événement)
            <input name="suisa_delai_abandon_mois" type="text" inputmode="numeric" value="<?= (int) $delaiAbandon ?>" style="max-width:120px">
        </label>
        <label>Texte du bouton de lien par défaut (si un événement n'en précise pas un)
            <input name="evenements_lien_texte_defaut" type="text" value="<?= e($lienTexteDefaut) ?>" placeholder="Plus d'informations">
        </label>
        <label><span>Terme utilisé pour désigner une série d'événements <?= info_tip(
            "Change l'affichage dans toute l'interface (menu, listes, formulaires) — "
            . "ex. « Spectacles », « Concerts », « Tournées »."
        ) ?></span>
            <input name="evenements_terme_spectacle" type="text" value="<?= e($termeSpectacle) ?>" placeholder="Spectacles">
        </label>
        <p class="muted small">Les pays proposés dans le champ « Région et pays » se règlent dans l'onglet <a href="?p=parametres_pays">Pays</a>.</p>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>

<div class="card form mt-22">
    <h2 class="mt-0">Synchronisation</h2>
    <p class="muted small">
        Les liens d'export (JSON/iCal) protégés par jeton se copient depuis la fiche de chaque <?= mb_strtolower(evenements_terme_spectacle(false)) ?>.
        Ils exposent en lecture seule les événements publics/privés (jamais les non répertoriés, jamais
        les informations SUISA/facturation/employés) — voir <code>SPEC_EVENEMENTS.md</code> §8.
    </p>
    <form method="post" action="?p=parametres_evenements" data-confirm="Régénérer le jeton invalidera tous les liens déjà copiés (à recopier partout où ils sont utilisés). Continuer ?">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton public</button>
        </div>
    </form>

    <?php // Second jeton, second usage. Celui-là ouvre TOUT : les dates encore en
          // option, celles qui ne sont pas répertoriées, et le contenu des
          // feuilles de route — adresses d'hébergement, codes, portables, pièces
          // jointes. Un abonnement iCal ne sait pas s'authentifier autrement
          // qu'en portant son secret dans l'URL : ce lien EST un mot de passe. ?>
    <h2>Calendrier de l'équipe</h2>
    <p class="muted small">
        Un second lien iCal, à ne donner qu'à l'équipe : il montre <strong>tout</strong> — les dates en option,
        celles qui ne sont pas répertoriées, et le contenu des <strong>feuilles de route</strong> (horaires, adresses,
        contacts, pièces jointes). Il se copie depuis la liste des <?= mb_strtolower(evenements_terme_spectacle()) ?>,
        globalement ou pour un seul d'entre eux. Régénérer son jeton est la seule façon de révoquer un abonnement :
        cela coupe tout le monde d'un coup.
    </p>
    <form method="post" action="?p=parametres_evenements" data-confirm="Régénérer ce jeton coupera TOUS les abonnements au calendrier de l'équipe, pour tout le monde. Continuer ?">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token_equipe" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton de l'équipe</button>
        </div>
    </form>
</div>
<?php endif; ?>
