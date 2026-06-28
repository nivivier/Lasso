<?php /** @var array $f */
// Corps du décompte de salaire, réutilisé à l'écran et à l'impression.
$taux = json_decode($f['taux_json'] ?: '{}', true) ?: [];
$estImpot  = $f['procedure'] === 'Ordinaire avec impôt à la source';
$hnum = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');

$lignes   = fiche_lignes_de($f);
$uneSeule = count($lignes) === 1;
$hasAxe = empty($impression) && !empty($axes ?? []);
// Génère le <td> de l'axe analytique pour une ligne.
// Axe défini → texte + crayon (select caché). Pas d'axe → select visible directement.
$axeCellHtml = function (array $l) use ($axes): string {
    $axeId  = (int) ($l['axe_analytique_id'] ?? 0);
    $axeLib = '';
    foreach ($axes as $ax) {
        if ((int) $ax['id'] === $axeId) { $axeLib = $ax['code'] ?: $ax['libelle']; break; }
    }
    $h = '<td class="ligne-axe-cell" data-ligne-id="' . (int) $l['id'] . '">';
    if ($axeId && $axeLib !== '') {
        $h .= '<div class="axe-disp">';
        $h .= '<span class="axe-disp-txt">' . e($axeLib) . '</span>';
        $h .= '<button type="button" class="row-edit-btn axe-edit-btn" title="Modifier l\'axe">' . icon('pencil') . '</button>';
        $h .= '</div>';
        $h .= '<select class="ligne-axe-sel axe-inline-sel" hidden>';
    } else {
        $h .= '<select class="ligne-axe-sel axe-inline-sel">';
    }
    $h .= '<option value="">— Axe —</option>';
    foreach ($axes as $ax) {
        $sel = (int) $ax['id'] === $axeId ? ' selected' : '';
        $h  .= '<option value="' . (int) $ax['id'] . '"' . $sel . '>' . e($ax['code'] ?: $ax['libelle']) . '</option>';
    }
    $h .= '</select></td>';
    return $h;
};

// Seuil mensuel « 8 h/semaine » (jours ÷ 7 × 8) — détermine le taux LAA appliqué.
$seuilH    = seuil_heures((int) $f['annee'], (int) $f['mois']);
$sousSeuil = (float) $f['nombre_heures'] <= $seuilH;
$seuilTxt  = ($sousSeuil ? '≤' : '>') . ' 8 h/sem.';

