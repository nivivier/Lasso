<?php /** @var int $delai */ /** @var string $lienTexteDefaut */ /** @var string $termeSpectacle */
/** @var array $paysDisponibles */ /** @var ?bool $saved */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres enregistrés.</p><?php endif; ?>

<div class="card form">
    <h2 class="mt-0">Valeurs par défaut</h2>
    <form method="post" action="?p=parametres_evenements">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Délai avant qu'une date envoyée sans décompte soit marquée « manquante » (mois)
            <input name="suisa_delai_decompte_mois" type="text" inputmode="numeric" value="<?= (int) $delai ?>" style="max-width:120px">
        </label>
        <label>Texte du bouton de lien par défaut (si un événement n'en précise pas un)
            <input name="evenements_lien_texte_defaut" type="text" value="<?= e($lienTexteDefaut) ?>" placeholder="Plus d'informations">
        </label>
        <label>Terme utilisé pour désigner une série d'événements <?= info_tip(
            "Change l'affichage dans toute l'interface (menu, listes, formulaires) — "
            . "ex. « Spectacles », « Concerts », « Tournées »."
        ) ?>
            <input name="evenements_terme_spectacle" type="text" value="<?= e($termeSpectacle) ?>" placeholder="Spectacles">
        </label>
        <label>Pays disponibles pour le champ « Région et pays » (séparés par des virgules)
            <input name="evenements_pays_disponibles" type="text" value="<?= e(implode(', ', $paysDisponibles)) ?>" placeholder="CH, FR, BE, CA">
        </label>
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
    <form method="post" action="?p=parametres_evenements" onsubmit="return confirm('Régénérer le jeton invalidera tous les liens déjà copiés (à recopier partout où ils sont utilisés). Continuer ?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton</button>
        </div>
    </form>
</div>
