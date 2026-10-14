<?php
// Carte « Recherche de fonds » d'une fiche structure : les dossiers de
// subvention déposés chez ce bailleur, toutes campagnes confondues.
//
// Attendu de l'appelant (views/structure.php) : $dossiersFonds.
?>
<?php // Les dossiers de subvention déposés chez ce bailleur, toutes campagnes
      // confondues. L'étiquette nomme le MODULE, comme celle du booking juste
      // au-dessus : deux cartes de campagnes sur le même écran ne diraient plus
      // laquelle est laquelle.
      //
      // Affichée même vide, comme sa jumelle : « aucun dossier » est une
      // réponse, alors qu'une carte absente laisse se demander si l'on a
      // cherché. C'est la question qu'on vient poser sur la fiche d'un
      // bailleur — a-t-on déjà demandé quelque chose ici ? ?>
<?php if (module_accessible('fonds')): ?>
<div class="card">
    <div class="card-block">
        <h2 class="mt-0">Recherche de fonds<?= $dossiersFonds ? ' (' . count($dossiersFonds) . ')' : '' ?> <?= info_tip(
            "Ce que ce bailleur exige comme pièces se règle depuis l'un de ses dossiers : "
            . "c'est lui qui l'exige, pas la campagne."
        ) ?></h2>
        <?php if (!$dossiersFonds): ?>
        <p class="muted mb-0">Aucun dossier de subvention chez cette structure.</p>
        <?php else: ?>
        <?php $aujourdhuiFonds = date('Y-m-d'); $preavisBilanFonds = fonds_preavis_bilan_jours(); ?>
        <div class="table-scroll">
        <table class="list mb-0">
            <thead><tr><th>Campagne</th><th class="num">Demandé</th><th class="num">Accordé</th><th>État</th></tr></thead>
            <tbody>
            <?php foreach ($dossiersFonds as $df): ?>
                <?php $statutF = fonds_demande_statut($df, $aujourdhuiFonds, $preavisBilanFonds); ?>
                <tr>
                    <td>
                        <a href="<?= url_avec_retour('?p=fonds_demande&id=' . (int) $df['id'], 'structure', $sid) ?>"><?= e((string) $df['campagne_nom']) ?></a>
                        <?php if (trim((string) $df['date_limite']) !== ''): ?>
                        <div class="muted small">Délai : <?= e(date('d.m.Y', strtotime((string) $df['date_limite']))) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num small"><?= (float) $df['montant_demande'] > 0 ? chf((float) $df['montant_demande']) : '—' ?></td>
                    <td class="num strong"><?= (float) $df['montant_accorde'] > 0 ? chf((float) $df['montant_accorde']) : '—' ?></td>
                    <td><?= badge(FONDS_STATUTS[$statutF] ?? $statutF, FONDS_STATUTS_CLASSES[$statutF] ?? 'muted') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
