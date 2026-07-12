<?php
// Handlers de routes du module comptabilité (préfixe « compta_ »).
// Inclus depuis index.php après lib/routes.php. S'appuie sur lib/compta.php.

require_once __DIR__ . '/compta.php';

// ----------------------------------------------------------- Helpers internes
// Plan comptable indexé par id (pour l'agrégation et l'affichage des libellés).
function compta_plan_map(): array
{
    $map = [];
    foreach (db()->query('SELECT * FROM plan_comptes ORDER BY ordre, id') as $r) {
        $map[(int) $r['id']] = $r;
    }
    return $map;
}

// Plan comptable actif uniquement (pour les listes déroulantes).
function compta_plan_actif(): array
{
    $map = [];
    foreach (db()->query('SELECT * FROM plan_comptes WHERE actif = 1 ORDER BY ordre, id') as $r) {
        $map[(int) $r['id']] = $r;
    }
    return $map;
}

// Recalcule le sens (produit/charge) de chaque catégorie d'après celui de sa
// racine, pour garder l'arbre cohérent après un déplacement/rattachement.
function compta_normaliser_sens(): void
{
    $plan = compta_plan_map();
    $byParent = plan_enfants($plan);
    $upd = db()->prepare('UPDATE plan_comptes SET sens = ? WHERE id = ?');
    $appliquer = function (int $pid, string $sens) use (&$appliquer, $byParent, $upd) {
        foreach ($byParent[$pid] ?? [] as $child) {
            $cid = (int) $child['id'];
            if ($child['sens'] !== $sens) {
                $upd->execute([$sens, $cid]);
            }
            $appliquer($cid, $sens);
        }
    };
    foreach ($byParent[0] ?? [] as $root) {
        $appliquer((int) $root['id'], (string) $root['sens']);
    }
}

// Ids de tous les descendants d'une catégorie (pour empêcher les cycles).
function compta_descendants(int $id, array $plan): array
{
    $byParent = plan_enfants($plan);
    $out = [];
    $walk = function (int $pid) use (&$walk, &$out, $byParent) {
        foreach ($byParent[$pid] ?? [] as $child) {
            $out[] = (int) $child['id'];
            $walk((int) $child['id']);
        }
    };
    $walk($id);
    return $out;
}

// Liste des comptes bancaires.
function compta_comptes(): array
{
    return db()->query('SELECT * FROM comptes_bancaires ORDER BY ordre, libelle')->fetchAll();
}

// Années présentes dans les écritures, décroissant.
function compta_annees(): array
{
    $annees = array_map('intval', db()->query("SELECT DISTINCT substr(date_op,1,4) AS y FROM ecritures")->fetchAll(PDO::FETCH_COLUMN));
    if (!$annees) {
        $annees = [(int) date('Y')];
    }
    rsort($annees);
    return $annees;
}

// Applique les règles actives aux écritures non figées manuellement.
// $compteId / $annee : filtres optionnels. Renvoie le nombre d'écritures lettrées.
function compta_lettrer_par_regles(?int $compteId, ?int $annee): int
{
    $sql = "SELECT id, compte_bancaire_id, texte, montant FROM ecritures WHERE origine_lettrage NOT IN ('manuel', 'ignore')";
    $params = [];
    if ($compteId) {
        $sql .= ' AND compte_bancaire_id = ?';
        $params[] = $compteId;
    }
    if ($annee) {
        $sql .= " AND substr(date_op,1,4) = ?";
        $params[] = (string) $annee;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ecritures = $stmt->fetchAll();

    $regles = charger_conditions_regles(db()->query('SELECT * FROM regles_lettrage WHERE actif = 1')->fetchAll());
    $affect = appliquer_regles($regles, $ecritures);
    if (!$affect) {
        return 0;
    }
    $upd = db()->prepare("UPDATE ecritures SET plan_compte_id = ?, origine_lettrage = 'regle' WHERE id = ?");
    db()->beginTransaction();
    foreach ($affect as $ecrId => $planId) {
        $upd->execute([$planId, $ecrId]);
    }
    db()->commit();
    return count($affect);
}

// Agrège les charges sociales proratisées pour les fiches ayant des lignes sur cet axe.
// Ratio = montant des lignes de cette fiche sur cet axe / salaire_travail total de la fiche.
function charges_sociales_axe(int $axeId, int $annee): array
{
    $stmt = db()->prepare(
        'SELECT f.salaire_travail,
                f.ded_avs, f.ded_ac, f.ded_amat, f.ded_laa, f.ded_lpp, f.ded_impot_source,
                f.total_deductions, f.salaire_brut, f.salaire_net,
                f.emp_avs, f.emp_ac, f.emp_amat, f.emp_af, f.emp_laa,
                f.emp_frais, f.emp_cpe, f.emp_lfp, f.emp_lpp,
                f.total_charges_emp, f.cout_total_emp,
                SUM(fl.quantite * fl.heures_unite * fl.taux_horaire) AS montant_axe
         FROM fiches f
         JOIN fiche_lignes fl ON fl.fiche_id = f.id AND fl.axe_analytique_id = ?
         WHERE f.annee = ?
         GROUP BY f.id'
    );
    $stmt->execute([$axeId, $annee]);

    $champs = ['salaire_brut', 'salaire_net', 'total_deductions',
               'ded_avs', 'ded_ac', 'ded_amat', 'ded_laa', 'ded_lpp', 'ded_impot_source',
               'emp_avs', 'emp_ac', 'emp_amat', 'emp_af', 'emp_laa',
               'emp_frais', 'emp_cpe', 'emp_lfp', 'emp_lpp',
               'total_charges_emp', 'cout_total_emp'];
    $tot = array_fill_keys($champs, 0.0);

    foreach ($stmt as $r) {
        $st = (float) $r['salaire_travail'];
        $ratio = $st > 0 ? (float) $r['montant_axe'] / $st : 0.0;
        foreach ($champs as $c) {
            $tot[$c] += (float) $r[$c] * $ratio;
        }
    }
    foreach ($tot as &$v) { $v = round($v, 2); }
    unset($v);
    // Regroupement OCAS patronale (AVS+AC+Amat+AF) calculé après arrondi des composantes.
    $tot['emp_ocas'] = round($tot['emp_avs'] + $tot['emp_ac'] + $tot['emp_amat'] + $tot['emp_af'], 2);
    return $tot;
}

// Agrège les charges sociales proratisées PAR AXE sur une période (mois/année de début → fin).
// Retourne [axe_id => [code, libelle, ded_avs, ..., emp_ocas, ...]].
function charges_sociales_par_axe(int $aD, int $mD, int $aF, int $mF): array
{
    $stmt = db()->prepare(
        'SELECT a.id AS axe_id, a.code AS axe_code, a.libelle AS axe_libelle,
                f.salaire_travail,
                f.ded_avs, f.ded_ac, f.ded_amat, f.ded_laa, f.ded_lpp,
                f.emp_avs, f.emp_ac, f.emp_amat, f.emp_af, f.emp_laa, f.emp_lpp,
                SUM(fl.quantite * fl.heures_unite * fl.taux_horaire) AS montant_axe
         FROM fiches f
         JOIN fiche_lignes fl ON fl.fiche_id = f.id AND fl.axe_analytique_id IS NOT NULL
         JOIN axes_analytiques a ON a.id = fl.axe_analytique_id
         WHERE (f.annee * 12 + f.mois) BETWEEN (? * 12 + ?) AND (? * 12 + ?)
         GROUP BY f.id, a.id'
    );
    $stmt->execute([$aD, $mD, $aF, $mF]);

    $champs = ['ded_avs', 'ded_ac', 'ded_amat', 'ded_laa', 'ded_lpp',
               'emp_avs', 'emp_ac', 'emp_amat', 'emp_af', 'emp_laa', 'emp_lpp'];
    $cumul = [];
    foreach ($stmt as $r) {
        $id = (int) $r['axe_id'];
        if (!isset($cumul[$id])) {
            $cumul[$id] = ['code' => (string) $r['axe_code'], 'libelle' => (string) $r['axe_libelle']]
                        + array_fill_keys($champs, 0.0);
        }
        $st    = (float) $r['salaire_travail'];
        $ratio = $st > 0 ? (float) $r['montant_axe'] / $st : 0.0;
        foreach ($champs as $c) { $cumul[$id][$c] += (float) $r[$c] * $ratio; }
    }
    foreach ($cumul as &$c) {
        foreach ($champs as $f2) { $c[$f2] = round($c[$f2], 2); }
        $c['emp_ocas'] = round($c['emp_avs'] + $c['emp_ac'] + $c['emp_amat'] + $c['emp_af'], 2);
    }
    unset($c);
    return $cumul;
}

// Détecte le type de charge sociale depuis le libellé/groupe du plan comptable.
// Retourne 'ocas', 'laa', 'lpp' ou '' si non identifié.
// Les mots-clés explicites ont priorité : la caisse LAA et LPP peuvent fournir les deux
// types (LAA et LPP), donc les noms d'entreprises seuls sont ambigus.
function detecter_type_charge(string $libelle, string $groupe = ''): string
{
    $h = mb_strtolower($libelle . ' ' . $groupe, 'UTF-8');
    if (str_contains($h, 'ocas') || str_contains($h, 'avs') || str_contains($h, 'social')) return 'ocas';
    if (str_contains($h, 'laa') || str_contains($h, 'accident')) return 'laa';
    if (str_contains($h, 'lpp') || str_contains($h, 'prévoy') || str_contains($h, 'prevoy')) return 'lpp';
    return '';
}

// Calcule le montant à ventiler pour un axe selon le type de charge (employé + employeur).
function montant_axe_pour_type(array $c, string $type): float
{
    return match ($type) {
        'ocas' => $c['ded_avs'] + $c['ded_ac'] + $c['ded_amat'] + $c['emp_ocas'],
        'laa'  => $c['ded_laa'] + $c['emp_laa'],
        'lpp'  => $c['ded_lpp'] + $c['emp_lpp'],
        default => 0.0,
    };
}

// Déduit la période de référence par défaut depuis la date d'un versement de charges.
function periode_defaut_charges(string $dateOp): array
{
    $mois  = (int) date('n', strtotime($dateOp));
    $annee = (int) date('Y', strtotime($dateOp));
    if ($mois <= 3)  return [$annee - 1, 1, $annee - 1, 12]; // Jan–Mar → année préc.
    if ($mois <= 6)  return [$annee, 1, $annee, 3];           // Avr–Jun → T1
    if ($mois <= 9)  return [$annee, 1, $annee, 6];           // Jul–Sep → S1
    return [$annee, 1, $annee, 9];                             // Oct–Déc → 9 mois
}

// ------------------------------------------------------------------- ROUTES
function route_compta(): void
{
    require_login();
    redirect('compta_ecritures');
}

// --- Plan comptable (catégories) -------------------------------------------
function route_compta_plan(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            $libelle = trim($_POST['libelle'] ?? '');
            $parent  = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $plan = compta_plan_map();
            if ($libelle !== '') {
                // Sens : hérité du parent si rattachée, sinon choisi (racine).
                $sens = $parent !== null && isset($plan[$parent])
                    ? (string) $plan[$parent]['sens']
                    : (($_POST['sens'] ?? 'charge') === 'produit' ? 'produit' : 'charge');
                $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM plan_comptes')->fetchColumn();
                db()->prepare('INSERT INTO plan_comptes (libelle, sens, parent_id, ordre) VALUES (?, ?, ?, ?)')
                    ->execute([$libelle, $sens, $parent, $ordre]);
            }
        } elseif ($section === 'edit') {
            // Renommage + rattachement éventuel à un autre parent (sans cycle).
            $id      = (int) ($_POST['id'] ?? 0);
            $libelle = trim($_POST['libelle'] ?? '');
            $parent  = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $plan = compta_plan_map();
            if ($libelle !== '' && isset($plan[$id])) {
                $interdits = array_merge([$id], compta_descendants($id, $plan));
                if ($parent !== null && (in_array($parent, $interdits, true) || !isset($plan[$parent]))) {
                    $parent = plan_pid($plan[$id]['parent_id'] ?? null) ?: null; // rattachement invalide → inchangé
                }
                db()->prepare('UPDATE plan_comptes SET libelle = ?, parent_id = ? WHERE id = ?')
                    ->execute([$libelle, $parent, $id]);
                compta_normaliser_sens();
            }
        } elseif ($section === 'move') {
            // Déplace une catégorie d'un cran (haut/bas) parmi ses frères.
            $id  = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $plan = compta_plan_map();
            if (isset($plan[$id])) {
                $pidParent = plan_pid($plan[$id]['parent_id'] ?? null);
                $freres = plan_enfants($plan)[$pidParent] ?? [];
                $ids = array_map(fn($r) => (int) $r['id'], $freres);
                $pos = array_search($id, $ids, true);
                $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    $upd = db()->prepare('UPDATE plan_comptes SET ordre = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($ids as $i => $cid) {
                        $upd->execute([$i, $cid]);
                    }
                    db()->commit();
                }
            }
            redirect('compta_plan');
        } elseif ($section === 'reorder') {
            // Glisser-déposer : rattache une catégorie à $parent (vide = racine) et
            // renumérote les frères selon l'ordre fourni ($order = ids, déplacée incluse).
            $id     = (int) ($_POST['id'] ?? 0);
            $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $order  = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            $plan = compta_plan_map();
            if (isset($plan[$id]) && $order) {
                $interdits = array_merge([$id], compta_descendants($id, $plan));
                $okParent = $parent === null
                    || (isset($plan[$parent]) && !in_array($parent, $interdits, true) && $plan[$parent]['sens'] === $plan[$id]['sens']);
                if ($okParent) {
                    db()->beginTransaction();
                    db()->prepare('UPDATE plan_comptes SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
                    $upd = db()->prepare('UPDATE plan_comptes SET ordre = ? WHERE id = ?');
                    $i = 0;
                    foreach ($order as $cid) {
                        // Ne renumérote que la catégorie déplacée et les frères du parent.
                        if ($cid === $id || (isset($plan[$cid]) && plan_pid($plan[$cid]['parent_id'] ?? null) === plan_pid($parent))) {
                            $upd->execute([$i++, $cid]);
                        }
                    }
                    db()->commit();
                    compta_normaliser_sens();
                }
            }
            redirect('compta_plan');
        } elseif ($section === 'archive') {
            // Archive / réactive une catégorie feuille (masquée du lettrage si archivée).
            $id = (int) ($_POST['id'] ?? 0);
            $plan = compta_plan_map();
            if (isset($plan[$id]) && plan_est_feuille($id, $plan)) {
                db()->prepare('UPDATE plan_comptes SET actif = 1 - actif WHERE id = ?')->execute([$id]);
            } else {
                redirect('compta_plan', ['err' => 'children']);
            }
            redirect('compta_plan', ['ok' => 1]);
        } elseif ($section === 'del') {
            // Suppression d'une catégorie feuille. Si elle porte des écritures lettrées,
            // $ecritures indique quoi en faire : 'delettrer' ou 'reaffecter' (→ $cible).
            $id = (int) ($_POST['id'] ?? 0);
            $plan = compta_plan_map();
            if (!isset($plan[$id]) || !plan_est_feuille($id, $plan)) {
                redirect('compta_plan', ['err' => 'children']); // groupe → on refuse
            }
            $nb = db()->prepare('SELECT COUNT(*) FROM ecritures WHERE plan_compte_id = ?');
            $nb->execute([$id]);
            $aEcritures = (int) $nb->fetchColumn() > 0;
            $mode  = $_POST['ecritures'] ?? '';
            $cible = (int) ($_POST['cible'] ?? 0);
            db()->beginTransaction();
            if ($aEcritures) {
                if ($mode === 'reaffecter' && $cible !== $id && isset($plan[$cible]) && plan_est_feuille($cible, $plan)) {
                    db()->prepare('UPDATE ecritures SET plan_compte_id = ? WHERE plan_compte_id = ?')->execute([$cible, $id]);
                    db()->prepare('UPDATE regles_lettrage SET plan_compte_id = ? WHERE plan_compte_id = ?')->execute([$cible, $id]);
                } else {
                    // Par défaut (ou choix « supprimer le lettrage ») : on délettre.
                    db()->prepare("UPDATE ecritures SET plan_compte_id = NULL, origine_lettrage = '' WHERE plan_compte_id = ?")->execute([$id]);
                }
            }
            // Les règles restantes pointant sur la catégorie sont supprimées (ON DELETE CASCADE).
            db()->prepare('DELETE FROM plan_comptes WHERE id = ?')->execute([$id]);
            db()->commit();
            redirect('compta_plan', ['ok' => 1]);
        }
        redirect('compta_plan');
    }
    $plan = compta_plan_map();
    $ecrCounts = [];
    foreach (db()->query('SELECT plan_compte_id, COUNT(*) n FROM ecritures WHERE plan_compte_id IS NOT NULL GROUP BY plan_compte_id') as $r) {
        $ecrCounts[(int) $r['plan_compte_id']] = (int) $r['n'];
    }
    render('compta_plan', [
        'lignes'    => plan_liste_ordonnee($plan),
        'plan'      => $plan,
        'feuilles'  => plan_feuilles($plan),
        'ecrCounts' => $ecrCounts,
        'saved'     => isset($_GET['ok']),
        'flagErr'   => $_GET['err'] ?? null,
    ], 'Comptabilité — Plan comptable');
}

