<?php /** @var int $preavisBilan */ /** @var ?bool $saved */
// Le seul réglage du module pour l'instant : à quelle distance de l'échéance un
// bilan devient une tâche. La carte s'ouvre au crayon comme partout ailleurs
// (docs/UI.md § 2a).
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres enregistrés.</p><?php endif; ?>

<?php if (!peut_ecrire('fonds')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Valeurs par défaut</h2>
        <?= carte_actions_html(['form' => 'fonds-defauts-form']) ?>
    </div>

    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Bilan annoncé</th><td><?= (int) $preavisBilan ?> jours avant l'échéance</td></tr>
        </table>
    </div>

    <form method="post" action="?p=fonds_reglages" id="fonds-defauts-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label><span>Annoncer un bilan à rendre combien de jours à l'avance <?= info_tip(
            "Un bilan n'est annoncé que lorsque son échéance approche : avant ce délai, le dossier reste "
            . "simplement « accordé ». Un bilan dû dans huit mois n'est pas une tâche — le tableau de bord "
            . "montrerait toute la saison et on cesserait de le lire, et l'étiquette « Bilan à rendre » se "
            . "poserait sur un dossier dès le jour de l'accord. Ce qui est déjà en retard, lui, reste affiché "
            . "quoi qu'il arrive."
        ) ?></span>
            <input name="fonds_preavis_bilan_jours" type="text" inputmode="numeric"
                   value="<?= (int) $preavisBilan ?>" style="max-width:120px">
        </label>
    </form>
</div>
<?php endif; ?>
