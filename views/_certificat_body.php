<?php
/** @var array $emp */ /** @var int $annee */ /** @var array $fiches */ /** @var array $tot */
// Certificat de salaire — structure officielle Formulaire 11 (AFC / CSI).
// Correspondance des rubriques avec nos données :
//   1  Salaire (pas de chiffres 2 à 7 dans notre cas) = total brut
//   8  Salaire brut total = chiffre 1
//   9  Cotisations AVS/AI/APG/AC/AANP (+ assurance maternité GE)
//   10.1 Cotisations LPP ordinaires
//   11 Salaire net = 8 − 9 − 10
//   12 Retenue de l'impôt à la source
$c1   = $tot['salaire_brut'];
$c8   = $tot['salaire_brut'];
$c9   = $tot['ded_avs'] + $tot['ded_ac'] + $tot['ded_amat'] + $tot['ded_laa'];
$c10  = $tot['ded_lpp'];
$c11  = $c8 - $c9 - $c10;
$c12  = $tot['ded_impot_source'];

// Période d'engagement déduite des mois présents dans l'année.
$moisList = array_map(fn($f) => (int) $f['mois'], $fiches);
$minM = $moisList ? min($moisList) : 1;
$maxM = $moisList ? max($moisList) : 12;
$debut = sprintf('01.%02d.%d', $minM, $annee);
$finJ  = cal_days_in_month(CAL_GREGORIAN, $maxM, $annee);
$fin   = sprintf('%02d.%02d.%d', $finJ, $maxM, $annee);

