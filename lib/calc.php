<?php
// Logique de calcul d'un décompte de salaire suisse.
// Reprend fidèlement le tableur exemple de l'employeur.

const CANTONS = [
    'Argovie', 'Appenzell Rhodes extérieures', 'Appenzell Rhodes intérieures',
    'Bâle-Campagne', 'Bâle-Ville', 'Berne', 'Fribourg', 'Genève', 'Glaris',
    'Grisons', 'Jura', 'Lucerne', 'Neuchâtel', 'Nidwald', 'Obwald',
    'Saint-Gall', 'Soleure', 'Schaffhouse', 'Schwyz', 'Tessin', 'Thurgovie',
    'Uri', 'Vaud', 'Valais', 'Zoug', 'Zurich',
];

const PROCEDURES = [
    'Ordinaire',
    'Simplifiée',
    'Ordinaire avec impôt à la source',
];

// Suppléments pour vacances usuels (valeur => libellé)
const SUPPLEMENTS_VACANCES = [
    '0'      => 'Aucun',
    '0.0833' => '8.33 % (4 semaines)',
    '0.1064' => '10.64 % (5 semaines)',
    '0.1304' => '13.04 % (6 semaines)',
];

function r2(float $v): float
{
    return round($v, 2);
}

/**
 * Calcule un décompte à partir du salaire du travail (déjà calculé à partir
 * des lignes de prestation : Σ quantité × heures × taux horaire) et de la
 * grille de taux de l'année.
 *
 * @return array montants + taux utilisés
 */
function calculer_fiche(array $emp, float $salaireTravail, array $taux): array
{
    $salaireTravail = r2($salaireTravail);
    $suppMontant    = r2($salaireTravail * (float) $emp['supplement_vacances']);
    $brut           = r2($salaireTravail + $suppMontant);

    $estImpotSource  = ($emp['procedure'] === 'Ordinaire avec impôt à la source');

    $dedAvs   = r2($brut * $taux['avs']);
    $dedAc    = r2($brut * $taux['ac']);
    $dedAmat  = r2($brut * $taux['amat']);
    $dedLaa   = r2($brut * $taux['laa']);
    $dedLpp   = r2($brut * $taux['lpp']);
    $dedImpot = $estImpotSource ? r2($brut * (float) $emp['impot_source_taux']) : 0.0;
    $dedCaf   = 0.0; // CAF non utilisée (cotisation cantonale Valais)

    $totalDed = r2($dedAvs + $dedAc + $dedAmat + $dedLaa + $dedLpp + $dedImpot);
    $net      = r2($brut - $totalDed);

    // --- Charges patronales (employeur) ---
    $empAvs   = r2($brut * ($taux['emp_avs'] ?? 0));
    $empAc    = r2($brut * ($taux['emp_ac'] ?? 0));
    $empAmat  = r2($brut * ($taux['emp_amat'] ?? 0));
    $empAf    = r2($brut * ($taux['emp_af'] ?? 0));
    $empLaa   = r2($brut * ($taux['emp_laa'] ?? 0));
    $empFrais = r2($brut * ($taux['emp_frais'] ?? 0));
    $empCpe   = r2($brut * ($taux['emp_cpe'] ?? 0));
    $empLfp   = r2($brut * ($taux['emp_lfp'] ?? 0));
    $empLpp   = r2($brut * ($taux['emp_lpp'] ?? 0));

    $totalCharges = r2($empAvs + $empAc + $empAmat + $empAf + $empLaa + $empFrais + $empCpe + $empLfp + $empLpp);
    $coutTotal    = r2($brut + $totalCharges);

    return [
        'salaire_travail'    => $salaireTravail,
        'supplement_montant' => $suppMontant,
        'salaire_brut'       => $brut,
        'ded_avs'            => $dedAvs,
        'ded_ac'             => $dedAc,
        'ded_amat'           => $dedAmat,
        'ded_laa'            => $dedLaa,
        'ded_lpp'            => $dedLpp,
        'ded_impot_source'   => $dedImpot,
        'ded_caf'            => $dedCaf,
        'total_deductions'   => $totalDed,
        'salaire_net'        => $net,
        'emp_avs'            => $empAvs,
        'emp_ac'             => $empAc,
        'emp_amat'           => $empAmat,
        'emp_af'             => $empAf,
        'emp_laa'            => $empLaa,
        'emp_frais'          => $empFrais,
        'emp_cpe'            => $empCpe,
        'emp_lfp'            => $empLfp,
        'emp_lpp'            => $empLpp,
        'total_charges_emp'  => $totalCharges,
        'cout_total_emp'     => $coutTotal,
    ];
}

