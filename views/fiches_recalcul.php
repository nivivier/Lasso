<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $lignes */
/** @var int $faites */ /** @var string $sauv */ /** @var bool $vide */
// Aperçu avant/après. Rien n'est écrit tant que le formulaire n'est pas envoyé,
// et seules les fiches cochées sont recalculées.
$aRecalculer = array_values(array_filter($lignes, fn ($l) => $l['ecarts']));
$nonPayees   = array_values(array_filter($aRecalculer, fn ($l) => !$l['payee']));
$mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
         'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$cle = ['salaire_brut' => 'Brut', 'total_deductions' => 'Déductions',
        'salaire_net' => 'Net', 'cout_total_emp' => 'Coût employeur'];
?>
<a class="back-link" href="?p=postes&amp;annee=<?= $annee ?>"><?= icon('arrow-left') ?> Lignes du décompte</a>
<div class="page-head-band"><div class="page-head">
    <div class="page-head-title"><h1>Recalcul des fiches</h1></div>
</div></div>

<?php if ($faites > 0): ?>
    <p class="ok flash"><?= $faites ?> fiche(s) recalculée(s).<?php if ($sauv !== ''): ?> Sauvegarde de la base avant l'opération : <code><?= e($sauv) ?></code>.<?php endif; ?></p>
<?php endif; ?>
<?php if ($vide): ?><p class="err flash">Aucune fiche cochée : rien n'a été modifié.</p><?php endif; ?>

<div class="card">
    <div class="year-bar">
        <h2>Année <?= $annee ?></h2>
        <label class="inline">
            <select data-go-on-change="?p=fiches_recalcul&annee=">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <p class="muted small">
        Une fiche fige ses montants et ses taux à sa création : changer un taux ou un poste ne réécrit rien.
        Ce recalcul les réécrit, pour les fiches que vous cochez, avec les postes et les taux <strong>actuels</strong>
        de l'année. Les lignes de prestation, la date de paiement et l'instantané de l'employé ne bougent pas.
        La base est sauvegardée automatiquement juste avant.
    </p>

    <?php if (!$aRecalculer): ?>
        <p class="muted">Aucune fiche de <?= $annee ?> ne changerait : les montants figés correspondent déjà aux taux en vigueur.</p>
    <?php else: ?>
        <p class="err"><strong><?= count($aRecalculer) ?> fiche(s)</strong> seraient modifiées, dont
            <strong><?= count($aRecalculer) - count($nonPayees) ?></strong> déjà payée(s) — celles-là ne sont pas cochées par défaut.</p>

        <?php if (!peut_ecrire('salaires')): ?>
            <p class="err">Vous n'avez pas les droits d'écriture nécessaires pour recalculer des fiches.</p>
        <?php endif; ?>

        <form method="post" action="?p=fiches_recalcul" data-confirm="Recalculer les fiches cochées ? Les montants figés seront réécrits.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="annee" value="<?= $annee ?>">
            <div class="table-scroll">
            <table class="list mb-16">
                <thead><tr>
                    <th></th><th>Employé</th><th>Mois</th>
                    <?php foreach ($cle as $lib): ?><th class="num"><?= e($lib) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($aRecalculer as $l): $f = $l['fiche']; ?>
                    <tr>
                        <td><input type="checkbox" name="fiches[]" value="<?= (int) $f['id'] ?>" <?= $l['payee'] ? '' : 'checked' ?>></td>
                        <td><?= e($f['prenom'] . ' ' . $f['nom']) ?><?php if ($l['payee']): ?> <span class="badge">payée</span><?php endif; ?></td>
                        <td><?= e($mois[(int) $f['mois']] ?? '') ?></td>
                        <?php foreach (array_keys($cle) as $col): ?>
                            <td class="num">
                                <?php if (isset($l['ecarts'][$col])): ?>
                                    <span class="muted"><s><?= chf($l['ecarts'][$col][0]) ?></s></span>
                                    <strong><?= chf($l['ecarts'][$col][1]) ?></strong>
                                <?php else: ?>
                                    <?= chf((float) $f[$col]) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (peut_ecrire('salaires')): ?>
            <div class="form-actions">
                <button type="submit"><?= icon('refresh-cw') ?> Recalculer les fiches cochées</button>
            </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