// Affiche un montant CHF, ou vide si nul (les rubriques sans valeur restent blanches).
$m = fn($v) => ((float) $v) != 0.0 ? chf((float) $v) : '';
?>
<div class="cert">
    <div class="cert-top">
        <?php $certLogo = $logo_src ?? param_logo('clair'); ?>
        <?php if ($certLogo !== ''): ?><img src="<?= e($certLogo) ?>" alt="" class="cert-logo"><?php else: ?><span></span><?php endif; ?>
        <div class="cert-title">
            <h2>Certificat de salaire</h2>
            <div class="cert-ref">Formulaire 11</div>
        </div>
    </div>

    <?php if (!$fiches): ?>
        <p class="muted">Aucune fiche de salaire pour l'année <?= (int) $annee ?>.</p>
    <?php else: ?>

    <div class="cert-id">
        <div class="cert-id-block">
            <span class="cert-lbl">Employé(e)</span>
            <strong><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></strong><br>
            <?= e($emp['rue']) ?><br>
            <?= e($emp['npa_localite']) ?>
        </div>
        <div class="cert-id-meta">
            <div><span class="cert-lbl">N° AVS</span><?= e($emp['numero_avs']) ?: '—' ?></div>
            <div><span class="cert-lbl">Date de naissance</span><?= trim((string) ($emp['date_naissance'] ?? '')) !== '' ? e(date('d.m.Y', strtotime($emp['date_naissance']))) : '—' ?></div>
            <div><span class="cert-lbl">Année</span><?= (int) $annee ?></div>
            <div><span class="cert-lbl">Période</span>du <?= e($debut) ?> au <?= e($fin) ?></div>
        </div>
    </div>

    <div class="cert-checks">
        <label class="cert-check"><span class="box"></span> F — Transport gratuit entre le domicile et le lieu de travail</label>
        <label class="cert-check"><span class="box"></span> G — Repas à la cantine / chèques-repas</label>
    </div>

    <table class="cert-table">
        <thead>
            <tr><th class="n"></th><th>Rubrique</th><th class="op"></th><th class="amt">CHF</th></tr>
        </thead>
        <tbody>
            <tr><td class="n">1</td><td>Salaire <span class="hint">(ne concernant pas les chiffres 2 à 7)</span></td><td class="op"></td><td class="amt"><?= $m($c1) ?></td></tr>
            <tr><td class="n">2</td><td>Prestations salariales accessoires</td><td class="op"></td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">2.1</td><td>Pension, logement</td><td class="op">+</td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">2.2</td><td>Part privée voiture de service</td><td class="op">+</td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">2.3</td><td>Autres</td><td class="op">+</td><td class="amt"></td></tr>
            <tr><td class="n">3</td><td>Prestations non périodiques</td><td class="op">+</td><td class="amt"></td></tr>
            <tr><td class="n">4</td><td>Prestations en capital</td><td class="op">+</td><td class="amt"></td></tr>
            <tr><td class="n">5</td><td>Droits de participation de collaborateur</td><td class="op">+</td><td class="amt"></td></tr>
            <tr><td class="n">6</td><td>Indemnités des membres de l'administration</td><td class="op">+</td><td class="amt"></td></tr>
            <tr><td class="n">7</td><td>Autres prestations</td><td class="op">+</td><td class="amt"></td></tr>
            <tr class="tot"><td class="n">8</td><td>Salaire brut total</td><td class="op">=</td><td class="amt"><?= $m($c8) ?></td></tr>
            <tr><td class="n">9</td><td>Cotisations <abbr title="Assurance Vieillesse et Survivants">AVS</abbr>/<abbr title="Assurance Invalidité">AI</abbr>/<abbr title="Allocations pour Perte de Gain">APG</abbr>/<abbr title="Assurance Chômage">AC</abbr>/<abbr title="Assurance contre les Accidents Non Professionnels">AANP</abbr></td><td class="op">−</td><td class="amt"><?= $m($c9) ?></td></tr>
            <tr><td class="n">10</td><td>Cotisations de prévoyance professionnelle (<abbr title="2e pilier">LPP</abbr>)</td><td class="op"></td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">10.1</td><td>Cotisations ordinaires</td><td class="op">−</td><td class="amt"><?= $m($c10) ?></td></tr>
            <tr class="sub"><td class="n">10.2</td><td>Cotisations pour le rachat</td><td class="op">−</td><td class="amt"></td></tr>
            <tr class="tot"><td class="n">11</td><td>Salaire net <span class="hint">— à reporter sur la déclaration d'impôt</span></td><td class="op">=</td><td class="amt"><?= $m($c11) ?></td></tr>
            <tr><td class="n">12</td><td>Retenue de l'impôt à la source</td><td class="op"><?= $c12 != 0.0 ? '−' : '' ?></td><td class="amt"><?= $m($c12) ?></td></tr>
            <tr><td class="n">13</td><td>Indemnités pour frais</td><td class="op"></td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">13.1</td><td>Frais effectifs (voyage, repas, nuitées, autres)</td><td class="op"></td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">13.2</td><td>Frais forfaitaires (représentation, voiture, autres)</td><td class="op"></td><td class="amt"></td></tr>
            <tr class="sub"><td class="n">13.3</td><td>Contributions au perfectionnement</td><td class="op"></td><td class="amt"></td></tr>
            <tr><td class="n">14</td><td>Autres prestations salariales accessoires</td><td class="op"></td><td class="amt"></td></tr>
            <tr><td class="n">15</td><td>Observations</td><td class="op"></td><td class="amt"></td></tr>
        </tbody>
    </table>

    <p class="cert-note">
        Certificat établi sur la base des <?= count($fiches) ?> fiche<?= count($fiches) > 1 ? 's' : '' ?> de salaire de l'année <?= (int) $annee ?>.
        Le chiffre 9 regroupe les cotisations sociales obligatoires retenues sur le salaire
        (AVS/AI/APG, AC, AANP et assurance maternité cantonale).
    </p>

    <div class="cert-foot">
        <div class="cert-sign">
            <span class="cert-lbl">Certifié exact et complet — l'employeur</span>
            <strong><?= e(param('employeur_nom')) ?></strong><br>
            <?= e(param('employeur_rue')) ?>, <?= e(param('employeur_npa')) ?>
        </div>
        <div class="cert-sign">
            <span class="cert-lbl">Lieu et date</span>
            <span class="cert-line"></span>
        </div>
        <div class="cert-sign">
            <span class="cert-lbl">Signature</span>
            <span class="cert-line"></span>
        </div>
    </div>
    <?php endif; ?>
</div>