// Seuil d'heures mensuel départageant LAA réduit / plein : jours du mois ÷ 7 × 8.
function seuil_heures(int $annee, int $mois): float
{
    return cal_days_in_month(CAL_GREGORIAN, $mois, $annee) / 7 * 8;
}

// Choisit les taux LAA (employé + employeur) effectifs selon les heures du mois.
// ≤ seuil → taux réduit ; au-dessus → taux plein. Renvoie ['laa'=>, 'emp_laa'=>].
function laa_effectif(array $taux, float $heures, int $annee, int $mois): array
{
    $plein = $heures > seuil_heures($annee, $mois);
    return [
        'laa'     => $plein ? ($taux['laa_plein'] ?? 0)     : ($taux['laa_reduit'] ?? 0),
        'emp_laa' => $plein ? ($taux['emp_laa_plein'] ?? 0) : ($taux['emp_laa_reduit'] ?? 0),
    ];
}

// Taux par défaut (fractions). Clés de stockage utilisées dans taux_par_annee.
// À confirmer chaque année avec l'OCAS et la caisse LPP.
const TAUX_DEFAUT = [
    // Part employé (taux OCAS Genève 2026)
    'taux_avs'         => 0.053,
    'taux_ac'          => 0.011,   // AC 1.10 % (OCAS 2026)
    'taux_amat'        => 0.00029,
    'taux_laa_reduit'  => 0.0053, // LAA employé, mois "court" (≤ seuil d'heures)
    'taux_laa_plein'   => 0.0096, // LAA employé, mois "plein"
    'taux_lpp'         => 0.07,    // LPP employé : 7 % (taux unique)
    // Part employeur
    'emp_taux_avs'        => 0.053,
    'emp_taux_ac'         => 0.011,
    'emp_taux_amat'       => 0.00029,
    'emp_taux_af'         => 0.0222, // allocations familiales GE (OCAS 2026)
    'emp_taux_laa_reduit' => 0.0053,
    'emp_taux_laa_plein'  => 0.0096,
    'emp_taux_frais'      => 0,
    'emp_taux_cpe'        => 0.0007,  // CPE
    'emp_taux_lfp'        => 0.00082, // formation professionnelle (LFP)
    'emp_taux_lpp'        => 0.08,    // LPP employeur : 8 % (taux unique)
];

// Taux bruts stockés pour une année (clé de stockage => fraction).
// Repli : si l'année n'est pas configurée, on reprend la dernière année
// configurée antérieure, sinon les valeurs par défaut.
function taux_stockes(int $annee): array
{
    $rows = [];
    $stmt = db()->prepare('SELECT cle, valeur FROM taux_par_annee WHERE annee = ?');
    $stmt->execute([$annee]);
    foreach ($stmt as $r) {
        $rows[$r['cle']] = (float) $r['valeur'];
    }
    if (!$rows) {
        $st = db()->prepare('SELECT MAX(annee) FROM taux_par_annee WHERE annee <= ?');
        $st->execute([$annee]);
        $prev = $st->fetchColumn();
        if ($prev) {
            $s2 = db()->prepare('SELECT cle, valeur FROM taux_par_annee WHERE annee = ?');
            $s2->execute([$prev]);
            foreach ($s2 as $r) {
                $rows[$r['cle']] = (float) $r['valeur'];
            }
        }
    }
    foreach (TAUX_DEFAUT as $k => $v) {
        if (!array_key_exists($k, $rows)) {
            $rows[$k] = $v;
        }
    }
    return $rows;
}

// Taux d'une année au format attendu par calculer_fiche().
function taux_pour_annee(int $annee): array
{
    $s = taux_stockes($annee);
    return [
        'avs'  => $s['taux_avs'],
        'ac'   => $s['taux_ac'],
        'amat' => $s['taux_amat'],
        // LAA : 2 taux (le taux effectif est choisi via laa_effectif() selon les heures)
        'laa_reduit' => $s['taux_laa_reduit'],
        'laa_plein'  => $s['taux_laa_plein'],
        'lpp'        => $s['taux_lpp'], // LPP : taux unique
        'emp_avs'   => $s['emp_taux_avs'],
        'emp_ac'    => $s['emp_taux_ac'],
        'emp_amat'  => $s['emp_taux_amat'],
        'emp_af'    => $s['emp_taux_af'],
        'emp_laa_reduit' => $s['emp_taux_laa_reduit'],
        'emp_laa_plein'  => $s['emp_taux_laa_plein'],
        'emp_frais' => $s['emp_taux_frais'],
        'emp_cpe'   => $s['emp_taux_cpe'],
        'emp_lfp'   => $s['emp_taux_lfp'],
        'emp_lpp'   => $s['emp_taux_lpp'], // LPP : taux unique
    ];
}