// --- Comptes bancaires ------------------------------------------------------
function route_compta_comptes(): void
{
    require_login();
    $err = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add' || $section === 'edit') {
            $libelle      = trim($_POST['libelle'] ?? '');
            $iban         = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
            $soldeInitial = (float) str_replace(["'", ' '], '', $_POST['solde_initial'] ?? '0');
            if ($libelle === '') {
                $err = 'Le libellé est obligatoire.';
            } else {
                try {
                    if ($section === 'edit') {
                        db()->prepare('UPDATE comptes_bancaires SET libelle=?, iban=?, solde_initial=? WHERE id=?')
                            ->execute([$libelle, $iban, $soldeInitial, (int) ($_POST['id'] ?? 0)]);
                    } else {
                        $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM comptes_bancaires')->fetchColumn();
                        db()->prepare('INSERT INTO comptes_bancaires (libelle, iban, solde_initial, ordre) VALUES (?, ?, ?, ?)')
                            ->execute([$libelle, $iban, $soldeInitial, $ordre]);
                    }
                    redirect('compta_comptes', ['ok' => 1]);
                } catch (PDOException $ex) {
                    $err = str_contains($ex->getMessage(), 'UNIQUE')
                        ? 'Cet IBAN est déjà associé à un autre compte.' : 'Enregistrement impossible.';
                }
            }
        } elseif ($section === 'del') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT COUNT(*) FROM ecritures WHERE compte_bancaire_id = ?');
            $stmt->execute([$id]);
            $stmtF = db()->prepare('SELECT COUNT(*) FROM factures WHERE compte_bancaire_id = ?');
            $stmtF->execute([$id]);
            if ((int) $stmt->fetchColumn() === 0 && (int) $stmtF->fetchColumn() === 0) {
                db()->prepare('DELETE FROM comptes_bancaires WHERE id = ?')->execute([$id]);
                redirect('compta_comptes', ['ok' => 1]);
            }
            redirect('compta_comptes', ['err' => 'used']);
        }
    }
    // Page partagée entre Comptabilité et Facturation (voir index.php) : le
    // lien de retour pointe vers le module d'où l'on peut raisonnablement
    // venir, pas vers Comptes annuels si Comptabilité est désactivée.
    $retour = module_actif('compta')
        ? ['href' => '?p=compta_bilan', 'label' => 'Comptes annuels']
        : ['href' => '?p=facturation_liste', 'label' => 'Facturation'];

    render('compta_comptes', [
        'comptes' => compta_comptes(),
        'err'     => $err,
        'saved'   => isset($_GET['ok']),
        'flagErr' => $_GET['err'] ?? null,
        'retour'  => $retour,
    ], 'Comptabilité — Comptes bancaires');
}

