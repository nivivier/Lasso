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
 * Les lignes ne sont plus en dur : chaque poste actif (postes_salariaux)
 * produit un montant, rangé sous sa colonne (« avs » → ded_avs). Les totaux
 * sont la somme des montants ARRONDIS, poste par poste — règle inchangée.
 *
 * $taux : les taux de l'année, déjà résolus par le contexte de la fiche
 * (laa_effectif() pour le seuil d'heures, bareme_age_effectif() pour l'âge).
 *
 * @return array montants + taux utilisés
 */
function calculer_fiche(array $emp, float $salaireTravail, array $taux, ?array $postes = null): array
{
    $salaireTravail = r2($salaireTravail);
    $suppMontant    = r2($salaireTravail * (float) $emp['supplement_vacances']);
    $brut           = r2($salaireTravail + $suppMontant);
    $coordonne      = salaire_coordonne($brut, $taux);

    // Colonnes historiques remises à zéro d'abord : un poste désactivé doit
    // laisser un 0 dans la fiche, pas la valeur d'une autre.
    $res = array_fill_keys(COLONNES_POSTES, 0.0);
    $res['ded_caf'] = 0.0; // CAF non utilisée (cotisation cantonale Valais)

    $totalDed = $totalCharges = 0.0;
    foreach ($postes ?? postes_actifs() as $p) {
        $montant = poste_montant($p, $brut, $coordonne, $taux, $emp);
        $res[poste_colonne_montant((string) $p['code'])] = $montant;
        if ((string) $p['sens'] === 'charge') {
            $totalCharges += $montant;
        } else {
            $totalDed += $montant;
        }
    }
    $totalDed     = r2($totalDed);
    $totalCharges = r2($totalCharges);

    return $res + [
        'salaire_travail'    => $salaireTravail,
        'supplement_montant' => $suppMontant,
        'salaire_brut'       => $brut,
        'salaire_coordonne'  => $coordonne,
        'total_deductions'   => $totalDed,
        'salaire_net'        => r2($brut - $totalDed),
        'total_charges_emp'  => $totalCharges,
        'cout_total_emp'     => r2($brut + $totalCharges),
    ];
}

// Colonnes de montant que la table fiches possède en propre. Un poste ajouté
// par l'employeur n'en a pas : il ne vit que dans fiche_postes, ce qui suffit à
// l'affichage, au certificat et à la comptabilité.
const COLONNES_POSTES = [
    'ded_avs', 'ded_ac', 'ded_amat', 'ded_laa', 'ded_lpp', 'ded_impot_source', 'ded_caf',
    'emp_avs', 'emp_ac', 'emp_amat', 'emp_af', 'emp_laa',
    'emp_frais', 'emp_cpe', 'emp_lfp', 'emp_lpp',
];

// Colonnes réellement présentes sur la table fiches. calculer_fiche() rend des
// clés qui n'en sont pas toutes : le salaire coordonné (calcul intermédiaire) et
// surtout les postes ajoutés par l'employeur, qui n'ont pas de colonne à eux et
// ne vivent que dans fiche_postes. Sans ce filtre, ajouter une ligne rendait
// l'enregistrement d'une fiche impossible (« table fiches has no column named
// ded_… »).
function fiches_colonnes(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("PRAGMA table_info('fiches')") as $col) {
            $cache[(string) $col['name']] = true;
        }
    }
    return $cache;
}

// Montants d'un calcul restreints à ce que la table fiches sait stocker.
function fiche_montants_stockables(array $montants): array
{
    return array_intersect_key($montants, fiches_colonnes());
}

// Montant d'un poste. « mode » n'est pas un langage de formules : trois
// comportements connus, parce qu'une ligne de salaire suisse n'est jamais une
// expression arbitraire.
function poste_montant(array $p, float $brut, float $coordonne, array $taux, array $emp): float
{
    $base = (string) $p['base'] === 'coordonne' ? $coordonne : $brut;
    $code = (string) $p['code'];
    if ((string) $p['mode'] === 'taux_employe') {
        // L'impôt à la source : le taux vit sur l'employé, et seule la
        // procédure correspondante le prélève.
        return $emp['procedure'] === 'Ordinaire avec impôt à la source'
            ? r2($base * (float) $emp['impot_source_taux'])
            : 0.0;
    }
    // taux, laa_seuil, bareme_age : le taux effectif est résolu en amont
    // (laa_effectif(), bareme_age_effectif()) et rangé sous le code du poste.
    return r2($base * (float) ($taux[$code] ?? 0));
}

