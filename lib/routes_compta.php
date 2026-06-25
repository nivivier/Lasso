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
            if ((int) $stmt->fetchColumn() === 0) {
                db()->prepare('DELETE FROM comptes_bancaires WHERE id = ?')->execute([$id]);
                redirect('compta_comptes', ['ok' => 1]);
            }
            redirect('compta_comptes', ['err' => 'used']);
        }
    }
    render('compta_comptes', [
        'comptes' => compta_comptes(),
        'err'     => $err,
        'saved'   => isset($_GET['ok']),
        'flagErr' => $_GET['err'] ?? null,
    ], 'Comptabilité — Comptes bancaires');
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
        $f = $_FILES['fichier'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $msg = ['err', 'Aucun fichier reçu ou téléversement échoué.'];
        } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            $msg = ['err', 'Fichier trop volumineux (max 5 Mo).'];
        } else {
            $contenu = file_get_contents($f['tmp_name']);
            $parse = parse_postfinance_csv($contenu);
            $iban = $parse['iban'];
            $compte = null;
            $compteCree = false;
            if ($iban !== '') {
                $stmt = db()->prepare('SELECT * FROM comptes_bancaires WHERE iban = ?');
                $stmt->execute([$iban]);
                $compte = $stmt->fetch();
                // Compte inconnu → création automatique à partir de l'IBAN vérifié.
                if (!$compte) {
                    $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM comptes_bancaires')->fetchColumn();
                    db()->prepare('INSERT INTO comptes_bancaires (libelle, iban, ordre) VALUES (?, ?, ?)')
                        ->execute(['Compte PostFinance ' . substr($iban, -4), $iban, $ordre]);
                    $stmt->execute([$iban]);
                    $compte = $stmt->fetch();
                    $compteCree = true;
                }
            }
            if (!$compte) {
                $msg = ['err', "IBAN introuvable dans le fichier : ce n'est peut-être pas un export PostFinance."];
            } elseif (!$parse['lignes']) {
                $msg = ['err', 'Aucune écriture trouvée dans ce fichier.'];
            } else {
                [$ins, $dup] = compta_inserer_ecritures($compte, $parse, $f['name']);
                compta_lettrer_par_regles((int) $compte['id'], null);
                $prefixe = $compteCree
                    ? "Compte « " . $compte['libelle'] . " » créé (IBAN $iban). "
                    : "Import « " . $compte['libelle'] . " » : ";
                $msg = ['ok', $prefixe . "$ins écriture(s) ajoutée(s), $dup doublon(s) ignoré(s)."];
            }
        }
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