// Traite un fichier d'export bancaire téléversé (CSV PostFinance) : parse,
// retrouve/crée le compte par IBAN, insère les écritures (dédoublonnées),
// applique les règles de lettrage, tente le rapprochement facturation.
// Ne fait ni redirect ni render — juste ['ok'|'err', message]. Partagée entre
// route_compta_import() (Comptabilité → Importer) et route_import_ecritures()
// (Paramètres → Importer), pour ne pas dupliquer cette logique.
// Détecte le format d'un export bancaire (extension + contenu, l'un ou
// l'autre pouvant manquer/mentir) et le parse en conséquence — CSV PostFinance
// ou XML camt.053, même format de sortie dans les deux cas (voir parse_postfinance_csv()).
function compta_parser_export_bancaire(string $contenu, string $nomFichier): array
{
    // La détection par contenu ne regarde que le tout début du fichier — un
    // CSV PostFinance dont une ligne de texte contiendrait par hasard la
    // sous-chaîne « <Document » (ex. un mémo de virement) ne doit pas être
    // mal aiguillé vers le parseur XML.
    $debut = ltrim(substr($contenu, 0, 500));
    $estXml = str_ends_with(strtolower($nomFichier), '.xml')
        || str_starts_with($debut, '<?xml')
        || str_starts_with($debut, '<Document');
    return $estXml ? parse_camt053($contenu) : parse_postfinance_csv($contenu);
}

// Simule ou applique un import d'écritures déjà lu en mémoire. En simulation,
// aucune écriture en base (lecture seule via compta_previsualiser_import()) —
// permet de nommer un compte inconnu avant sa création réelle.
function compta_traiter_fichier_importe(string $contenu, string $nomFichier, bool $simule, string $nomCompteChoisi = ''): array
{
    $vide = ['err' => null, 'simule' => $simule, 'preview' => null, 'ok' => null];
    $parse = compta_parser_export_bancaire($contenu, $nomFichier);
    if ($parse['iban'] === '') {
        return ['err' => "IBAN introuvable dans le fichier : format non reconnu (CSV PostFinance ou XML camt.053 attendu)."] + $vide;
    }
    if (!$parse['lignes']) {
        return ['err' => 'Aucune écriture trouvée dans ce fichier.'] + $vide;
    }

    if ($simule) {
        return ['preview' => compta_previsualiser_import($parse)] + $vide;
    }

    $stmt = db()->prepare('SELECT * FROM comptes_bancaires WHERE iban = ?');
    $stmt->execute([$parse['iban']]);
    $compte = $stmt->fetch();
    $compteCree = false;
    // Compte inconnu → création à partir du nom choisi (ou d'un nom par défaut).
    if (!$compte) {
        $nom = trim($nomCompteChoisi) !== '' ? trim($nomCompteChoisi) : ('Compte PostFinance ' . substr($parse['iban'], -4));
        $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM comptes_bancaires')->fetchColumn();
        db()->prepare('INSERT INTO comptes_bancaires (libelle, iban, ordre) VALUES (?, ?, ?)')
            ->execute([$nom, $parse['iban'], $ordre]);
        $stmt->execute([$parse['iban']]);
        $compte = $stmt->fetch();
        $compteCree = true;
    }
    [$ins, $dup, $importId] = compta_inserer_ecritures($compte, $parse, $nomFichier);
    compta_lettrer_par_regles((int) $compte['id'], null);
    if (module_actif('facturation')) {
        facturation_suggerer_rapprochements(db(), $importId);
    }
    $prefixe = $compteCree
        ? "Compte « " . $compte['libelle'] . " » créé (IBAN " . $parse['iban'] . "). "
        : "Import « " . $compte['libelle'] . " » : ";
    return ['ok' => $prefixe . "$ins écriture(s) ajoutée(s), $dup doublon(s) ignoré(s)."] + $vide;
}