// Salaire coordonné : brut moins la déduction de coordination, plafonné.
// Les deux réglages sont ANNUELS (comme les publie la LPP) et ramenés au mois.
// À 0 — le réglage de départ — ils ne font rien : le coordonné vaut le brut,
// et une base « coordonne » se comporte exactement comme le brut.
function salaire_coordonne(float $brut, array $taux): float
{
    $c = $brut - (float) ($taux['coord_deduction'] ?? 0) / 12;
    $plafond = (float) ($taux['coord_plafond'] ?? 0) / 12;
    if ($plafond > 0 && $c > $plafond) {
        $c = $plafond;
    }
    return r2(max(0.0, $c));
}

// Postes actifs, dans l'ordre d'affichage. Mémorisé le temps de la requête :
// un recalcul de masse rappelle calculer_fiche() des dizaines de fois.
function postes_actifs(bool $recharger = false): array
{
    static $cache = null;
    if ($recharger) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = db()->query('SELECT * FROM postes_salariaux WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
    }
    return $cache;
}

// Ordre complet de la liste avec un poste décalé d'un cran, au format attendu
// par la route (« 3,1,2 »). Sert au repli sans JavaScript des boutons monter /
// descendre : le glisser-déposer les masque, mais ils restent le chemin quand
// le navigateur n'exécute rien.
function postes_ordre_deplace(array $postes, int $id, int $sens): string
{
    $ids = array_map(fn ($p) => (int) $p['id'], $postes);
    $pos = array_search($id, $ids, true);
    $vers = $pos === false ? -1 : $pos + $sens;
    if ($pos !== false && $vers >= 0 && $vers < count($ids)) {
        [$ids[$pos], $ids[$vers]] = [$ids[$vers], $ids[$pos]];
    }
    return implode(',', $ids);
}

// À appeler après toute modification des postes OU de leurs taux : les deux
// mémorisations ci-dessus survivraient sinon à l'enregistrement, dans la même
// requête.
function postes_oublier(): void
{
    postes_actifs(true);
    taux_pour_annee(0, true);
}

// --- Postes salariaux : les lignes d'un décompte -----------------------------
//
// Une fiche FIGE ses lignes (fiche_postes) à l'enregistrement : libellé, taux et
// montant. C'est cette copie que lisent l'affichage, le certificat et la
// comptabilité — jamais postes_salariaux, qui ne sert qu'à calculer les fiches
// à venir. Renommer ou désactiver un poste ne réécrit donc aucune fiche passée.

// Correspondance entre le code d'un poste et la colonne de montant de la fiche.
// Les codes ont été choisis pour que ce soit direct : « avs » → ded_avs,
// « emp_avs » → emp_avs.
function poste_colonne_montant(string $code): string
{
    return str_starts_with($code, 'emp_') ? $code : 'ded_' . $code;
}

// Lignes figées d'une fiche, dans l'ordre d'affichage. Tableau vide pour une
// fiche qui n'aurait pas (encore) sa copie — l'appelant retombe alors sur les
// colonnes, ce qui garde l'écran fonctionnel en toute circonstance.
function fiche_postes_lire(int $ficheId): array
{
    $stmt = db()->prepare('SELECT * FROM fiche_postes WHERE fiche_id = ? ORDER BY ordre, id');
    $stmt->execute([$ficheId]);
    return $stmt->fetchAll();
}

// Proratise les lignes FIGÉES d'un lot de fiches selon un ratio par fiche
// (utilisé par la ventilation analytique : ratio = part de la fiche portée par
// l'axe). Renvoie :
//   'montants'  [colonne => total non arrondi]  — colonne = ded_x / emp_x,
//   'groupes'   [groupe_compta => [colonnes]]   — regroupements déclarés par les
//               postes, pour qu'un poste ajouté alimente le bon total sans
//               qu'on touche au code comptable,
//   'couvertes' [fiche_id => true]              — les fiches qui ont une copie
//               figée ; pour les autres, l'appelant reste sur les colonnes.
function fiches_postes_prorata(array $ratios): array
{
    $ids = array_keys($ratios);
    if (!$ids) {
        return ['montants' => [], 'groupes' => [], 'couvertes' => []];
    }
    $stmt = db()->prepare(
        'SELECT fiche_id, code, groupe_compta, montant
           FROM fiche_postes WHERE fiche_id IN (' . sql_in($ids) . ')'
    );
    $stmt->execute($ids);

    $montants = $groupes = $couvertes = [];
    foreach ($stmt as $l) {
        $fiche = (int) $l['fiche_id'];
        $col   = poste_colonne_montant((string) $l['code']);
        $couvertes[$fiche] = true;
        $montants[$col]    = ($montants[$col] ?? 0.0) + (float) $l['montant'] * ($ratios[$fiche] ?? 0.0);
        $groupe = (string) $l['groupe_compta'];
        if ($groupe !== '') {
            $groupes[$groupe][$col] = true;
        }
    }
    foreach ($groupes as &$cols) { $cols = array_keys($cols); }
    unset($cols);
    return ['montants' => $montants, 'groupes' => $groupes, 'couvertes' => $couvertes];
}

