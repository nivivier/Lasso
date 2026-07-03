<?php /** @var int $delai */ /** @var string $token */ /** @var string $urlJson */ /** @var string $urlIcal */
/** @var array $spectacles */ /** @var ?bool $saved */

$urlField = function (string $label, string $url) {
    echo '<label>' . e($label);
    echo '<div class="url-copy">';
    echo '<input type="text" readonly value="' . e($url) . '" onclick="this.select()">';
    echo '<button type="button" class="btn ghost btn-sm url-copy-btn">Copier</button>';
    echo '</div></label>';
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Paramètres enregistrés.</p><?php endif; ?>

<div class="card form">
    <h3 class="sub no-mt">Suivi SUISA</h3>
    <form method="post" action="?p=parametres_evenements">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Délai avant qu'une date envoyée sans décompte soit marquée « manquante » (mois)
            <input name="suisa_delai_decompte_mois" type="text" inputmode="numeric" value="<?= (int) $delai ?>" style="max-width:120px">
        </label>
        <div class="form-actions">
            <button type="submit">Enregistrer</button>
        </div>
    </form>

    <h3 class="sub">Export public des événements</h3>
    <p class="muted small">
        Ces liens exposent en lecture seule les événements publics/privés (jamais les non répertoriés,
        jamais les informations SUISA/facturation/employés) — réutilisables par le site web associatif
        ou tout autre système. Voir <code>SPEC_EVENEMENTS.md</code> §8.
    </p>
    <div class="grid2">
        <?php $urlField('Tous les événements (JSON)', $urlJson); ?>
        <?php $urlField('Tous les événements (iCal)', $urlIcal); ?>
    </div>

    <?php if ($spectacles): ?>
    <h3 class="sub">Lien par spectacle</h3>
    <div class="check-list" style="max-height:none">
        <?php foreach ($spectacles as $s): ?>
            <div class="grid2">
                <?php $urlField($s['nom'] . ' (JSON)', evenements_export_url('evenements_json', $token, (int) $s['id'])); ?>
                <?php $urlField($s['nom'] . ' (iCal)', evenements_export_url('evenements_ical', $token, (int) $s['id'])); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="?p=parametres_evenements" onsubmit="return confirm('Régénérer le jeton invalidera tous les liens ci-dessus (à recopier partout où ils sont utilisés). Continuer ?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="regenerer_token" value="1">
        <div class="form-actions">
            <button type="submit" class="btn ghost"><?= icon('lock') ?> Régénérer le jeton</button>
        </div>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('.url-copy-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.previousElementSibling;
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                const label = btn.textContent;
                btn.textContent = 'Copié !';
                setTimeout(() => { btn.textContent = label; }, 1500);
            });
        });
    });
})();
</script>