// Résout la simulation ou l'application d'un import d'écritures depuis
// $_POST/$_FILES : fichier fraîchement téléversé, ou contenu mémorisé en
// session après une simulation (bouton « Importer réellement », sans
// re-téléversement). Partagé par route_compta_import() et route_import_ecritures().
function compta_import_ecritures_requete(): array
{
    $simule = !isset($_POST['appliquer']);
    $vide = ['err' => null, 'simule' => $simule, 'preview' => null, 'ok' => null];

    $contenu = null;
    $nomFichier = '';
    $up = $_FILES['fichier'] ?? null;
    if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (($up['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['err' => 'Fichier trop volumineux (max 5 Mo).'] + $vide;
        }
        $contenu = (string) file_get_contents($up['tmp_name']);
        $nomFichier = (string) $up['name'];
    } elseif (!empty($_POST['depuis_session']) && !empty($_SESSION['import_ecritures_csv'])) {
        $contenu = (string) $_SESSION['import_ecritures_csv'];
        $nomFichier = (string) ($_SESSION['import_ecritures_nom'] ?? 'import');
    } else {
        return ['err' => 'Veuillez choisir un fichier à importer (CSV PostFinance ou XML camt.053).'] + $vide;
    }

    $res = compta_traiter_fichier_importe($contenu, $nomFichier, $simule, trim($_POST['nom_compte'] ?? ''));

    if ($simule && $res['err'] === null) {
        $_SESSION['import_ecritures_csv'] = $contenu;
        $_SESSION['import_ecritures_nom'] = $nomFichier;
    } else {
        unset($_SESSION['import_ecritures_csv'], $_SESSION['import_ecritures_nom']);
    }
    return $res;
}

// --- Import d'un export PostFinance -----------------------------------------
function route_compta_import(): void
{
    require_login();
    $msg = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if ($section = $_POST['section'] ?? '') {
            if ($section === 'del') {
                // Supprime un import et toutes ses écritures.
                db()->prepare('DELETE FROM ecritures WHERE import_id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                db()->prepare('DELETE FROM imports WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect('compta_import', ['ok' => 'del']);
            }
        }
        $msg = compta_import_ecritures_requete();
    }
    render('compta_import', [
        'comptes' => compta_comptes(),
        'imports' => db()->query('SELECT i.*, c.libelle AS compte_libelle,
                                  (SELECT COUNT(*) FROM ecritures e WHERE e.import_id = i.id) AS nb_actuelles,
                                  (SELECT COUNT(*) FROM ecritures e WHERE e.import_id = i.id AND e.plan_compte_id IS NOT NULL) AS nb_lettrees
                                  FROM imports i JOIN comptes_bancaires c ON c.id = i.compte_bancaire_id
                                  ORDER BY i.importe_le DESC, i.id DESC')->fetchAll(),
        'msg'     => $msg,
        'okDel'   => ($_GET['ok'] ?? '') === 'del',
    ], 'Comptabilité — Importer');
}

// Import d'écritures depuis Paramètres → Importer (même traitement que
// route_compta_import(), sans l'historique des imports/suppression — dupliqué
// pour l'instant comme point d'entrée, mais le code reste factorisé).
function route_import_ecritures(): void
{
    require_login();
    $msg = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $msg = compta_import_ecritures_requete();
    }
    render('import_fiches', [
        'errFiches' => null, 'resultatsFiches' => null, 'resumeFiches' => null, 'simuleFiches' => true,
        'errFactures' => null, 'resultatsFactures' => null, 'resumeFactures' => null, 'simuleFactures' => true,
        'msgEcritures' => $msg,
        'errEvenements' => null, 'resultatsEvenements' => null, 'resumeEvenements' => null, 'simuleEvenements' => true,
    ], 'Importer');
}

// --- Lettrage (écran principal) --------------------------------------------
function route_compta_ecritures(): void
{
    require_login();
    $comptes = compta_comptes();

    // Filtres : GET prioritaire, sinon dernière valeur en session, sinon défaut.
    $compteId = (int) filtre_persistant('compte', 'ecr_compte', 0);
    $annees   = compta_annees();
    $annee    = (int) filtre_persistant('annee', 'ecr_annee', $annees[0] ?? date('Y'));
    $categorieFilter = filtre_persistant('categorie', 'ecr_categorie', '');
    $axeFilter        = filtre_persistant('axe', 'ecr_axe', '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        $retour  = ['compte' => $compteId, 'annee' => $annee, 'categorie' => $categorieFilter, 'axe' => $axeFilter];
        if ($section === 'bulk_undo') {
            $r = bulk_undo_appliquer();
            redirect($r['route'] ?? 'compta_ecritures', ($r['retour'] ?? $retour) + ($r ? ['ok' => 'annule'] : []));
        }
        if ($section === 'create' || $section === 'update') {
            $cid     = (int) ($_POST['compte_bancaire_id'] ?? 0);
            $date_op = trim($_POST['date_op'] ?? '');
            $texte   = trim($_POST['texte'] ?? '');
            $montant = (float) str_replace(["'", "\u{202F}", ' '], '', $_POST['montant'] ?? '0');
            $planRaw = $_POST['plan_compte_id'] ?? '';
            if ($planRaw === 'ignore') {
                $planId = null;
                $origLettrage = 'ignore';
            } else {
                $planId  = ($planRaw !== '' && $planRaw !== '0') ? (int) $planRaw : null;
                $origLettrage = $planId !== null ? 'manuel' : '';
            }
            if ($cid && $date_op && $texte) {
                if ($section === 'create') {
                    $axeRaw = (int) ($_POST['axe_analytique_id'] ?? 0);
                    $hash = sha1('manual-' . uniqid('', true) . mt_rand());
                    db()->prepare('INSERT INTO ecritures (compte_bancaire_id, date_op, texte, montant, plan_compte_id, origine_lettrage, hash) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$cid, $date_op, $texte, $montant, $planId, $origLettrage, $hash]);
                    if ($axeRaw) {
                        $newId = (int) db()->lastInsertId();
                        compta_save_ventilations($newId, [['axe_id' => $axeRaw, 'montant' => $montant]]);
                    }
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    db()->prepare('UPDATE ecritures SET compte_bancaire_id=?, date_op=?, texte=?, montant=?, plan_compte_id=?, origine_lettrage=? WHERE id=? AND import_id IS NULL')
                        ->execute([$cid, $date_op, $texte, $montant, $planId, $origLettrage, $id]);
                    // Ventilations gérées exclusivement via le panneau multi-axe (compta_ventilation_save).
                }
            }
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'delete_manual') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM ecritures_ventilations WHERE ecriture_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ecritures WHERE id=? AND import_id IS NULL')->execute([$id]);
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'lettrer') {
            // Affectation manuelle (une ou plusieurs écritures) à une catégorie.
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $planId = $_POST['plan_compte_id'] ?? '';
            $ids = array_values(array_filter($ids));
            if ($ids) {
                unset($_SESSION['bulk_undo']); // évite de reprendre par erreur un état d'une requête précédente
                if ($planId === '' || $planId === '0') {
                    // Délettrage : remet à NULL (annule aussi un « Ne pas lettrer »).
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    bulk_undo_memoriser('ecritures', $ids, ['plan_compte_id', 'origine_lettrage'], 'compta_ecritures', $retour);
                    db()->prepare("UPDATE ecritures SET plan_compte_id = NULL, origine_lettrage = '' WHERE id IN ($in)")
                        ->execute($ids);
                } elseif ($planId === 'ignore') {
                    // Marquage « Ne pas lettrer » : sans catégorie, mais exclue des non-lettrées.
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    bulk_undo_memoriser('ecritures', $ids, ['plan_compte_id', 'origine_lettrage'], 'compta_ecritures', $retour);
                    db()->prepare("UPDATE ecritures SET plan_compte_id = NULL, origine_lettrage = 'ignore' WHERE id IN ($in)")
                        ->execute($ids);
                } elseif (plan_est_feuille((int) $planId, compta_plan_map())) {
                    // Seules les catégories feuilles (sans enfant) sont assignables.
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    bulk_undo_memoriser('ecritures', $ids, ['plan_compte_id', 'origine_lettrage'], 'compta_ecritures', $retour);
                    $stmt = db()->prepare("UPDATE ecritures SET plan_compte_id = ?, origine_lettrage = 'manuel' WHERE id IN ($in)");
                    $stmt->execute(array_merge([(int) $planId], $ids));
                }
                if (isset($_SESSION['bulk_undo'])) {
                    $retour['bulk'] = count($ids);
                }
            }
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'axer') {
            // Affectation d'un axe analytique (une ou plusieurs écritures) — remplace toutes
            // les ventilations existantes par une seule avec le montant total de l'écriture.
            $ids   = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
            $axeId = (int) ($_POST['axe_analytique_id'] ?? 0);
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("DELETE FROM ecritures_ventilations WHERE ecriture_id IN ($in)")->execute($ids);
                if ($axeId) {
                    $stmtMt  = db()->prepare('SELECT id, montant FROM ecritures WHERE id = ?');
                    $stmtIns = db()->prepare('INSERT INTO ecritures_ventilations (ecriture_id, axe_id, montant) VALUES (?, ?, ?)');
                    db()->beginTransaction();
                    foreach ($ids as $eid) {
                        $stmtMt->execute([$eid]);
                        $ecr = $stmtMt->fetch();
                        if ($ecr) $stmtIns->execute([$eid, $axeId, (float) $ecr['montant']]);
                    }
                    db()->commit();
                }
            }
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'apply_rules') {
            // Toujours tous comptes + toutes années : les règles sont globales.
            $n = compta_lettrer_par_regles(null, null);
            redirect('compta_ecritures', $retour + ['rules' => $n]);
        }
        redirect('compta_ecritures', $retour);
    }

    // Écriture manuelle à éditer (mode ?edit=ID).
    $editEcr = null;
    if (isset($_GET['edit'])) {
        $stmt2 = db()->prepare('SELECT * FROM ecritures WHERE id=? AND import_id IS NULL');
        $stmt2->execute([(int) $_GET['edit']]);
        $editEcr = $stmt2->fetch() ?: null;
    }

    // Construction de la requête filtrée.
    $sql = 'SELECT e.*, p.libelle AS cat_libelle, cb.libelle AS compte_libelle FROM ecritures e
            LEFT JOIN plan_comptes p ON p.id = e.plan_compte_id
            JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id WHERE 1=1';
    $params = [];
    if ($compteId) {
        $sql .= ' AND e.compte_bancaire_id = ?';
        $params[] = $compteId;
    }
    if ($annee) {
        $sql .= ' AND substr(e.date_op,1,4) = ?';
        $params[] = (string) $annee;
    }
    if ($categorieFilter === 'a_lettrer') {
        $sql .= " AND e.plan_compte_id IS NULL AND e.origine_lettrage <> 'ignore'";
    } elseif ($categorieFilter === 'ignore') {
        $sql .= " AND e.origine_lettrage = 'ignore'";
    } elseif (ctype_digit((string) $categorieFilter) && $categorieFilter !== '') {
        // Catégorie choisie : si feuille → cette catégorie ; si sur-catégorie
        // (parent) → toutes les écritures de son sous-arbre.
        $ids = plan_descendants((int) $categorieFilter, plan_enfants(compta_plan_actif()));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND e.plan_compte_id IN ($in)";
        $params = array_merge($params, array_map('intval', $ids));
    }
    if (module_actif('analytique') && ctype_digit((string) $axeFilter) && $axeFilter !== '') {
        $sql .= ' AND EXISTS (SELECT 1 FROM ecritures_ventilations ev WHERE ev.ecriture_id = e.id AND ev.axe_id = ?)';
        $params[] = (int) $axeFilter;
    } elseif (module_actif('analytique') && $axeFilter === 'sans_axe') {
        $sql .= ' AND e.plan_compte_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ecritures_ventilations ev WHERE ev.ecriture_id = e.id)';
    }
    $sql .= ' ORDER BY e.date_op DESC, e.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ecritures = $stmt->fetchAll();

    // Ventilations par écriture (chargées en une seule requête supplémentaire).
    $ventilationsParEcr = [];
    if ($ecritures && module_actif('analytique')) {
        $ecrIds = array_column($ecritures, 'id');
        $inPlh  = implode(',', array_fill(0, count($ecrIds), '?'));
        $stmtV  = db()->prepare(
            "SELECT ev.ecriture_id, ev.axe_id, ev.montant, a.libelle, a.code
             FROM ecritures_ventilations ev JOIN axes_analytiques a ON a.id = ev.axe_id
             WHERE ev.ecriture_id IN ($inPlh) ORDER BY ev.id"
        );
        $stmtV->execute($ecrIds);
        foreach ($stmtV as $v) {
            $ventilationsParEcr[(int) $v['ecriture_id']][] = $v;
        }
    }

    $feuilles = plan_feuilles(compta_plan_actif());
    // Arbre complet (parents + feuilles) pour le filtre par catégorie / sur-catégorie.
    $categoriesArbre = plan_liste_ordonnee(compta_plan_actif());
    $axes     = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];
    render('compta_ecritures', [
        'comptes'            => $comptes,
        'compteId'           => $compteId,
        'annee'              => $annee,
        'annees'             => $annees,
        'categorieFilter'    => $categorieFilter,
        'categoriesArbre'    => $categoriesArbre,
        'axeFilter'          => $axeFilter,
        'ecritures'          => $ecritures,
        'ventilationsParEcr' => $ventilationsParEcr,
        'feuilles'           => $feuilles,
        'axes'               => $axes,
        'rules'              => $_GET['rules'] ?? null,
        'editEcr'            => $editEcr,
        'openNew'         => isset($_GET['new']),
        'bulkCount'       => isset($_GET['bulk']) ? (int) $_GET['bulk'] : null,
        'okAnnule'        => ($_GET['ok'] ?? '') === 'annule',
    ], 'Comptabilité — Lettrage');
}