// Lignes de déduction : [libellé, taux affiché ou null, montant, visible?]
$deductions = [
    ['AVS / AI / APG', $taux['avs'] ?? null,  (float) $f['ded_avs'], true],
    ['AC',             $taux['ac'] ?? null,   (float) $f['ded_ac'], true],
    ['Assurance maternité', $taux['amat'] ?? null, (float) $f['ded_amat'], ((float) $f['ded_amat']) > 0],
    ['LAA',            $taux['laa'] ?? null,  (float) $f['ded_laa'], true],
    ['LPP',            $taux['lpp'] ?? null,  (float) $f['ded_lpp'], true],
    ['Impôt à la source', $taux['impot_source'] ?? null, (float) $f['ded_impot_source'], $estImpot],
];
?>
<div class="payslip">
    <?php $psLogo = $logo_src ?? param_logo('clair'); ?>
    <?php if ($psLogo !== ''): ?>
    <div class="ps-head">
        <img src="<?= e($psLogo) ?>" alt="" class="ps-logo">
    </div>
    <?php endif; ?>
    <div class="ps-title">
        <h2>Décompte de salaire</h2>
        <div class="ps-period"><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></div>
    </div>

        <div class="ps-parties mb-24">
        <div>
            <h3>Employeur</h3>
            <p><strong><?= e(param('employeur_nom')) ?></strong><br>
            <?= e(param('employeur_rue')) ?><br>
            <?= e(param('employeur_npa')) ?></p>
        </div>
        <div>
            <h3>Employé</h3>
            <p><strong><?php if (empty($impression) && !empty($f['employe_id'])): ?><a href="?p=employe_voir&id=<?= (int) $f['employe_id'] ?>"><?= e($f['employe_nom']) ?></a><?php else: ?><?= e($f['employe_nom']) ?><?php endif; ?></strong><br>
            <?= e($f['employe_rue']) ?><br>
            <?= e($f['employe_npa']) ?><br>
            <?php if ($f['employe_avs']): ?>N° AVS : <?= e($f['employe_avs']) ?><?php endif; ?></p>
        </div>
    </div>

    <table class="ps-table mb-24">
        <thead><tr>
            <th>Salaire</th>
            <?php if ($hasAxe): ?><th>Axe</th><?php endif; ?>
            <th class="num">Détail</th>
            <th class="num">Montant (CHF)</th>
        </tr></thead>
        <tbody>
            <?php if ($uneSeule):
                $l = $lignes[0]; ?>
            <tr><td>Salaire du travail</td>
                <?php if ($hasAxe): ?><?= $axeCellHtml($l) ?><?php endif; ?>
                <td class="num"><?= $hnum((float) $f['nombre_heures']) ?> h × <?= chf((float) $l['taux_horaire']) ?>/h
                    <div class="muted small ps-seuil"><?= e($seuilTxt) ?></div></td>
                <td class="num"><?= chf((float) $f['salaire_travail']) ?></td></tr>
            <?php else: ?>
                <?php foreach ($lignes as $l):
                    $sousH = (float) $l['quantite'] * (float) $l['heures_unite'];
                    $montant = $sousH * (float) $l['taux_horaire']; ?>
                <tr class="ps-sub"><td><?= e($l['libelle']) ?> × <?= $hnum($l['quantite']) ?></td>
                    <?php if ($hasAxe): ?><?= $axeCellHtml($l) ?><?php endif; ?>
                    <td class="num"><?= $hnum($sousH) ?> h × <?= chf((float) $l['taux_horaire']) ?>/h</td>
                    <td class="num"><?= chf($montant) ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Salaire du travail</td>
                    <?php if ($hasAxe): ?><td></td><?php endif; ?>
                    <td class="num"><?= $hnum((float) $f['nombre_heures']) ?> h
                        <div class="muted small ps-seuil"><?= e($seuilTxt) ?></div></td>
                    <td class="num"><?= chf((float) $f['salaire_travail']) ?></td></tr>
            <?php endif; ?>
            <?php if ((float) $f['supplement_montant'] > 0): ?>
            <tr><td>Supplément pour vacances</td>
                <?php if ($hasAxe): ?><td></td><?php endif; ?>
                <td class="num"><?= pct((float) $f['supplement_taux']) ?></td>
                <td class="num"><?= chf((float) $f['supplement_montant']) ?></td></tr>
            <?php endif; ?>
            <tr class="grand-total brut-line"><td>Salaire brut</td>
                <?php if ($hasAxe): ?><td></td><?php endif; ?>
                <td></td>
                <td class="num"><?= chf((float) $f['salaire_brut']) ?> CHF</td></tr>
        </tbody>
    </table>

    <table class="ps-table">
        <thead><tr><th>Déductions</th><th class="num">Taux</th><th class="num">Montant (CHF)</th></tr></thead>
        <tbody>
            <?php foreach ($deductions as [$lib, $tx, $montant, $visible]): ?>
                <?php if (!$visible) continue; ?>
                <tr>
                    <td>
                        <?php if ($lib === 'AVS / AI / APG'): ?>
                            <abbr title="Assurance Vieillesse et Survivants">AVS</abbr> / <abbr title="Assurance Invalidité">AI</abbr> / <abbr title="Allocations pour Perte de Gain">APG</abbr>
                        <?php elseif ($lib === 'AC'): ?>
                            <abbr title="Assurance Chômage">AC</abbr>
                        <?php elseif ($lib === 'Assurance maternité'): ?>
                            <abbr title="Assurance Maternité">A.mat</abbr>
                        <?php elseif ($lib === 'LAA'): ?>
                            <abbr title="Assurance contre les Accidents">LAA</abbr>
                        <?php elseif ($lib === 'LPP'): ?>
                            <abbr title="Prévoyance Professionnelle (2e pilier)">LPP</abbr>
                        <?php elseif ($lib === 'Impôt à la source'): ?>
                            <abbr title="Impôt à la source">Impôt source</abbr>
                        <?php else: ?>
                            <?= e($lib) ?>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $tx !== null ? pct((float) $tx) : '' ?></td>
                    <td class="num">− <?= chf($montant) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total des déductions</td><td></td>
                <td class="num">− <?= chf((float) $f['total_deductions']) ?></td></tr>
        </tbody>
    </table>

    <table class="ps-table net">
        <tbody>
            <tr class="grand-total <?= trim((string) $f['date_paiement']) === '' ? 'apayer' : '' ?>"><td>Salaire net</td><td class="num"><?= chf((float) $f['salaire_net']) ?> CHF</td></tr>
        </tbody>
    </table>
    <p class="ps-paiement">
        <?php if (trim((string) $f['date_paiement']) !== ''): ?>
            ✓ Payé le <?= e(date('d.m.Y', strtotime($f['date_paiement']))) ?>
        <?php else: ?>
            <span class="ps-apayer">À payer</span>
        <?php endif; ?>
    </p>

    <?php
    $chargesEmp = [
        ['AVS / AI / APG', $taux['emp_avs'] ?? null,  (float) ($f['emp_avs'] ?? 0)],
        ['AC',             $taux['emp_ac'] ?? null,   (float) ($f['emp_ac'] ?? 0)],
        ['Assurance maternité', $taux['emp_amat'] ?? null, (float) ($f['emp_amat'] ?? 0)],
        ['Allocations familiales', $taux['emp_af'] ?? null, (float) ($f['emp_af'] ?? 0)],
        ['LAA (accidents prof.)', $taux['emp_laa'] ?? null, (float) ($f['emp_laa'] ?? 0)],
        ['Frais d\'administration', $taux['emp_frais'] ?? null, (float) ($f['emp_frais'] ?? 0)],
        ['CPE',            $taux['emp_cpe'] ?? null,  (float) ($f['emp_cpe'] ?? 0)],
        ['Formation pro. (LFP)', $taux['emp_lfp'] ?? null, (float) ($f['emp_lfp'] ?? 0)],
        ['LPP',            $taux['emp_lpp'] ?? null,  (float) ($f['emp_lpp'] ?? 0)],
    ];
    if ((int) ($f['afficher_cout_emp'] ?? 0) && ((float) ($f['total_charges_emp'] ?? 0)) > 0):
    ?>
    <table class="ps-table">
        <thead><tr><th>Charges patronales (employeur)</th><th class="num">Taux</th><th class="num">Montant (CHF)</th></tr></thead>
        <tbody>
            <?php foreach ($chargesEmp as [$lib, $tx, $montant]): ?>
                <?php if ($montant <= 0) continue; ?>
                <tr>
                    <td>
                        <?php if ($lib === 'AVS / AI / APG'): ?>
                            <abbr title="Assurance Vieillesse et Survivants">AVS</abbr> / <abbr title="Assurance Invalidité">AI</abbr> / <abbr title="Allocations pour Perte de Gain">APG</abbr>
                        <?php elseif ($lib === 'AC'): ?>
                            <abbr title="Assurance Chômage">AC</abbr>
                        <?php elseif ($lib === 'Assurance maternité'): ?>
                            <abbr title="Assurance Maternité">A.mat</abbr>
                        <?php elseif ($lib === 'LAA (accidents prof.)'): ?>
                            <abbr title="Assurance contre les Accidents">LAA</abbr>
                        <?php elseif ($lib === 'LPP'): ?>
                            <abbr title="Prévoyance Professionnelle (2e pilier)">LPP</abbr>
                        <?php else: ?>
                            <?= e($lib) ?>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $tx !== null ? pct((float) $tx) : '' ?></td>
                    <td class="num"><?= chf($montant) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total charges patronales</td><td></td>
                <td class="num"><?= chf((float) $f['total_charges_emp']) ?></td></tr>
            <tr class="total"><td>Coût total employeur (brut + charges)</td><td></td>
                <td class="num"><?= chf((float) $f['cout_total_emp']) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>