// --- Lettrage (écran principal) --------------------------------------------
function route_compta_ecritures(): void
{
    require_login();
    $comptes = compta_comptes();

    // Filtres : GET prioritaire, sinon dernière valeur en session, sinon défaut.
    if (isset($_GET['compte'])) {
        $compteId = (int) $_GET['compte'];
        $_SESSION['ecr_compte'] = $compteId;
    } else {
        $compteId = (int) ($_SESSION['ecr_compte'] ?? 0);
    }
    $annees = compta_annees();
    if (isset($_GET['annee'])) {
        $annee = (int) $_GET['annee'];
        $_SESSION['ecr_annee'] = $annee;
    } else {
        $annee = (int) ($_SESSION['ecr_annee'] ?? ($annees[0] ?? date('Y')));
    }
    if (isset($_GET['categorie'])) {
        $categorieFilter = $_GET['categorie'];
        $_SESSION['ecr_categorie'] = $categorieFilter;
    } else {
        $categorieFilter = $_SESSION['ecr_categorie'] ?? '';
    }
    if (isset($_GET['axe'])) {
        $axeFilter = $_GET['axe'];
        $_SESSION['ecr_axe'] = $axeFilter;
    } else {
        $axeFilter = $_SESSION['ecr_axe'] ?? '';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        $retour  = ['compte' => $compteId, 'annee' => $annee, 'categorie' => $categorieFilter, 'axe' => $axeFilter];
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
            $axeRaw = $_POST['axe_analytique_id'] ?? '';
            $axeId  = ($axeRaw !== '' && $axeRaw !== '0') ? (int) $axeRaw : null;
            if ($cid && $date_op && $texte) {
                if ($section === 'create') {
                    $hash = sha1('manual-' . uniqid('', true) . mt_rand());
                    db()->prepare('INSERT INTO ecritures (compte_bancaire_id, date_op, texte, montant, plan_compte_id, origine_lettrage, axe_analytique_id, hash) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$cid, $date_op, $texte, $montant, $planId, $origLettrage, $axeId, $hash]);
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    db()->prepare('UPDATE ecritures SET compte_bancaire_id=?, date_op=?, texte=?, montant=?, plan_compte_id=?, origine_lettrage=?, axe_analytique_id=? WHERE id=? AND import_id IS NULL')
                        ->execute([$cid, $date_op, $texte, $montant, $planId, $origLettrage, $axeId, $id]);
                }
            }
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'delete_manual') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM ecritures WHERE id=? AND import_id IS NULL')->execute([$id]);
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'lettrer') {
            // Affectation manuelle (une ou plusieurs écritures) à une catégorie.
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $planId = $_POST['plan_compte_id'] ?? '';
            $ids = array_filter($ids);
            if ($ids) {
                if ($planId === '' || $planId === '0') {
                    // Délettrage : remet à NULL (annule aussi un « Ne pas lettrer »).
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    db()->prepare("UPDATE ecritures SET plan_compte_id = NULL, origine_lettrage = '' WHERE id IN ($in)")
                        ->execute(array_values($ids));
                } elseif ($planId === 'ignore') {
                    // Marquage « Ne pas lettrer » : sans catégorie, mais exclue des non-lettrées.
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    db()->prepare("UPDATE ecritures SET plan_compte_id = NULL, origine_lettrage = 'ignore' WHERE id IN ($in)")
                        ->execute(array_values($ids));
                } elseif (plan_est_feuille((int) $planId, compta_plan_map())) {
                    // Seules les catégories feuilles (sans enfant) sont assignables.
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = db()->prepare("UPDATE ecritures SET plan_compte_id = ?, origine_lettrage = 'manuel' WHERE id IN ($in)");
                    $stmt->execute(array_merge([(int) $planId], array_values($ids)));
                }
            }
            redirect('compta_ecritures', $retour);
        } elseif ($section === 'axer') {
            // Affectation d'un axe analytique (une ou plusieurs écritures).
            $ids   = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            $axeId = $_POST['axe_analytique_id'] ?? '';
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                if ($axeId === '' || $axeId === '0') {
                    db()->prepare("UPDATE ecritures SET axe_analytique_id = NULL WHERE id IN ($in)")
                        ->execute(array_values($ids));
                } else {
                    $stmt = db()->prepare("UPDATE ecritures SET axe_analytique_id = ? WHERE id IN ($in)");
                    $stmt->execute(array_merge([(int) $axeId], array_values($ids)));
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
        $sql .= ' AND e.plan_compte_id = ?';
        $params[] = (int) $categorieFilter;
    }
    if (ctype_digit((string) $axeFilter) && $axeFilter !== '') {
        $sql .= ' AND e.axe_analytique_id = ?';
        $params[] = (int) $axeFilter;
    } elseif ($axeFilter === 'sans_axe') {
        $sql .= ' AND e.axe_analytique_id IS NULL AND e.plan_compte_id IS NOT NULL';
    }
    $sql .= ' ORDER BY e.date_op DESC, e.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ecritures = $stmt->fetchAll();

    $feuilles = plan_feuilles(compta_plan_actif());
    $axes     = db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
    render('compta_ecritures', [
        'comptes'         => $comptes,
        'compteId'        => $compteId,
        'annee'           => $annee,
        'annees'          => $annees,
        'categorieFilter' => $categorieFilter,
        'axeFilter'       => $axeFilter,
        'ecritures'       => $ecritures,
        'feuilles'        => $feuilles,
        'axes'            => $axes,
        'rules'           => $_GET['rules'] ?? null,
        'editEcr'         => $editEcr,
        'openNew'         => isset($_GET['new']),
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
            $id    = (int) ($_POST['id'] ?? 0);
            $lib   = trim($_POST['libelle'] ?? '');
            $code  = trim($_POST['code'] ?? '');
            $actif = isset($_POST['actif']) ? 1 : 0;
            if ($id && $lib !== '') {
                db()->prepare('UPDATE axes_analytiques SET libelle=?, code=?, actif=? WHERE id=?')
                    ->execute([$lib, $code, $actif, $id]);
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                db()->prepare('UPDATE ecritures SET axe_analytique_id = NULL WHERE axe_analytique_id = ?')->execute([$id]);
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
    $axes = db()->query('SELECT * FROM axes_analytiques ORDER BY ordre, id')->fetchAll();
    if (!$axes) return [];
    $stmt = db()->prepare(
        'SELECT plan_compte_id, SUM(montant) s FROM ecritures
         WHERE axe_analytique_id = ? AND plan_compte_id IS NOT NULL AND substr(date_op,1,4) = ?
         GROUP BY plan_compte_id'
    );
    $rows = [];
    foreach ($axes as $axe) {
        $stmt->execute([(int) $axe['id'], (string) $annee]);
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
function route_compta_analyse(): void
{
    require_login();
    $annees = compta_annees();
    $annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) ($annees[0] ?? date('Y'));
    $plan   = compta_plan_map();
    $axes   = db()->query('SELECT * FROM axes_analytiques ORDER BY ordre, id')->fetchAll();
    $ventilation = calculer_ventilation_analytique($annee, $plan);
    render('compta_analyse', [
        'annee'       => $annee,
        'annees'      => $annees,
        'axes'        => $axes,
        'ventilation' => $ventilation,
    ], 'Comptabilité analytique');
}

// --- Bilan & compte de résultat --------------------------------------------
function route_compta_bilan(): void
{
    require_login();
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : 0;
    if (isset($_GET['prec'])) {
        $nbPrec = max(0, min(3, (int) $_GET['prec']));
        $_SESSION['bilan_prec'] = $nbPrec;
    } else {
        $nbPrec = (int) ($_SESSION['bilan_prec'] ?? 2); // défaut : 3 colonnes (année + 2 précédentes)
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

    $nom = (string) param('employeur_nom');
    $filename = 'ecritures-' . $annee . ($nom !== '' ? '-' . preg_replace('/[^a-z0-9]/i', '-', $nom) : '') . '.csv';

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