// --- Règles de lettrage -----------------------------------------------------
function route_compta_regles(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';

        // Parse les conditions soumises par le builder UI.
        $parseConditions = function (): array {
            $types    = $_POST['cond_type']        ?? [];
            $ops      = $_POST['cond_op']           ?? [];
            $opsNum   = $_POST['cond_op_num']       ?? [];
            $valTexte = $_POST['cond_valeur_text']  ?? [];
            $valSens  = $_POST['cond_valeur_sens']  ?? [];
            $valNum   = $_POST['cond_valeur_num']   ?? [];
            $conds = [];
            foreach ($types as $i => $type) {
                $type = in_array($type, ['texte', 'sens', 'montant'], true) ? $type : 'texte';
                [$valeur, $op] = match ($type) {
                    'sens'    => [in_array($valSens[$i] ?? '', ['credit', 'debit'], true) ? ($valSens[$i] ?? 'credit') : 'credit', '='],
                    'montant' => [
                        trim((string) ($valNum[$i] ?? '')),
                        in_array($opsNum[$i] ?? '', ['>=', '<=', '='], true) ? $opsNum[$i] : '>=',
                    ],
                    default   => [
                        trim((string) ($valTexte[$i] ?? '')),
                        in_array($ops[$i] ?? '', ['contient', 'commence', 'exact'], true) ? $ops[$i] : 'contient',
                    ],
                };
                if ($type === 'sens' || $valeur !== '') {
                    $conds[] = compact('type', 'op', 'valeur');
                }
            }
            return $conds;
        };

        if ($section === 'add' || $section === 'edit') {
            $planId    = (int) ($_POST['plan_compte_id'] ?? 0);
            $cid       = ($_POST['compte_bancaire_id'] ?? '') === '' ? null : (int) $_POST['compte_bancaire_id'];
            $operateur = in_array($_POST['operateur'] ?? '', ['ET', 'OU'], true) ? $_POST['operateur'] : 'ET';
            $actif     = isset($_POST['actif']) ? 1 : 0;
            $conditions = $parseConditions();

            if ($planId > 0 && plan_est_feuille($planId, compta_plan_map())) {
                db()->beginTransaction();
                if ($section === 'edit') {
                    $id = (int) ($_POST['id'] ?? 0);
                    db()->prepare('UPDATE regles_lettrage SET compte_bancaire_id=?, plan_compte_id=?, operateur=?, actif=? WHERE id=?')
                        ->execute([$cid, $planId, $operateur, $actif, $id]);
                    db()->prepare('DELETE FROM conditions_lettrage WHERE regle_id = ?')->execute([$id]);
                } else {
                    $maxPrio = (int) db()->query('SELECT COALESCE(MAX(priorite),0) FROM regles_lettrage')->fetchColumn();
                    db()->prepare('INSERT INTO regles_lettrage (compte_bancaire_id, plan_compte_id, operateur, priorite, actif) VALUES (?, ?, ?, ?, 1)')
                        ->execute([$cid, $planId, $operateur, $maxPrio + 10]);
                    $id = (int) db()->lastInsertId();
                }
                $insC = db()->prepare('INSERT INTO conditions_lettrage (regle_id, type, op, valeur, ordre) VALUES (?, ?, ?, ?, ?)');
                foreach ($conditions as $ord => $cond) {
                    $insC->execute([$id, $cond['type'], $cond['op'], $cond['valeur'], $ord]);
                }
                db()->commit();
            }
        } elseif ($section === 'test') {
            $cid       = ($_POST['compte_bancaire_id'] ?? '') === '' ? null : (int) $_POST['compte_bancaire_id'];
            $operateur = in_array($_POST['operateur'] ?? '', ['ET', 'OU'], true) ? $_POST['operateur'] : 'ET';
            $conditions = $parseConditions();
            $regle = ['compte_bancaire_id' => $cid, 'operateur' => $operateur, 'conditions' => $conditions];
            $n = compter_impact_regle($regle, compta_ecritures_non_lettrees());
            header('Content-Type: application/json');
            echo json_encode(['n' => $n]);
            exit;
        } elseif ($section === 'del') {
            db()->prepare('DELETE FROM regles_lettrage WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        } elseif ($section === 'move_up' || $section === 'move_down') {
            $id  = (int) ($_POST['id'] ?? 0);
            $all = db()->query('SELECT id FROM regles_lettrage ORDER BY priorite ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
            $pos = (int) array_search($id, $all, true);
            if ($id > 0 && $pos !== false) {
                $swap = $section === 'move_up' ? $pos - 1 : $pos + 1;
                if ($swap >= 0 && $swap < count($all)) {
                    $upd = db()->prepare('UPDATE regles_lettrage SET priorite = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($all as $i => $rid) { $upd->execute([$i * 10, (int) $rid]); }
                    $upd->execute([$swap * 10, $id]);
                    $upd->execute([$pos  * 10, (int) $all[$swap]]);
                    db()->commit();
                }
            }
        }
        redirect('compta_regles', ['ok' => 1]);
    }
    $regles = db()->query('SELECT r.*, p.libelle AS cat_libelle, c.libelle AS compte_libelle
                           FROM regles_lettrage r
                           JOIN plan_comptes p ON p.id = r.plan_compte_id
                           LEFT JOIN comptes_bancaires c ON c.id = r.compte_bancaire_id
                           ORDER BY r.priorite, r.id')->fetchAll();
    $regles = charger_conditions_regles($regles);
    $nonLettrees = compta_ecritures_non_lettrees();
    $impacts = [];
    foreach ($regles as $r) {
        $impacts[(int) $r['id']] = (int) $r['actif'] === 1 ? compter_impact_regle($r, $nonLettrees) : 0;
    }
    // Prefill pour "Créer une règle depuis cette écriture" (lien depuis lettrage).
    $prefillMotif  = trim($_GET['motif'] ?? '');
    $prefillCompte = isset($_GET['compte']) ? (int) $_GET['compte'] : null;
    render('compta_regles', [
        'regles'        => $regles,
        'impacts'       => $impacts,
        'feuilles'      => plan_feuilles(compta_plan_actif()),
        'comptes'       => compta_comptes(),
        'prefillMotif'  => $prefillMotif,
        'prefillCompte' => $prefillCompte,
        'saved'         => isset($_GET['ok']),
        'test'          => isset($_GET['test']) ? (int) $_GET['test'] : null,
    ], 'Comptabilité — Règles');
}

// Écritures non lettrées (id, compte, texte, montant) — pour l'aperçu d'impact.
function compta_ecritures_non_lettrees(): array
{
    return db()->query("SELECT id, compte_bancaire_id, texte, montant FROM ecritures WHERE plan_compte_id IS NULL AND origine_lettrage <> 'ignore'")->fetchAll();
}

// Combien d'écritures (parmi $ecritures) la règle toucherait (scope compte inclus).
function compter_impact_regle(array $regle, array $ecritures): int
{
    $cid = $regle['compte_bancaire_id'] ?? null;
    $n = 0;
    foreach ($ecritures as $e) {
        if ($cid !== null && (int) $cid !== (int) $e['compte_bancaire_id']) {
            continue;
        }
        if (regle_match($regle, $e)) {
            $n++;
        }
    }
    return $n;
}

// --- Axes analytiques -------------------------------------------------------
function route_compta_axes(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'create') {
            $libelle = trim($_POST['libelle'] ?? '');
            $code    = trim($_POST['code'] ?? '');
            if ($libelle !== '') {
                $max = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) FROM axes_analytiques')->fetchColumn();
                db()->prepare('INSERT INTO axes_analytiques (libelle, code, ordre) VALUES (?,?,?)')
                    ->execute([$libelle, $code, $max + 10]);
            }
        } elseif ($section === 'update') {
            $id   = (int) ($_POST['id'] ?? 0);
            $lib  = trim($_POST['libelle'] ?? '');
            $code = trim($_POST['code'] ?? '');
            if ($id && $lib !== '') {
                // actif non inclus dans ce formulaire (géré séparément par toggle_actif).
                db()->prepare('UPDATE axes_analytiques SET libelle=?, code=? WHERE id=?')
                    ->execute([$lib, $code, $id]);
            }
        } elseif ($section === 'toggle_actif') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                db()->prepare('UPDATE axes_analytiques SET actif = 1 - actif WHERE id = ?')->execute([$id]);
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                db()->prepare('DELETE FROM ecritures_ventilations WHERE axe_id = ?')->execute([$id]);
                db()->prepare('DELETE FROM axes_analytiques WHERE id = ?')->execute([$id]);
            }
        } elseif ($section === 'move_up' || $section === 'move_down') {
            $id  = (int) ($_POST['id'] ?? 0);
            $all = db()->query('SELECT id FROM axes_analytiques ORDER BY ordre ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
            $pos = array_search($id, $all, true);
            if ($id > 0 && $pos !== false) {
                $swap = $section === 'move_up' ? $pos - 1 : $pos + 1;
                if ($swap >= 0 && $swap < count($all)) {
                    $upd = db()->prepare('UPDATE axes_analytiques SET ordre = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($all as $i => $rid) { $upd->execute([$i * 10, (int) $rid]); }
                    $upd->execute([$swap * 10, $id]);
                    $upd->execute([$pos  * 10, (int) $all[$swap]]);
                    db()->commit();
                }
            }
        }
        redirect('compta_axes', ['ok' => 1]);
    }
    render('compta_axes', [
        'axes'  => db()->query('SELECT * FROM axes_analytiques ORDER BY ordre, id')->fetchAll(),
        'saved' => isset($_GET['ok']),
    ], 'Comptabilité — Axes analytiques');
}

// Ventilation du compte de résultat par axe analytique pour une année.
function calculer_ventilation_analytique(int $annee, array $plan): array
{
    $axes = db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
    if (!$axes) return [];
    // $annee === 0 = toutes les années
    if ($annee) {
        $stmt = db()->prepare(
            'SELECT e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL AND substr(e.date_op,1,4) = ?
             GROUP BY e.plan_compte_id'
        );
    } else {
        $stmt = db()->prepare(
            'SELECT e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL
             GROUP BY e.plan_compte_id'
        );
    }
    $rows = [];
    foreach ($axes as $axe) {
        $params = [(int) $axe['id']];
        if ($annee) $params[] = (string) $annee;
        $stmt->execute($params);
        $produits = 0.0;
        $charges  = 0.0;
        foreach ($stmt as $r) {
            if (($plan[(int) $r['plan_compte_id']]['sens'] ?? 'charge') === 'produit') {
                $produits += (float) $r['s'];
            } else {
                $charges += (float) $r['s'];
            }
        }
        $rows[] = [
            'id'       => (int) $axe['id'],
            'libelle'  => $axe['libelle'],
            'code'     => $axe['code'],
            'actif'    => (int) $axe['actif'],
            'produits' => $produits,
            'charges'  => $charges,
            'resultat' => $produits + $charges,
        ];
    }
    return $rows;
}

// --- Analyse analytique ----------------------------------------------------
function compta_analyse_data(?int $annee): array
{
    $annees = compta_annees();
    // null = pas de paramètre → année la plus récente ; 0 = toutes les années
    if ($annee === null) $annee = (int) ($annees[0] ?? date('Y'));
    $plan  = compta_plan_map();
    $axes  = db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
    $ventilation = calculer_ventilation_analytique($annee, $plan);

    // Détail par axe : pour chaque axe, toutes les écritures groupées par catégorie.
    // ev.montant = part ventilée sur cet axe (peut différer de e.montant si multi-axe).
    if ($annee) {
        $stmtLig = db()->prepare(
            'SELECT e.plan_compte_id, e.date_op, e.texte, ev.montant, cb.libelle AS compte
             FROM ecritures_ventilations ev
             JOIN ecritures e ON e.id = ev.ecriture_id
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL AND substr(e.date_op,1,4) = ?
             ORDER BY e.date_op ASC, e.id ASC'
        );
    } else {
        $stmtLig = db()->prepare(
            'SELECT e.plan_compte_id, e.date_op, e.texte, ev.montant, cb.libelle AS compte
             FROM ecritures_ventilations ev
             JOIN ecritures e ON e.id = ev.ecriture_id
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL
             ORDER BY e.date_op ASC, e.id ASC'
        );
    }
    $detailParAxe = [];
    foreach ($axes as $axe) {
        $aid = (int) $axe['id'];
        $params = [$aid];
        if ($annee) $params[] = (string) $annee;
        $stmtLig->execute($params);
        $catMap = [];
        foreach ($stmtLig as $r) {
            $pid = (int) $r['plan_compte_id'];
            $pc  = $plan[$pid] ?? null;
            if (!$pc) continue;
            if (!isset($catMap[$pid])) {
                $catMap[$pid] = ['libelle' => $pc['libelle'], 'sens' => $pc['sens'], 'montant' => 0.0, 'lignes' => []];
            }
            $catMap[$pid]['montant'] += (float) $r['montant'];
            $catMap[$pid]['lignes'][] = $r;
        }
        uasort($catMap, fn($a, $b) =>
            ($b['sens'] === 'produit') <=> ($a['sens'] === 'produit') ?: strcmp($a['libelle'], $b['libelle'])
        );
        $detailParAxe[$aid] = $catMap;
    }

    // Totaux des écritures lettrées sans axe.
    $sqlNv = 'SELECT e.plan_compte_id, SUM(e.montant) s FROM ecritures e
              WHERE e.plan_compte_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM ecritures_ventilations ev WHERE ev.ecriture_id = e.id)'
           . ($annee ? ' AND substr(e.date_op,1,4) = ?' : '')
           . ' GROUP BY e.plan_compte_id';
    $stmtNv = db()->prepare($sqlNv);
    $stmtNv->execute($annee ? [(string) $annee] : []);
    $nvProd = 0.0; $nvChg = 0.0;
    foreach ($stmtNv as $r) {
        if (($plan[(int) $r['plan_compte_id']]['sens'] ?? 'charge') === 'produit') $nvProd += (float) $r['s'];
        else $nvChg += (float) $r['s'];
    }

    return [
        'annee'        => $annee,
        'annees'       => $annees,
        'axes'         => $axes,
        'ventilation'  => $ventilation,
        'detailParAxe' => $detailParAxe,
        'nomEmployeur' => (string) param('employeur_nom'),
        'nonVentile'   => ['produits' => $nvProd, 'charges' => $nvChg, 'resultat' => $nvProd + $nvChg],
    ];
}

