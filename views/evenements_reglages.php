<?php /** @var int $delai */ /** @var int $delaiAbandon */ /** @var string $lienTexteDefaut */ /** @var string $termeProjet */ /** @var string $termeProjetSingulier */
/** @var ?bool $saved */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres enregistrés.</p><?php endif; ?>

<?php if (!peut_ecrire('evenements')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Valeurs par défaut</h2>
        <?= carte_actions_html(['form' => 'evenements-defauts-form']) ?>
    </div>

    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Décompte marqué « manquant » après</th><td><?= (int) $delai ?> mois</td></tr>
            <tr><th>Événement marqué « abandonné » après</th><td><?= (int) $delaiAbandon ?> mois</td></tr>
            <tr><th>Texte du bouton de lien</th><td><?= $lienTexteDefaut !== '' ? e($lienTexteDefaut) : '<span class="muted">Plus d\'informations</span>' ?></td></tr>
            <tr><th>Terme pour une série d'événements</th><td>
                <?= e($termeProjet !== '' ? $termeProjet : 'Projets') ?>
                <span class="muted small">(pluriel)</span> ·
                <?= e($termeProjetSingulier !== '' ? $termeProjetSingulier : 'Projet') ?>
                <span class="muted small">(singulier)</span></td></tr>
        </table>
        <p class="muted small">Les pays proposés dans le champ « Région et pays » se règlent dans l'onglet <a href="?p=pays">Pays</a>.</p>
    </div>

    <form method="post" action="?p=evenements_reglages" id="evenements-defauts-form" class="card-edit form" hidden>
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
        <?php // Les deux formes se saisissent : le français ne forme pas toujours
              // son pluriel en ajoutant un « s », et deviner l'une à partir de
              // l'autre s'est déjà trompé (evenements_terme_projet()). Chaque
              // étiquette dit laquelle on attend. ?>
        <div class="grid2">
            <label><span>Terme pour une série d'événements, au <strong>pluriel</strong> <?= info_tip(
                "Change l'affichage dans toute l'interface (menu, listes, formulaires) — "
                . "ex. « Projets », « Concerts », « Tournées »."
            ) ?></span>
                <input name="evenements_terme_projet" type="text" value="<?= e($termeProjet) ?>" placeholder="Projets">
            </label>
            <label><span>…et au <strong>singulier</strong> <?= info_tip(
                "Saisi à part, et non déduit du pluriel : « Festivals » donne « Festival », "
                . "mais « Travaux » ne donnerait pas « Travail »."
            ) ?></span>
                <input name="evenements_terme_projet_singulier" type="text" value="<?= e($termeProjetSingulier) ?>" placeholder="Projet">
            </label>
        </div>
    </form>
</div>

<?php endif; ?>
