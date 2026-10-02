<?php /** @var ?bool $saved */ /** @var ?string $regenere */
// Les deux jetons qui ouvrent l'application vers l'extérieur. Ils vivent sous
// « Données » et non sous « Valeurs et libellés » : ce ne sont pas des réglages
// d'affichage, ce sont des portes — on vient ici pour en changer la serrure.
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($regenere === 'public'): ?><p class="ok flash">Jeton public régénéré. Les liens déjà copiés ne répondent plus — recopiez-les là où ils servent.</p>
<?php elseif ($regenere === 'equipe'): ?><p class="ok flash">Jeton de l'équipe régénéré. Tous les abonnements au calendrier de l'équipe sont coupés.</p><?php endif; ?>

<?php if (!peut_ecrire('evenements')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <h2 class="mt-0">Dates publiques</h2>
    <p class="muted small">
        Les liens d'export (JSON/iCal) protégés par jeton se copient depuis la liste des
        <?= mb_strtolower(evenements_terme_projet()) ?>, globalement ou pour un seul d'entre eux.
        Ils exposent en lecture seule les événements publics/privés (jamais les non répertoriés, jamais
        les informations SUISA/facturation/employés) — voir <code>SPEC_EVENEMENTS.md</code> §8.
    </p>
    <form method="post" action="?p=synchronisation" data-confirm="Régénérer le jeton invalidera tous les liens déjà copiés (à recopier partout où ils sont utilisés). Continuer ?">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton public</button>
        </div>
    </form>
</div>

<?php // Second jeton, second usage. Celui-là ouvre TOUT : les dates encore en
      // option, celles qui ne sont pas répertoriées, et le contenu des feuilles
      // de route — adresses d'hébergement, codes, portables, pièces jointes. Un
      // abonnement iCal ne sait pas s'authentifier autrement qu'en portant son
      // secret dans l'URL : ce lien EST un mot de passe. ?>
<div class="card form mt-22">
    <h2 class="mt-0">Calendrier de l'équipe</h2>
    <p class="muted small">
        Un second lien iCal, à ne donner qu'à l'équipe : il montre <strong>tout</strong> — les dates en option,
        celles qui ne sont pas répertoriées, et le contenu des <strong>feuilles de route</strong> (horaires, adresses,
        contacts, pièces jointes). Il se copie au même endroit que les liens publics. Régénérer son jeton est la
        seule façon de révoquer un abonnement : cela coupe tout le monde d'un coup.
    </p>
    <form method="post" action="?p=synchronisation" data-confirm="Régénérer ce jeton coupera TOUS les abonnements au calendrier de l'équipe, pour tout le monde. Continuer ?">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token_equipe" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton de l'équipe</button>
        </div>
    </form>
</div>
<?php endif; ?>