function route_compta_analyse(): void
{
    require_login();
    // Pas de param → toutes les années (0) plutôt que la plus récente.
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : 0;
    render('compta_analyse', compta_analyse_data($annee), 'Comptabilité analytique');
}

function route_compta_analyse_axe(): void
{
    require_login();
    $axeId = (int) ($_GET['axe'] ?? 0);
    if (!$axeId) redirect('compta_analyse');

    $stmt = db()->prepare('SELECT * FROM axes_analytiques WHERE id = ?');
    $stmt->execute([$axeId]);
    $axe = $stmt->fetch();
    if (!$axe) redirect('compta_analyse');

    $annees = compta_annees();
    $annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) ($annees[0] ?? date('Y'));

    $plan = compta_plan_map();

    // Sommaires par catégorie (même logique que axe_print).
    if ($annee === 0) {
        $sommesParAnnee = [];
        $stmtAll = db()->prepare(
            'SELECT substr(e.date_op,1,4) y, e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL
             GROUP BY y, e.plan_compte_id'
        );
        $stmtAll->execute([$axeId]);
        foreach ($stmtAll as $r) {
            $sommesParAnnee[(int) $r['y']][(int) $r['plan_compte_id']] = (float) $r['s'];
        }
        $cols = array_values(array_filter($annees, fn($a) => isset($sommesParAnnee[$a])));
    } else {
        if ($annees && !in_array($annee, $annees, true)) $annee = (int) ($annees[0] ?? date('Y'));
        $cols = [$annee];
        $sommesParAnnee = [$annee => []];
        $stmtSommes = db()->prepare(
            'SELECT e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL AND substr(e.date_op,1,4) = ?
             GROUP BY e.plan_compte_id'
        );
        $stmtSommes->execute([$axeId, (string) $annee]);
        foreach ($stmtSommes as $r) {
            $sommesParAnnee[$annee][(int) $r['plan_compte_id']] = (float) $r['s'];
        }
    }

    $totauxParAnnee = [];
    foreach ($cols as $a) {
        $tp = 0.0; $tc = 0.0;
        foreach ($sommesParAnnee[$a] as $pid => $m) {
            if (($plan[$pid]['sens'] ?? 'charge') === 'produit') $tp += $m; else $tc += $m;
        }
        $totauxParAnnee[$a] = ['produits' => $tp, 'charges' => $tc, 'resultat' => $tp + $tc];
    }

    $anneeRef = $annee === 0 ? -1 : (int) ($cols[0] ?? $annee);

    // Charges sociales prévues (fiches avec au moins une ligne sur cet axe, proratisées).
    $chargesParAnnee = [];
    foreach (($annee === 0 ? $annees : [$annee]) as $a) {
        $c = charges_sociales_axe($axeId, (int) $a);
        if ($c['salaire_brut'] > 0.005) {
            $chargesParAnnee[(int) $a] = $c;
        }
    }

    // Écritures ventilées sur cet axe (ev.montant = part de l'écriture imputée à cet axe).
    if ($annee === 0) {
        $stmtEcr = db()->prepare(
            'SELECT e.id, e.date_op, e.texte, ev.montant,
                    cb.libelle AS compte_libelle, pc.libelle AS cat_libelle
             FROM ecritures_ventilations ev
             JOIN ecritures e ON e.id = ev.ecriture_id
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             LEFT JOIN plan_comptes pc ON pc.id = e.plan_compte_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL
             ORDER BY e.date_op DESC, e.id DESC'
        );
        $stmtEcr->execute([$axeId]);
    } else {
        $stmtEcr = db()->prepare(
            'SELECT e.id, e.date_op, e.texte, ev.montant,
                    cb.libelle AS compte_libelle, pc.libelle AS cat_libelle
             FROM ecritures_ventilations ev
             JOIN ecritures e ON e.id = ev.ecriture_id
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             LEFT JOIN plan_comptes pc ON pc.id = e.plan_compte_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL AND substr(e.date_op,1,4) = ?
             ORDER BY e.date_op DESC, e.id DESC'
        );
        $stmtEcr->execute([$axeId, (string) $annee]);
    }
    $ecritures = $stmtEcr->fetchAll();

    render('compta_analyse_axe', [
        'axe'             => $axe,
        'annee'           => $annee,
        'anneeRef'        => $anneeRef,
        'annees'          => $annees,
        'cols'            => $cols,
        'plan'            => $plan,
        'sommesParAnnee'  => $sommesParAnnee,
        'totauxParAnnee'  => $totauxParAnnee,
        'chargesParAnnee' => $chargesParAnnee,
        'ecritures'       => $ecritures,
    ], 'Analytique — ' . $axe['libelle']);
}

function route_compta_analyse_print(): void
{
    require_login();
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : null;
    render_bare('compta_analyse_print', compta_analyse_data($annee));
}

function route_compta_analyse_axe_print(): void
{
    require_login();
    $axeId = (int) ($_GET['axe'] ?? 0);
    if (!$axeId) redirect('compta_analyse');

    $stmt = db()->prepare('SELECT * FROM axes_analytiques WHERE id = ?');
    $stmt->execute([$axeId]);
    $axe = $stmt->fetch();
    if (!$axe) redirect('compta_analyse');

    $annees = compta_annees();
    $annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) ($annees[0] ?? date('Y'));

    $plan = compta_plan_map();
    $sommesParAnnee = [];

    if ($annee === 0) {
        $stmtAll = db()->prepare(
            'SELECT substr(e.date_op,1,4) y, e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL
             GROUP BY y, e.plan_compte_id'
        );
        $stmtAll->execute([$axeId]);
        foreach ($stmtAll as $r) {
            $sommesParAnnee[(int) $r['y']][(int) $r['plan_compte_id']] = (float) $r['s'];
        }
        $cols = array_values(array_filter($annees, fn($a) => isset($sommesParAnnee[$a])));
    } else {
        if (!in_array($annee, $annees, true)) $annee = (int) ($annees[0] ?? date('Y'));
        $cols = [$annee];
        $stmtSommes = db()->prepare(
            'SELECT e.plan_compte_id, SUM(ev.montant) s
             FROM ecritures_ventilations ev JOIN ecritures e ON e.id = ev.ecriture_id
             WHERE ev.axe_id = ? AND e.plan_compte_id IS NOT NULL AND substr(e.date_op,1,4) = ?
             GROUP BY e.plan_compte_id'
        );
        $sommesParAnnee = [$annee => []];
        $stmtSommes->execute([$axeId, (string) $annee]);
        foreach ($stmtSommes as $r) {
            $sommesParAnnee[$annee][(int) $r['plan_compte_id']] = (float) $r['s'];
        }
    }

    $totauxParAnnee = [];
    foreach ($cols as $a) {
        $tp = 0.0; $tc = 0.0;
        foreach ($sommesParAnnee[$a] as $pid => $m) {
            if (($plan[$pid]['sens'] ?? 'charge') === 'produit') $tp += $m;
            else $tc += $m;
        }
        $totauxParAnnee[$a] = ['produits' => $tp, 'charges' => $tc, 'resultat' => $tp + $tc];
    }

    // Quand toutes les années : aucune colonne isolée n'est mise en valeur, seul le total l'est.
    $anneeRef = $annee === 0 ? -1 : (int) ($cols[0] ?? $annee);

    render_bare('compta_analyse_axe_print', [
        'axe'            => $axe,
        'annee'          => $annee,
        'anneeRef'       => $anneeRef,
        'annees'         => $annees,
        'cols'           => $cols,
        'plan'           => $plan,
        'sommesParAnnee' => $sommesParAnnee,
        'totauxParAnnee' => $totauxParAnnee,
        'nomEmployeur'   => (string) param('employeur_nom'),
    ]);
}

// --- Bilan & compte de résultat --------------------------------------------
function route_compta_bilan(): void
{
    require_login();
    $annees = compta_annees();
    $annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) ($annees[0] ?? date('Y'));
    // « Comparer jusqu'à » : année la plus ancienne à comparer (0 = ne pas comparer).
    if (isset($_GET['jusqua'])) {
        $jusqua = ($_GET['jusqua'] === 'aucune') ? 0 : (int) $_GET['jusqua'];
        $_SESSION['bilan_jusqua'] = $jusqua;
    } else {
        $jusqua = $_SESSION['bilan_jusqua'] ?? ($annee - 2); // défaut : année − 2
    }
    // Convertit en nombre d'années précédentes (disponibles) à inclure dans les colonnes.
    $nbPrec = 0;
    if ($jusqua > 0) {
        foreach ($annees as $y) {
            if ((int) $y < $annee && (int) $y >= $jusqua) {
                $nbPrec++;
            }
        }
    }
    render('compta_bilan', compta_bilan_data($annee, $nbPrec), 'Comptabilité — Bilan & résultat');
}