// Lignes figées d'un LOT de fiches, groupées par fiche, projetées sur ce dont
// la comptabilité a besoin : [fiche_id => [['colonne', 'montant', 'groupe'], …]].
// Une seule requête, quel que soit le nombre de fiches.
function fiches_postes_par_fiche(array $ficheIds): array
{
    $ids = array_values(array_unique(array_filter($ficheIds)));
    if (!$ids) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT fiche_id, code, groupe_compta, montant
           FROM fiche_postes WHERE fiche_id IN (' . sql_in($ids) . ')'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt as $l) {
        $out[(int) $l['fiche_id']][] = [
            'colonne' => poste_colonne_montant((string) $l['code']),
            'montant' => (float) $l['montant'],
            'groupe'  => (string) $l['groupe_compta'],
        ];
    }
    return $out;
}

// Écrit la copie figée depuis un résultat de calculer_fiche(). À appeler dans
// la MÊME transaction que l'écriture de la fiche : les deux doivent avancer
// ensemble, ou pas du tout.
//
// $taux : les taux effectivement appliqués (ceux qui partent dans taux_json).
function fiche_postes_ecrire(int $ficheId, array $montants, array $taux): void
{
    db()->prepare('DELETE FROM fiche_postes WHERE fiche_id = ?')->execute([$ficheId]);
    $ins = db()->prepare(
        'INSERT INTO fiche_postes
            (fiche_id, poste_id, code, libelle, sens, rubrique_certificat, groupe_compta,
             masquer_si_zero, taux, montant, ordre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach (db()->query('SELECT * FROM postes_salariaux WHERE actif = 1 ORDER BY ordre, id') as $p) {
        $code = (string) $p['code'];
        $colonne = poste_colonne_montant($code);
        if (!array_key_exists($colonne, $montants)) {
            continue; // poste sans montant calculé : rien à figer
        }
        $ins->execute([
            $ficheId, (int) $p['id'], $code, (string) $p['libelle'], (string) $p['sens'],
            (string) $p['rubrique_certificat'], (string) $p['groupe_compta'],
            (int) $p['masquer_si_zero'], $taux[$code] ?? null,
            (float) $montants[$colonne], (int) $p['ordre'],
        ]);
    }
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

// --- Barèmes d'âge (LPP) -----------------------------------------------------
//
// L'âge de référence est un choix de l'employeur, parce que les caisses ne le
// prennent pas toutes au même moment :
//   'annee'       âge atteint dans l'année = année de la fiche − année de
//                 naissance (la convention LPP la plus répandue) — défaut ;
//   'anniversaire' âge révolu au mois de la fiche.
function age_pour_bareme(array $emp, int $annee, int $mois): ?int
{
    $naissance = trim((string) ($emp['date_naissance'] ?? ''));
    if ($naissance === '') {
        return null;
    }
    $ts = strtotime($naissance);
    if ($ts === false) {
        return null;
    }
    if (param('lpp_age_reference', 'annee') === 'anniversaire') {
        $fin = mktime(0, 0, 0, $mois, (int) date('t', mktime(0, 0, 0, $mois, 1, $annee)), $annee);
        return (int) date_diff(date_create('@' . $ts), date_create('@' . $fin))->y;
    }
    return $annee - (int) date('Y', $ts);
}

// Taux effectifs des postes au barème d'âge, rangés sous le code du poste —
// exactement comme laa_effectif() le fait pour le seuil d'heures. Un poste sans
// palier correspondant (ou un employé sans date de naissance) donne 0 : mieux
// vaut une ligne à zéro, visible, qu'un taux deviné.
function bareme_age_effectif(array $emp, int $annee, int $mois, ?array $postes = null): array
{
    $out = [];
    foreach ($postes ?? postes_actifs() as $p) {
        if ((string) $p['mode'] !== 'bareme_age') {
            continue;
        }
        $age = age_pour_bareme($emp, $annee, $mois);
        $out[(string) $p['code']] = $age === null ? 0.0 : bareme_age_taux((int) $p['id'], $annee, $age);
    }
    return $out;
}

// Palier applicable, pour l'année demandée ou à défaut la dernière année
// configurée avant elle (même repli que les taux).
function bareme_age_taux(int $posteId, int $annee, int $age): float
{
    $stmt = db()->prepare(
        'SELECT valeur FROM poste_bareme_age
          WHERE poste_id = ? AND ? BETWEEN age_min AND age_max
            AND annee = (SELECT MAX(annee) FROM poste_bareme_age WHERE poste_id = ? AND annee <= ?)
          ORDER BY age_min LIMIT 1'
    );
    $stmt->execute([$posteId, $age, $posteId, $annee]);
    $v = $stmt->fetchColumn();
    return $v === false ? 0.0 : (float) $v;
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

// Taux de toutes les années configurées + repli par défaut, au format attendu
// par le port JS de calculer_fiche() (aperçu « Coûts estimés » en direct dans
// fiche_form.php) — même logique de repli que taux_stockes() (année antérieure
// la plus récente), reproduite côté client sur ce petit jeu de données.
function taux_pour_annee_js(): array
{
    $annees = array_map('intval', db()->query('SELECT DISTINCT annee FROM taux_par_annee ORDER BY annee')->fetchAll(PDO::FETCH_COLUMN));
    $parAnnee = [];
    foreach ($annees as $an) {
        $parAnnee[$an] = taux_pour_annee($an);
    }
    // Les postes voyagent avec les taux : l'aperçu applique la même grille que
    // le serveur, sans dupliquer la liste des lignes côté JS.
    $postes = array_map(fn ($p) => [
        'code' => (string) $p['code'],
        'sens' => (string) $p['sens'],
        'mode' => (string) $p['mode'],
        'base' => (string) $p['base'],
    ], postes_actifs());
    return ['parAnnee' => $parAnnee, 'defaut' => taux_pour_annee(0), 'postes' => $postes,
            'bareme' => baremes_age_js(), 'ageRef' => param('lpp_age_reference', 'annee')];
}

// Paliers d'âge par poste et par année, pour l'aperçu en direct :
// [code => [annee => [[age_min, age_max, valeur], …]]].
function baremes_age_js(): array
{
    $out = [];
    $sql = 'SELECT p.code, b.annee, b.age_min, b.age_max, b.valeur
              FROM poste_bareme_age b JOIN postes_salariaux p ON p.id = b.poste_id
             WHERE p.actif = 1 ORDER BY b.annee, b.age_min';
    foreach (db()->query($sql) as $r) {
        $out[(string) $r['code']][(int) $r['annee']][] =
            [(int) $r['age_min'], (int) $r['age_max'], (float) $r['valeur']];
    }
    return $out;
}

// Taux d'une année au format attendu par calculer_fiche().
//
// Deux sources, dans cet ordre : poste_taux (ce que règle l'employeur depuis
// « Postes salariaux ») fait foi ; taux_par_annee, puis TAUX_DEFAUT, ne servent
// plus que de repli pour une année ou un poste jamais configuré là. La bascule
// s'est faite sans rien changer aux montants : migration_80 a recopié
// taux_par_annee dans poste_taux.
function taux_pour_annee(int $annee, bool $recharger = false): array
{
    // Mémoïsé : un écran de recalcul rappelle cette fonction une fois par
    // fiche, avec la même année, pour un résultat identique. postes_oublier()
    // vide ce cache en même temps que celui des postes.
    static $cache = [];
    if ($recharger) {
        $cache = [];
        return [];
    }
    if (isset($cache[$annee])) {
        return $cache[$annee];
    }
    $s = taux_stockes($annee);
    $t = [
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
    // Réglages du salaire coordonné (montants ANNUELS, 0 = sans effet).
    $t['coord_deduction'] = (float) ($s['coord_deduction'] ?? 0);
    $t['coord_plafond']   = (float) ($s['coord_plafond'] ?? 0);
    return $cache[$annee] = array_merge($t, postes_taux_annee($annee));
}

// Taux des postes pour une année, depuis poste_taux : la valeur de l'année
// demandée, sinon celle de la dernière année configurée avant elle. Un poste au
// mode laa_seuil rend deux clés (« laa_reduit », « laa_plein »), comme attendu
// par laa_effectif().
function postes_taux_annee(int $annee): array
{
    $stmt = db()->prepare(
        'SELECT p.code, p.mode, t.valeur, t.valeur_alt
           FROM postes_salariaux p
           JOIN poste_taux t ON t.poste_id = p.id
          WHERE p.actif = 1
            AND t.annee = (SELECT MAX(annee) FROM poste_taux
                            WHERE poste_id = p.id AND annee <= ?)'
    );
    $stmt->execute([$annee]);
    $out = [];
    foreach ($stmt as $r) {
        $code = (string) $r['code'];
        if ((string) $r['mode'] === 'laa_seuil') {
            $out[$code . '_reduit'] = (float) $r['valeur'];
            $out[$code . '_plein']  = (float) ($r['valeur_alt'] ?? $r['valeur']);
        } else {
            $out[$code] = (float) $r['valeur'];
        }
    }
    return $out;
}