// Ligne de total d'un tableau de bilan (une cellule par colonne d'année).
function compta_ligne_total(string $libelle, string $cle, string $cls, array $cols, array $totauxParAnnee): string
{
    $h    = '<tr class="' . $cls . '"><td>' . e($libelle) . '</td>';
    $cur  = (int) ($cols[0] ?? 0);
    foreach ($cols as $a) {
        $precCls = ((int) $a !== $cur) ? ' col-prec' : '';
        $h .= '<td class="num' . $precCls . '">' . chf((float) ($totauxParAnnee[(int) $a][$cle] ?? 0)) . '</td>';
    }
    return $h . '</tr>';
}

// Somme des soldes bancaires au 31.12 de l'année $a.
function compta_total_patrimoine(int $a, array $patrimoine): float
{
    $t = 0.0;
    foreach ($patrimoine as $p) {
        $t += (float) ($p['valeurs'][$a] ?? 0);
    }
    return $t;
}

// Calcule toutes les données du bilan (compte de résultat de $annee + patrimoine
// multi-années). Partagé entre l'écran et la version imprimable.
function compta_bilan_data(int $annee, int $nbPrec = 0): array
{
    $annees = compta_annees();
    if (!$annee) {
        $annee = (int) ($annees[0] ?? date('Y'));
    }

    // Colonnes à afficher : année sélectionnée + $nbPrec années précédentes disponibles.
    $pos  = array_search($annee, $annees);
    $cols = array_slice($annees, $pos === false ? 0 : $pos, $nbPrec + 1);

    // Compte de résultat de l'année sélectionnée (warning non-lettrées + détail).
    $plan = compta_plan_map();
    $stmt = db()->prepare('SELECT montant, plan_compte_id, origine_lettrage FROM ecritures WHERE substr(date_op,1,4) = ?');
    $stmt->execute([(string) $annee]);
    $resultat = agreger_resultat($stmt->fetchAll(), $plan);

    // Sommes par (année, catégorie) + totaux par année, pour le comparatif.
    $sommesParAnnee = [];
    foreach (db()->query("SELECT substr(date_op,1,4) y, plan_compte_id, SUM(montant) s
                          FROM ecritures WHERE plan_compte_id IS NOT NULL GROUP BY y, plan_compte_id") as $r) {
        $sommesParAnnee[(int) $r['y']][(int) $r['plan_compte_id']] = (float) $r['s'];
    }
    $totauxParAnnee = [];
    foreach ($cols as $a) {
        $tp = 0.0;
        $tc = 0.0;
        foreach ($sommesParAnnee[$a] ?? [] as $pid => $m) {
            if (($plan[$pid]['sens'] ?? 'charge') === 'produit') {
                $tp += $m;
            } else {
                $tc += $m;
            }
        }
        $totauxParAnnee[$a] = ['produits' => $tp, 'charges' => $tc, 'resultat' => $tp + $tc];
    }

    // Détail des écritures par catégorie (pour le dépliage au clic sur une ligne).
    $stmt = db()->prepare('SELECT e.plan_compte_id, e.date_op, e.texte, e.montant, cb.libelle AS compte
        FROM ecritures e JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
        WHERE substr(e.date_op,1,4) = ? AND e.plan_compte_id IS NOT NULL
        ORDER BY e.date_op, e.id');
    $stmt->execute([(string) $annee]);
    $lignesParCat = [];
    foreach ($stmt as $r) {
        $lignesParCat[(int) $r['plan_compte_id']][] = $r;
    }

    // Patrimoine : solde de fin d'année par compte bancaire.
    // Report à nouveau : on vérifie que l'ouverture de N = clôture de N-1.
    $clotureFin = db()->prepare("SELECT solde FROM ecritures
        WHERE compte_bancaire_id = ? AND solde IS NOT NULL AND date_op <= ?
        ORDER BY date_op DESC, id ASC LIMIT 1");
    // Ouverture de l'année : 1re ligne bancaire portant un solde courant, et sa date.
    // PostFinance ne renseigne le solde que sur la DERNIÈRE opération d'une journée ;
    // l'ouverture se reconstitue donc en retranchant la somme des montants bancaires
    // de l'année jusqu'à cette date incluse (et non le seul montant de cette ligne,
    // qui ignorerait les autres mouvements du même jour).
    $premiereAnnee = db()->prepare("SELECT solde, date_op FROM ecritures
        WHERE compte_bancaire_id = ? AND solde IS NOT NULL AND substr(date_op,1,4) = ?
        ORDER BY date_op ASC, id ASC LIMIT 1");
    $cumulBancaireJusqua = db()->prepare("SELECT COALESCE(SUM(montant), 0) FROM ecritures
        WHERE compte_bancaire_id = ? AND import_id IS NOT NULL AND substr(date_op,1,4) = ? AND date_op <= ?");
    // Cumul des écritures manuelles (jamais reflétées dans le solde courant
    // bancaire) jusqu'à une date : elles font bel et bien varier le patrimoine.
    $manuelCumul = db()->prepare("SELECT COALESCE(SUM(montant), 0) FROM ecritures
        WHERE compte_bancaire_id = ? AND import_id IS NULL AND date_op <= ?");
    $patrimoine = [];
    $continuite = [];
    foreach (compta_comptes() as $c) {
        $cid = (int) $c['id'];
        $ligne = ['libelle' => $c['libelle'], 'valeurs' => []];
        foreach ($cols as $a) {
            $clotureFin->execute([$cid, "$a-12-31"]);
            $v = $clotureFin->fetchColumn();
            $base = $v === false ? (float) $c['solde_initial'] : (float) $v;
            $manuelCumul->execute([$cid, "$a-12-31"]);
            $ligne['valeurs'][$a] = $base + (float) $manuelCumul->fetchColumn();
            $clotureFin->execute([$cid, ($a - 1) . '-12-31']);
            $clotPrec = $clotureFin->fetchColumn();
            $premiereAnnee->execute([$cid, (string) $a]);
            $prem = $premiereAnnee->fetch();
            if ($clotPrec !== false && $prem) {
                $cumulBancaireJusqua->execute([$cid, (string) $a, $prem['date_op']]);
                $ouverture = (float) $prem['solde'] - (float) $cumulBancaireJusqua->fetchColumn();
                if (abs($ouverture - (float) $clotPrec) > 0.01) {
                    $continuite[] = ['compte' => $c['libelle'], 'annee' => $a,
                        'ouverture' => $ouverture, 'cloture_prec' => (float) $clotPrec];
                }
            }
        }
        $patrimoine[] = $ligne;
    }

    return [
        'annee'          => $annee,
        'annees'         => $annees,
        'cols'           => $cols,
        'nbPrec'         => $nbPrec,
        'resultat'       => $resultat,
        'sommesParAnnee' => $sommesParAnnee,
        'totauxParAnnee' => $totauxParAnnee,
        'continuite'     => $continuite,
        'plan'           => $plan,
        'lignesParCat'   => $lignesParCat,
        'patrimoine'     => $patrimoine,
    ];
}

// Série de données pour le graphique du tableau de bord (toutes les années, ordre chrono).
function compta_dashboard_series(): array
{
    $annees = array_reverse(compta_annees());
    if (!$annees) return [];

    $plan = compta_plan_map();

    $sommesParAnnee = [];
    foreach (db()->query("SELECT substr(date_op,1,4) y, plan_compte_id, SUM(montant) s
                          FROM ecritures WHERE plan_compte_id IS NOT NULL
                          GROUP BY y, plan_compte_id") as $r) {
        $sommesParAnnee[(int) $r['y']][(int) $r['plan_compte_id']] = (float) $r['s'];
    }

    $series = [];
    foreach ($annees as $a) {
        $tp = 0.0; $tc = 0.0;
        foreach ($sommesParAnnee[$a] ?? [] as $pid => $m) {
            if (($plan[$pid]['sens'] ?? 'charge') === 'produit') $tp += $m;
            else $tc += $m;
        }
        $series[$a] = ['produits' => $tp, 'charges' => $tc, 'resultat' => $tp + $tc, 'patrimoine' => 0.0];
    }

    // Une seule passe chronologique par compte (au lieu d'une requête par
    // couple compte × année) : on avance un pointeur pendant que les années
    // (déjà triées) et les dates lues (triées côté SQL) progressent toutes
    // les deux dans le même sens.
    $soldesParCompte = [];
    foreach (db()->query(
        'SELECT compte_bancaire_id, date_op, solde FROM ecritures
         WHERE solde IS NOT NULL ORDER BY compte_bancaire_id, date_op ASC, id ASC'
    ) as $r) {
        $soldesParCompte[(int) $r['compte_bancaire_id']][] = ['date' => $r['date_op'], 'solde' => (float) $r['solde']];
    }
    $manuelsParCompte = [];
    foreach (db()->query(
        'SELECT compte_bancaire_id, date_op, montant FROM ecritures
         WHERE import_id IS NULL ORDER BY compte_bancaire_id, date_op ASC, id ASC'
    ) as $r) {
        $manuelsParCompte[(int) $r['compte_bancaire_id']][] = ['date' => $r['date_op'], 'montant' => (float) $r['montant']];
    }

    foreach (compta_comptes() as $c) {
        $cid     = (int) $c['id'];
        $soldes  = $soldesParCompte[$cid] ?? [];
        $manuels = $manuelsParCompte[$cid] ?? [];
        $iSolde = 0; $iManuel = 0; $dernierSolde = null; $sommeManuel = 0.0;
        foreach ($annees as $a) {
            $cutoff = "$a-12-31";
            while ($iSolde < count($soldes) && $soldes[$iSolde]['date'] <= $cutoff) {
                $dernierSolde = $soldes[$iSolde]['solde'];
                $iSolde++;
            }
            while ($iManuel < count($manuels) && $manuels[$iManuel]['date'] <= $cutoff) {
                $sommeManuel += $manuels[$iManuel]['montant'];
                $iManuel++;
            }
            $base = $dernierSolde ?? (float) $c['solde_initial'];
            $series[$a]['patrimoine'] += $base + $sommeManuel;
        }
    }

    return $series;
}

function route_compta_bilan_print(): void
{
    require_login();
    $annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : 0;
    $nbPrec = max(0, min(3, (int) ($_GET['prec'] ?? ($_SESSION['bilan_prec'] ?? 2))));
    render_bare('compta_bilan_print', compta_bilan_data($annee, $nbPrec) + ['nomEmployeur' => (string) param('employeur_nom')]);
}

// --- Export CSV des écritures -----------------------------------------------
function route_compta_ecritures_csv(): void
{
    require_login();
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');

    if ($annee === 0) {
        $rows = db()->query(
            'SELECT e.date_op, e.texte, e.tiers, e.communication, e.montant,
                    cb.libelle AS compte_bancaire,
                    p.libelle  AS categorie,
                    e.origine_lettrage
             FROM ecritures e
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             LEFT JOIN plan_comptes p  ON p.id  = e.plan_compte_id
             ORDER BY e.date_op ASC, e.id ASC'
        )->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT e.date_op, e.texte, e.tiers, e.communication, e.montant,
                    cb.libelle AS compte_bancaire,
                    p.libelle  AS categorie,
                    e.origine_lettrage
             FROM ecritures e
             JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
             LEFT JOIN plan_comptes p  ON p.id  = e.plan_compte_id
             WHERE substr(e.date_op,1,4) = ?
             ORDER BY e.date_op ASC, e.id ASC'
        );
        $stmt->execute([(string) $annee]);
        $rows = $stmt->fetchAll();
    }

    $nom = (string) param('employeur_nom');
    $anneeLabel = $annee === 0 ? 'toutes-annees' : (string) $annee;
    $filename = 'ecritures-' . $anneeLabel . ($nom !== '' ? '-' . preg_replace('/[^a-z0-9]/i', '-', $nom) : '') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 pour compatibilité Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Texte', 'Tiers', 'Communication', 'Montant CHF', 'Compte bancaire', 'Catégorie', 'Lettrage'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date_op'],
            $r['texte'],
            $r['tiers'] ?? '',
            $r['communication'] ?? '',
            number_format((float) $r['montant'], 2, '.', ''),
            $r['compte_bancaire'],
            $r['categorie'] ?? '',
            $r['origine_lettrage'] ?? '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// Export d'un relevé ISO 20022 camt.053 (XML) pour UN compte bancaire — le
// format porte une seule IBAN par relevé, contrairement à l'export CSV
// (route_compta_ecritures_csv()) qui combine tous les comptes.
function route_compta_ecritures_camt053(): void
{
    require_login();
    $compteId = (int) ($_GET['compte'] ?? 0);
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');

    $stmt = db()->prepare('SELECT * FROM comptes_bancaires WHERE id = ?');
    $stmt->execute([$compteId]);
    $compte = $stmt->fetch();
    if (!$compte || trim((string) $compte['iban']) === '') {
        redirect('export', ['err' => 'camt_compte']);
    }

    if ($annee === 0) {
        $soldeOuverture = (float) $compte['solde_initial'];
        $rows = db()->prepare('SELECT date_op, texte, montant FROM ecritures WHERE compte_bancaire_id = ? ORDER BY date_op ASC, id ASC');
        $rows->execute([$compteId]);
    } else {
        $stmtAvant = db()->prepare('SELECT COALESCE(SUM(montant),0) FROM ecritures WHERE compte_bancaire_id = ? AND substr(date_op,1,4) < ?');
        $stmtAvant->execute([$compteId, (string) $annee]);
        $soldeOuverture = (float) $compte['solde_initial'] + (float) $stmtAvant->fetchColumn();

        $rows = db()->prepare('SELECT date_op, texte, montant FROM ecritures WHERE compte_bancaire_id = ? AND substr(date_op,1,4) = ? ORDER BY date_op ASC, id ASC');
        $rows->execute([$compteId, (string) $annee]);
    }
    $lignes = $rows->fetchAll();

    $dateDebut = $lignes ? $lignes[0]['date_op'] : ($annee ? $annee . '-01-01' : date('Y-m-d'));
    $dateFin   = $lignes ? end($lignes)['date_op'] : ($annee ? $annee . '-12-31' : date('Y-m-d'));

    $xml = compta_generer_camt053($compte, $lignes, $dateDebut, $dateFin, $soldeOuverture);

    $anneeLabel = $annee === 0 ? 'toutes-annees' : (string) $annee;
    $filename = 'camt053-' . preg_replace('/[^a-z0-9]/i', '-', $compte['libelle']) . '-' . $anneeLabel . '.xml';

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    echo $xml;
}

// Sauvegarde AJAX des ventilations d'une écriture (remplace DELETE + INSERT).
// Retourne JSON {ok, ventilations[]}.
function route_compta_ventilation_save(): void
{
    require_login();
    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false]); return;
    }
    check_csrf();
    $ecrId = (int) ($_POST['ecriture_id'] ?? 0);
    if (!$ecrId) { echo json_encode(['ok' => false]); return; }
    $ecrStmt = db()->prepare('SELECT montant FROM ecritures WHERE id = ?');
    $ecrStmt->execute([$ecrId]);
    $ecrRow  = $ecrStmt->fetch();
    if (!$ecrRow) { echo json_encode(['ok' => false]); return; }

    // Les UI soumettent des valeurs absolues ; on applique le signe de l'écriture
    // pour que les charges soient négatives et les recettes positives en base.
    $sign   = (float) $ecrRow['montant'] < 0 ? -1.0 : 1.0;
    $axeIds   = array_map('intval',   (array) ($_POST['axe_id'] ?? []));
    $montants = array_map('floatval', (array) ($_POST['montant'] ?? []));
    $lignes = [];
    foreach ($axeIds as $i => $aId) {
        if ($aId > 0) {
            $lignes[] = ['axe_id' => $aId, 'montant' => abs($montants[$i] ?? 0.0) * $sign];
        }
    }
    compta_save_ventilations($ecrId, $lignes);
    echo json_encode(['ok' => true, 'ventilations' => compta_ventilations_ecriture($ecrId)]);
}

// Page de suggestion de ventilation pour une écriture de charges sociales.
function route_compta_suggestion_ventilation(): void
{
    require_login();

    $annee = (int) ($_GET['annee'] ?? (int) date('Y'));
    $ecrId = (int) ($_GET['ecriture_id'] ?? 0);

    // Écritures de charges sociales non ventilées (filtrées par mots-clés plan comptable).
    $kw = ['%social%', '%ocas%', '%avs%', '%laa%', '%lpp%', '%prévoy%', '%accident%'];
    $likeClause = implode(' OR ', array_map(fn() => 'LOWER(pc.libelle) LIKE ? OR LOWER(pc.groupe) LIKE ?', $kw));
    $likeParams = [];
    foreach ($kw as $k) { $likeParams[] = $k; $likeParams[] = $k; }

    $anneeClause = $annee ? " AND substr(e.date_op,1,4) = ?" : '';
    $params = array_merge($likeParams, $annee ? [$annee] : []);

    $stmt = db()->prepare("
        SELECT e.id, e.date_op, e.texte, e.montant,
               pc.libelle AS pc_libelle, pc.groupe AS pc_groupe,
               cb.libelle AS compte_libelle
        FROM ecritures e
        JOIN plan_comptes pc ON pc.id = e.plan_compte_id
        LEFT JOIN comptes_bancaires cb ON cb.id = e.compte_bancaire_id
        WHERE pc.sens = 'charge' AND pc.actif = 1
          AND NOT EXISTS (SELECT 1 FROM ecritures_ventilations ev WHERE ev.ecriture_id = e.id)
          AND ($likeClause)
          $anneeClause
        ORDER BY e.date_op DESC
    ");
    $stmt->execute($params);
    $ecritures = $stmt->fetchAll();

    // Écriture sélectionnée + type détecté + période par défaut.
    $ecrSel = null; $type = ''; $periodeDefaut = [null, null, null, null];
    foreach ($ecritures as $ecr) {
        if ((int) $ecr['id'] === $ecrId) {
            $ecrSel       = $ecr;
            $type         = detecter_type_charge((string) $ecr['pc_libelle'], (string) $ecr['pc_groupe']);
            $periodeDefaut = periode_defaut_charges((string) $ecr['date_op']);
            break;
        }
    }

    // Années disponibles pour filtre de la liste + sélecteurs de période.
    $anneesEcr  = array_map('intval', db()->query("SELECT DISTINCT substr(date_op,1,4) FROM ecritures ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN));
    $anneesFich = array_map('intval', db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN));
    $axes       = db()->query('SELECT id, code, libelle FROM axes_analytiques WHERE actif = 1 ORDER BY code, libelle')->fetchAll();

    render('compta_suggestion_ventilation', [
        'ecritures'    => $ecritures,
        'annee'        => $annee,
        'anneesEcr'    => $anneesEcr,
        'anneesFich'   => $anneesFich,
        'ecrId'        => $ecrId,
        'ecrSel'       => $ecrSel,
        'type'         => $type,
        'periodeDefaut' => $periodeDefaut,
        'axes'         => $axes,
    ], 'Suggérer ventilation charges');
}

// Endpoint AJAX : calcule la ventilation suggérée pour une période donnée (GET, retourne JSON).
function route_compta_suggestion_preview(): void
{
    require_login();
    header('Content-Type: application/json; charset=UTF-8');
    $aD   = (int) ($_GET['annee_debut'] ?? 0);
    $mD   = (int) ($_GET['mois_debut']  ?? 0);
    $aF   = (int) ($_GET['annee_fin']   ?? 0);
    $mF   = (int) ($_GET['mois_fin']    ?? 0);
    $type = preg_replace('/[^a-z]/', '', strtolower($_GET['type'] ?? ''));

    if (!$aD || !$mD || !$aF || !$mF || !in_array($type, ['ocas', 'laa', 'lpp'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Paramètres invalides']); return;
    }

    $cumul = charges_sociales_par_axe($aD, $mD, $aF, $mF);
    $suggestions = [];
    foreach ($cumul as $axeId => $c) {
        $montant = montant_axe_pour_type($c, $type);
        if ($montant > 0.005) {
            $suggestions[] = [
                'axe_id'  => $axeId,
                'code'    => $c['code'],
                'libelle' => $c['libelle'],
                'montant' => round($montant, 2),
            ];
        }
    }
    echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
}
