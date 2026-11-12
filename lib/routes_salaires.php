<?php
// Routes du module SALAIRES : employés, taux horaires, lignes du décompte
// (postes salariaux), fiches de salaire, certificats, cotisations, import et
// recalcul.
//
// Sorties de lib/routes.php, qui en portait 3064 et mêlait l'authentification,
// les comptes, les paramètres de l'employeur, le tableau de bord et la paie
// entière. Aucune n'a changé : c'est le FICHIER qui change, pas le code.

// -------------------------------------------------------------- EMPLOYÉS
function route_employes(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $pgTaille = pagination_taille('employes_taille');

    // Filtre de colonne « Nom » : actif / inactif. Par défaut seuls les actifs,
    // parce que c'est la liste dont on se sert — les anciens employés ne
    // disparaissent pas, ils attendent derrière l'entonnoir. Aucune case cochée
    // = tout le monde, comme partout ailleurs (filtre_coche()).
    $actif = filtre_coche('actif', 'employes_actif', ['1', '0'], true, ['1']);
    $where  = ' WHERE 1=1';
    $params = [];
    if ($actif && count($actif) < 2) {
        $where .= ' AND e.actif = ?';
        $params[] = (int) $actif[0];
    }

    // Tri de colonne. L'ordre naturel garde les actifs en tête : c'est la
    // liste dont on se sert, et la trier par nom ne doit pas remonter les
    // anciens au milieu tant qu'on ne l'a pas demandé.
    // ⚠️ Colonnes QUALIFIÉES : l'identité vit sur personnes depuis la
    // migration 100 et le reste sur employes, donc « nom » tout court ne
    // désigne plus rien de sûr.
    $tri = tri_colonne('employes', [
        'nom'     => ['pe.nom COLLATE NOCASE', 'pe.prenom COLLATE NOCASE'],
        'adresse' => ['pe.npa_localite COLLATE NOCASE', 'pe.rue COLLATE NOCASE'],
        'email'   => 'pe.email COLLATE NOCASE',
    ]);
    $orderBy = $tri['sql'] !== '' ? $tri['sql'] . ', e.id' : ' ORDER BY e.actif DESC, pe.nom, pe.prenom';

    // Total « sans recherche » : avec le filtre structuré, mais sans le texte
    // saisi — c'est lui qui décide du mode de pagination (client ou serveur).
    $stmtTot = db()->prepare('SELECT COUNT(*)' . EMPLOYE_FROM . $where);
    $stmtTot->execute($params);
    $totalSansRecherche = (int) $stmtTot->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    if ($modeClient) {
        // Mode client (voir pagination_mode_client()) : toutes les lignes,
        // recherche/pagination 100% en JS (lassoListeClient()) — pas de
        // requête LIKE ni de LIMIT ici.
        $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . $where . $orderBy);
        $stmt->execute($params);
        $employes = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['pe.nom', 'pe.prenom', 'pe.rue', 'pe.npa_localite', 'pe.email']);
        $stmtTot = db()->prepare('SELECT COUNT(*)' . EMPLOYE_FROM . $where . $rechSql);
        $stmtTot->execute(array_merge($params, $rechParams));
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);
        $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . $where . $rechSql . $orderBy . $limitSql);
        $stmt->execute(array_merge($params, $rechParams, $limitParams));
        $employes = $stmt->fetchAll();
    }

    // Dernière fiche de salaire par employé (seulement pour les employés affichés).
    $derniere = [];
    if ($employes) {
        $empIds = array_column($employes, 'id');
        $inPlh  = sql_in($empIds);
        $q = db()->prepare(
            "SELECT employe_id, annee, mois, salaire_brut, salaire_net
             FROM fiches WHERE employe_id IN ($inPlh) ORDER BY annee DESC, mois DESC"
        );
        $q->execute($empIds);
        foreach ($q as $r) {
            $eid = (int) $r['employe_id'];
            if (!isset($derniere[$eid])) {
                $derniere[$eid] = $r;
            }
        }
    }
    // Le filtre doit survivre à la pagination et à la recherche : il voyage
    // dans les liens de page, au format attendu par filtre_coche() (marqueur
    // « _set », sans quoi il retomberait sur la session au clic suivant).
    $pgParams = $recherche !== '' ? ['q' => $recherche] : [];
    if ($actif) {
        $pgParams['actif']     = $actif;
        $pgParams['actif_set'] = 1;
    }
    // Trois vues en une : la liste, et « duo » — la liste en colonne étroite
    // avec la fiche choisie à droite (views/_duo.php). Mémorisée comme sur
    // ?p=structures : un lien « Employés » sans ?vue= rouvre la dernière.
    $vue = vue_liste('employes_vue');
    $duo = duo_selection($vue, $employes, 'employe_donnees_vue');

    render('employes', array_merge($duo, [
        'vue' => $vue, 'duoSel' => $duo['duoSel'],
        'employes' => $employes, 'derniere' => $derniere, 'recherche' => $recherche,
        'modeClient' => $modeClient, 'actif' => $actif, 'tri' => $tri,
        'pgRoute' => 'employes', 'pgParams' => $pgParams,
        'pgPage' => $pgPage, 'pgTaille' => $pgTaille, 'pgTotal' => $pgTotal]), 'Employés');
}

// Les données de la fiche d'un employé : la page pleine (route_employe())
// comme le volet droit de la vue deux colonnes (route_employes(), ?vue=duo)
// les lisent ici. Rend null quand l'identifiant ne désigne personne.
// ⚠️ EMPLOYE_SELECT/EMPLOYE_FROM : l'identité vit sur personnes, un SELECT *
// rendrait des noms vides (voir CLAUDE.md § Personnes).
function employe_donnees_vue(int $id): ?array
{
    $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM fiches WHERE employe_id = ? ORDER BY annee DESC, mois DESC');
    $stmt->execute([$id]);
    return ['emp' => $emp, 'fiches' => $stmt->fetchAll()];
}

function route_employe(): void
{
    require_login();
    $donnees = employe_donnees_vue((int) ($_GET['id'] ?? 0));
    if ($donnees === null) {
        redirect('employes');
    }
    render('employe', $donnees, $donnees['emp']['prenom'] . ' ' . $donnees['emp']['nom']);
}

// Pastille d'identité d'un employé (?p=employe) : une couleur choisie dans
// la palette, ou une photo recadrée. Trois actions exclusives, distinguées par
// « action » — une seule route plutôt que trois, elles écrivent les deux mêmes
// colonnes et partagent le nettoyage de l'ancienne photo.
function route_employe_photo(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('employes');
    }
    check_csrf();
    $stmt = db()->prepare('SELECT e.id, pe.prenom, pe.nom, e.avatar_photo' . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        redirect('employes');
    }
    $ancienne = (string) $emp['avatar_photo'];
    $action = (string) ($_POST['action'] ?? '');
    $err = null;

    if ($action === 'couleur') {
        // La valeur part dans un attribut style : hors palette, elle est
        // ignorée plutôt qu'écrite (avatar_teinte() refuserait de toute façon
        // à l'affichage, mais autant ne pas stocker n'importe quoi).
        $couleur = strtolower(trim((string) ($_POST['couleur'] ?? '')));
        if (!in_array($couleur, AVATAR_TEINTES, true)) {
            $couleur = '';
        }
        db()->prepare('UPDATE employes SET avatar_couleur = ? WHERE id = ?')->execute([$couleur, $id]);
    } elseif ($action === 'photo') {
        try {
            $chemin = avatar_photo_enregistrer((string) ($_POST['photo_data'] ?? ''));
            db()->prepare('UPDATE employes SET avatar_photo = ? WHERE id = ?')->execute([$chemin, $id]);
            avatar_photo_supprimer($ancienne);
        } catch (RuntimeException $ex) {
            $err = $ex->getMessage();
        }
    } elseif ($action === 'photo_supprimer') {
        db()->prepare("UPDATE employes SET avatar_photo = '' WHERE id = ?")->execute([$id]);
        avatar_photo_supprimer($ancienne);
    }

    redirect('employe', $err === null ? ['id' => $id] : ['id' => $id, 'err_avatar' => $err]);
}

// Les personnes de l'annuaire qui n'ont pas encore d'emploi : celles qu'un
// nouvel employé peut reprendre plutôt que de dupliquer leur identité.
function personnes_sans_emploi(): array
{
    return db()->query('SELECT id, prenom, nom, email, rue, npa_localite, date_naissance FROM personnes
        WHERE id NOT IN (SELECT personne_id FROM employes WHERE personne_id IS NOT NULL)
        ORDER BY nom COLLATE NOCASE, prenom COLLATE NOCASE')->fetchAll();
}

function route_employe_form(): void
{
    require_login();
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $emp = null;
    if ($id) {
        $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if (!$emp) {
            redirect('employes');
        }
        // Le n° AVS est chiffré en base (lib/secrets.php § N° AVS) : le
        // formulaire le montre en clair. Illisible (base restaurée sans sa
        // clé), le champ part vide et un champ laissé vide ne l'efface pas.
        $avsStocke = (string) $emp['numero_avs'];
        $emp['avs_illisible'] = avs_illisible($avsStocke);
        $emp['numero_avs'] = avs_lire($avsStocke);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = [
            'prenom'              => trim($_POST['prenom'] ?? ''),
            'nom'                 => trim($_POST['nom'] ?? ''),
            'email'               => trim($_POST['email'] ?? ''),
            'rue'                 => trim($_POST['rue'] ?? ''),
            'npa_localite'        => trim($_POST['npa_localite'] ?? ''),
            'numero_avs'          => trim($_POST['numero_avs'] ?? ''),
            'date_naissance'      => trim($_POST['date_naissance'] ?? ''),
            'canton'              => $_POST['canton'] ?? 'Genève',
            'procedure'           => $_POST['procedure'] ?? 'Ordinaire',
            // Supplément : select avec valeurs en fraction (0.0833…) → stocké tel quel
            'supplement_vacances' => (float) ($_POST['supplement_vacances'] ?? '0'),
            'impot_source_taux'   => (float) str_replace(',', '.', $_POST['impot_source_taux'] ?? '0') / 100,
            'actif'               => isset($_POST['actif']) ? 1 : 0,
        ];
        $err = null;
        if ($champs['prenom'] === '' || $champs['nom'] === '') {
            $err = 'Prénom et nom sont obligatoires.';
        } elseif ($champs['email'] !== '' && !filter_var($champs['email'], FILTER_VALIDATE_EMAIL)) {
            $err = 'Adresse e-mail invalide.';
        } elseif ($champs['numero_avs'] !== '' && !avs_valide($champs['numero_avs'])) {
            $err = 'Numéro AVS invalide (format attendu : 756.XXXX.XXXX.XX).';
        }
        // Personne existante (création seulement) : l'emploi s'y rattache au
        // lieu d'en créer une seconde. Une personne n'a qu'un emploi — la
        // liste ne propose que celles qui n'en ont pas, et le serveur le
        // revérifie (la liste a pu vieillir depuis l'ouverture de la page).
        $personneExistante = $id ? 0 : (int) ($_POST['personne_id'] ?? 0);
        if (!$err && $personneExistante) {
            $stmtP = db()->prepare('SELECT (SELECT COUNT(*) FROM personnes WHERE id = :id),
                                           (SELECT COUNT(*) FROM employes WHERE personne_id = :id)');
            $stmtP->execute(['id' => $personneExistante]);
            [$existe, $dejaEmploye] = array_map('intval', $stmtP->fetch(PDO::FETCH_NUM));
            if (!$existe) {
                $personneExistante = 0;
            } elseif ($dejaEmploye) {
                $err = 'Cette personne est déjà employée.';
            }
        }
        if ($err) {
            render('employe_form', [
                'emp' => array_merge((array) $emp, $champs, $id ? [] : ['personne_id' => $personneExistante]),
                'err' => $err,
                'personnesDispo' => $id ? [] : personnes_sans_emploi(),
            ], 'Employé');
            return;
        }
        if (!in_array($champs['canton'], CANTONS, true)) {
            $champs['canton'] = 'Genève';
        }
        if (!in_array($champs['procedure'], PROCEDURES, true)) {
            $champs['procedure'] = 'Ordinaire';
        }
        // L'identité d'un côté, l'EMPLOI de l'autre : prénom, nom, adresse et
        // date de naissance vivent sur personnes depuis la migration 100, le
        // numéro AVS et le taux horaire restent ici. Enregistrer un employé
        // écrit donc dans deux tables.
        //
        // ⚠️ Les fiches déjà enregistrées ne bougent pas : elles FIGENT leur
        // copie de l'identité (fiches.employe_nom/employe_rue/employe_npa/
        // employe_avs). Corriger une adresse ne réécrit aucun historique.
        $sqlEmploi = 'numero_avs=:numero_avs, canton=:canton, procedure=:procedure,
                      supplement_vacances=:supplement_vacances,
                      impot_source_taux=:impot_source_taux, actif=:actif';
        $emploi = [
            'numero_avs'          => ($champs['numero_avs'] === '' && !empty($emp['avs_illisible']))
                ? $avsStocke : avs_stocker($champs['numero_avs']),
            'canton'              => $champs['canton'],
            'procedure'           => $champs['procedure'],
            'supplement_vacances' => $champs['supplement_vacances'],
            'impot_source_taux'   => $champs['impot_source_taux'],
            'actif'               => $champs['actif'],
        ];
        if ($id) {
            $personneId = (int) ($emp['personne_id'] ?? 0);
            if ($personneId) {
                personne_enregistrer($personneId, $champs);
            } else {
                // Un employé d'avant la migration qui aurait perdu son lien :
                // on lui en rend un plutôt que de laisser son nom nulle part.
                $personneId = personne_creer($champs);
                db()->prepare('UPDATE employes SET personne_id = ? WHERE id = ?')->execute([$personneId, $id]);
            }
            db()->prepare('UPDATE employes SET ' . $sqlEmploi . ' WHERE id=:id')
                ->execute($emploi + ['id' => $id]);
        } else {
            // La saisie fait foi aussi pour une personne reprise : les champs
            // ont été préremplis depuis sa fiche, ce qu'on y a corrigé l'est
            // pour tous les écrans qui la montrent.
            if ($personneExistante) {
                $personneId = $personneExistante;
                personne_enregistrer($personneId, $champs);
            } else {
                $personneId = personne_creer($champs);
            }
            db()->prepare(
                'INSERT INTO employes (personne_id, numero_avs, canton, procedure, supplement_vacances, impot_source_taux, actif)
                 VALUES (:personne_id, :numero_avs, :canton, :procedure, :supplement_vacances, :impot_source_taux, :actif)'
            )->execute($emploi + ['personne_id' => $personneId]);
        }
        redirect('employes');
    }
    // Un employé qui a des fiches ne se supprime pas (route_employe_supprimer() le
    // refuse) : la corbeille de l'écran de modification n'a donc pas à s'y
    // afficher. Compté ici plutôt que dans la vue — une vue n'interroge pas la
    // base.
    $nbFichesEmp = 0;
    if ($id) {
        $stmtNb = db()->prepare('SELECT COUNT(*) FROM fiches WHERE employe_id = ?');
        $stmtNb->execute([$id]);
        $nbFichesEmp = (int) $stmtNb->fetchColumn();
    }
    // À l'affichage, l'override saisi en % : on ne touche pas aux valeurs stockées (fractions)
    render('employe_form', [
        'emp' => $emp, 'err' => null, 'nbFiches' => $nbFichesEmp,
        'personnesDispo' => $id ? [] : personnes_sans_emploi(),
    ], $id ? 'Modifier employé' : 'Nouvel employé');
}

function route_employe_supprimer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (supprimer_si_non_reference('employes', $id, 'fiches', 'employe_id')) {
            redirect('employes');
        }
        // A des fiches → suppression refusée
        redirect('employe', ['id' => $id, 'err' => 'fiches']);
    }
    redirect('employes');
}

function route_taux_horaires(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            $libelle = trim($_POST['th_libelle'] ?? '');
            $montant = (float) str_replace(',', '.', $_POST['th_montant'] ?? '0');
            if ($libelle !== '' && $montant > 0) {
                db()->prepare('INSERT INTO taux_horaires (libelle, montant) VALUES (?, ?)')->execute([$libelle, $montant]);
            }
        } elseif ($section === 'del') {
            db()->prepare('DELETE FROM taux_horaires WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        } elseif ($section === 'th_rename') {
            $libelle = trim($_POST['th_libelle'] ?? '');
            if ($libelle !== '') {
                db()->prepare('UPDATE taux_horaires SET libelle = ? WHERE id = ?')
                    ->execute([$libelle, (int) ($_POST['id'] ?? 0)]);
            }
        } elseif ($section === 'unite_add') {
            $libelle = trim($_POST['u_libelle'] ?? '');
            $heures  = (float) str_replace(',', '.', $_POST['u_heures'] ?? '0');
            if ($libelle !== '' && $heures > 0) {
                db()->prepare('INSERT INTO unites (libelle, heures) VALUES (?, ?)')->execute([$libelle, $heures]);
            }
        } elseif ($section === 'unite_rename') {
            $libelle = trim($_POST['u_libelle'] ?? '');
            if ($libelle !== '') {
                db()->prepare('UPDATE unites SET libelle = ? WHERE id = ?')
                    ->execute([$libelle, (int) ($_POST['id'] ?? 0)]);
            }
        } elseif ($section === 'unite_del') {
            db()->prepare('DELETE FROM unites WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        }
        redirect('taux_horaires', ['ok' => 1]);
    }
    render('taux_horaires', [
        'saved'        => isset($_GET['ok']),
        'tauxHoraires' => db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll(),
        'unites'       => db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll(),
    ], 'Salaires horaires et unités');
}

// Import de fiches de salaire depuis un fichier JSON (format d'export, type
// « fiches_salaire »). N'insère que les fiches nouvelles : une fiche déjà
// présente (même employé/année/mois) est ignorée, jamais écrasée (historique figé).
function route_fiches_importer(): void
{
    require_login();
    $err = null; $resultats = null; $resume = null; $simule = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $simule = !isset($_POST['appliquer']); // bouton « Simuler » vs « Importer »
        // Source du contenu : fichier téléversé, ou contenu mémorisé en session
        // (permet de cliquer « Importer » après « Simuler » sans re-téléverser).
        $r = lire_fichier_importe(2 * 1024 * 1024, 'Fichier trop volumineux (2 Mo maximum).', 'import_fiches_json', 'Veuillez choisir un fichier JSON à importer.');
        $err  = $r['err'];
        $json = $r['contenu'];
        if ($err === null) {
            $doc = json_decode((string) $json, true);
            if (!is_array($doc) || ($doc['type'] ?? '') !== 'fiches_salaire' || !is_array($doc['fiches'] ?? null)) {
                $err = 'Fichier non reconnu : un export de fiches de salaire (JSON) est attendu.';
                unset($_SESSION['import_fiches_json']);
            } else {
                try {
                    [$resultats, $resume] = importer_fiches_salaire($doc['fiches'], $simule);
                    // Mémorise le contenu pour l'étape « Importer » qui suit une simulation.
                    if ($simule) {
                        $_SESSION['import_fiches_json'] = $json;
                    } else {
                        unset($_SESSION['import_fiches_json']);
                    }
                } catch (Throwable $e) {
                    $err = "Erreur pendant l'import : " . $e->getMessage();
                }
            }
        }
    }
    rendre_import([
        'errFiches' => $err, 'resultatsFiches' => $resultats, 'resumeFiches' => $resume, 'simuleFiches' => $simule,
    ]);
}

// Évalue (et, si !$simule, insère) une liste de fiches. Correspondance employé
// par numéro AVS. Renvoie [resultats par ligne, résumé chiffré].
function importer_fiches_salaire(array $fiches, bool $simule): array
{
    $existe  = db()->prepare('SELECT id FROM fiches WHERE employe_id = ? AND annee = ? AND mois = ?');
    $resultats = [];
    $resume = ['total' => 0, 'nouvelles' => 0, 'existantes' => 0, 'erreurs' => 0];

    if (!$simule) {
        db()->beginTransaction();
    }
    try {
        foreach ($fiches as $f) {
            $resume['total']++;
            $avs   = str_replace(' ', '', (string) ($f['employe_avs'] ?? ($f['employe']['numero_avs'] ?? '')));
            $annee = (int) ($f['annee'] ?? 0);
            $mois  = (int) ($f['mois'] ?? 0);
            $nom   = (string) ($f['employe_nom'] ?? ($f['employe']['nom_complet'] ?? ''));
            $ligne = ['nom' => $nom, 'annee' => $annee, 'mois' => $mois,
                      'brut' => (float) ($f['salaire_brut'] ?? 0), 'net' => (float) ($f['salaire_net'] ?? 0)];

            if ($avs === '' || $annee < 2000 || $mois < 1 || $mois > 12) {
                $ligne['statut'] = 'erreur';
                $ligne['detail'] = 'Données manquantes (AVS, année ou mois).';
                $resume['erreurs']++; $resultats[] = $ligne; continue;
            }
            // Le n° AVS est chiffré en base : la correspondance se fait en PHP.
            $empId = avs_trouver_employe($avs);
            if ($empId === null) {
                $ligne['statut'] = 'erreur';
                $ligne['detail'] = "Aucun employé avec le numéro AVS $avs.";
                $resume['erreurs']++; $resultats[] = $ligne; continue;
            }
            $existe->execute([(int) $empId, $annee, $mois]);
            if ($existe->fetch()) {
                $ligne['statut'] = 'existante';
                $ligne['detail'] = 'Fiche déjà présente — ignorée.';
                $resume['existantes']++; $resultats[] = $ligne; continue;
            }
            $ligne['statut'] = 'nouvelle';
            $resume['nouvelles']++;
            if (!$simule) {
                $num = fn(string $k) => (float) ($f[$k] ?? 0);
                $data = [
                    'employe_id' => (int) $empId, 'annee' => $annee, 'mois' => $mois,
                    'date_paiement' => (string) ($f['date_paiement'] ?? ''),
                    'employe_nom' => $nom,
                    'employe_rue' => (string) ($f['employe_rue'] ?? ''),
                    'employe_npa' => (string) ($f['employe_npa'] ?? ''),
                    'employe_avs' => avs_stocker((string) ($f['employe_avs'] ?? $avs)),
                    'canton' => (string) ($f['canton'] ?? ''),
                    'procedure' => (string) ($f['procedure'] ?? 'Ordinaire'),
                    'salaire_horaire' => $num('salaire_horaire'), 'nombre_heures' => $num('nombre_heures'),
                    'supplement_taux' => $num('supplement_taux'), 'salaire_travail' => $num('salaire_travail'),
                    'supplement_montant' => $num('supplement_montant'), 'salaire_brut' => $num('salaire_brut'),
                    'ded_avs' => $num('ded_avs'), 'ded_ac' => $num('ded_ac'), 'ded_amat' => $num('ded_amat'),
                    'ded_laa' => $num('ded_laa'), 'ded_lpp' => $num('ded_lpp'),
                    'ded_impot_source' => $num('ded_impot_source'), 'ded_caf' => $num('ded_caf'),
                    'total_deductions' => $num('total_deductions'), 'salaire_net' => $num('salaire_net'),
                    'emp_avs' => $num('emp_avs'), 'emp_ac' => $num('emp_ac'), 'emp_amat' => $num('emp_amat'),
                    'emp_af' => $num('emp_af'), 'emp_laa' => $num('emp_laa'), 'emp_frais' => $num('emp_frais'),
                    'emp_cpe' => $num('emp_cpe'), 'emp_lfp' => $num('emp_lfp'), 'emp_lpp' => $num('emp_lpp'),
                    'total_charges_emp' => $num('total_charges_emp'), 'cout_total_emp' => $num('cout_total_emp'),
                    'afficher_cout_emp' => 0,
                    'taux_json' => json_encode((object) ($f['taux'] ?? []), JSON_UNESCAPED_UNICODE),
                ];
                $ph = ':' . implode(', :', array_keys($data));
                db()->prepare('INSERT INTO fiches (' . implode(', ', array_keys($data)) . ') VALUES (' . $ph . ')')
                    ->execute($data);
                $ficheId = (int) db()->lastInsertId();
                $insL = db()->prepare('INSERT INTO fiche_lignes (fiche_id, libelle, heures_unite, quantite, taux_horaire, ordre) VALUES (?, ?, ?, ?, ?, ?)');
                $ordre = 0;
                foreach ((array) ($f['lignes'] ?? []) as $l) {
                    $insL->execute([$ficheId, (string) ($l['libelle'] ?? 'Heures de travail'),
                        (float) ($l['heures_unite'] ?? 1), (float) ($l['quantite'] ?? 0),
                        (float) ($l['taux_horaire'] ?? 0), $ordre++]);
                }
            }
            $resultats[] = $ligne;
        }
        if (!$simule) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if (!$simule && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return [$resultats, $resume];
}

// Paliers d'âge d'une année, par poste : [poste_id => [[min, max, valeur], …]].
// Repli sur la dernière année configurée avant elle, comme les taux — sinon
// ouvrir une nouvelle année afficherait une grille vide à remplir de zéro.
function baremes_age_annee(int $annee): array
{
    $stmt = db()->prepare(
        'SELECT b.poste_id, b.age_min, b.age_max, b.valeur
           FROM poste_bareme_age b
          WHERE b.annee = (SELECT MAX(annee) FROM poste_bareme_age
                            WHERE poste_id = b.poste_id AND annee <= ?)
          ORDER BY b.poste_id, b.age_min'
    );
    $stmt->execute([$annee]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int) $r['poste_id']][] = [(int) $r['age_min'], (int) $r['age_max'], (float) $r['valeur']];
    }
    return $out;
}

// Réécrit les paliers d'âge d'UN poste pour une année : c'est une grille, on la
// remplace en entier plutôt que de tenter des mises à jour ligne à ligne.
// $paliers : [['min' =>, 'max' =>, 'taux' =>], …].
function enregistrer_baremes_age(int $posteId, int $annee, array $paliers): void
{
    db()->prepare('DELETE FROM poste_bareme_age WHERE poste_id = ? AND annee = ?')
        ->execute([$posteId, $annee]);
    $ins = db()->prepare(
        'INSERT INTO poste_bareme_age (poste_id, annee, age_min, age_max, valeur) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($paliers as $palier) {
        $min = (int) ($palier['min'] ?? 0);
        $max = (int) ($palier['max'] ?? 0);
        $val = (float) str_replace(',', '.', (string) ($palier['taux'] ?? '0')) / 100;
        if ($max < $min || ($min === 0 && $max === 0)) {
            continue; // ligne laissée vide, ou bornes incohérentes
        }
        $ins->execute([$posteId, $annee, $min, $max, $val]);
    }
}

// ------------------------------------------------------- RECALCUL DE FICHES
//
// Une fiche fige ses montants ET ses taux à la création : changer un taux ou un
// poste ne réécrit RIEN. Le recalcul est donc un geste explicite, ici, sur des
// fiches choisies une par une — avec un aperçu avant/après, un avertissement
// sur le nombre de fiches touchées, et une sauvegarde automatique de la base
// avant d'écrire. Les fiches déjà payées ne sont jamais cochées par défaut.

// Recalcule une fiche avec les postes et les taux ACTUELS de son année, à
// partir de ses propres lignes de prestation et de son propre instantané
// employé (supplément vacances, procédure, taux d'impôt à la source figé).
// N'écrit rien : rend [montants, taux].
function recalculer_fiche(array $f): array
{
    $stmt = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ?');
    $stmt->execute([(int) $f['id']]);
    $heures = 0.0;
    $salaireTravail = 0.0;
    foreach ($stmt as $l) {
        $h = (float) $l['heures_unite'] * (float) $l['quantite'];
        $heures += $h;
        $salaireTravail += $h * (float) $l['taux_horaire'];
    }
    $annee = (int) $f['annee'];
    $mois  = (int) $f['mois'];
    $fige  = json_decode((string) $f['taux_json'], true) ?: [];
    // La date de naissance ne fait pas partie de l'instantané de la fiche : les
    // barèmes d'âge la relisent sur l'employé.
    $st = db()->prepare('SELECT pe.date_naissance' . EMPLOYE_FROM . ' WHERE e.id = ?');
    $st->execute([(int) $f['employe_id']]);
    $emp = [
        'supplement_vacances' => (float) $f['supplement_taux'],
        'procedure'           => (string) $f['procedure'],
        // Le taux d'impôt à la source reste celui figé sur la fiche : il est
        // propre à l'employé et à sa situation d'alors, pas à la grille.
        'impot_source_taux'   => (float) ($fige['impot_source'] ?? 0),
        'date_naissance'      => (string) ($st->fetchColumn() ?: ''),
    ];
    $taux = taux_pour_annee($annee);
    $taux = array_merge($taux, laa_effectif($taux, $heures, $annee, $mois));
    $taux = array_merge($taux, bareme_age_effectif($emp, $annee, $mois));
    $c = calculer_fiche($emp, $salaireTravail, $taux);
    return [$c, $taux + ['impot_source' => $emp['impot_source_taux']]];
}

// Colonnes de la fiche qu'un recalcul réécrit. Les instantanés (nom, adresse,
// procédure, salaire horaire) et la date de paiement n'en font pas partie.
const RECALCUL_COLONNES = [
    'salaire_travail', 'supplement_montant', 'salaire_brut',
    'ded_avs', 'ded_ac', 'ded_amat', 'ded_laa', 'ded_lpp', 'ded_impot_source', 'ded_caf',
    'total_deductions', 'salaire_net',
    'emp_avs', 'emp_ac', 'emp_amat', 'emp_af', 'emp_laa',
    'emp_frais', 'emp_cpe', 'emp_lfp', 'emp_lpp',
    'total_charges_emp', 'cout_total_emp',
];

function route_fiches_recalculer(): void
{
    require_login();
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $annee = (int) ($_POST['annee'] ?? date('Y'));
        $ids = array_values(array_unique(array_map('intval', (array) ($_POST['fiches'] ?? []))));
        if (!$ids) {
            redirect('fiches_recalculer', ['annee' => $annee, 'vide' => 1]);
        }
        // Borné à l'année affichée : on ne réécrit que ce que l'aperçu a montré.
        $stmt = db()->prepare('SELECT * FROM fiches WHERE annee = ? AND id IN (' . sql_in($ids) . ')');
        $stmt->execute(array_merge([$annee], $ids));
        $fiches = $stmt->fetchAll();
        if (!$fiches) {
            redirect('fiches_recalculer', ['annee' => $annee, 'vide' => 1]);
        }

        // Sauvegarde AVANT d'écrire : un recalcul touche des documents déjà
        // remis à des employés, on veut pouvoir revenir en arrière.
        $sauvegarde = sauvegarder_base('avant_recalcul');
        $set = implode(' = ?, ', RECALCUL_COLONNES) . ' = ?, taux_json = ?';
        $upd = db()->prepare("UPDATE fiches SET $set WHERE id = ?");
        db()->beginTransaction();
        foreach ($fiches as $f) {
            [$c, $taux] = recalculer_fiche($f);
            $vals = array_map(fn ($col) => (float) ($c[$col] ?? 0), RECALCUL_COLONNES);
            $vals[] = json_encode((object) $taux, JSON_UNESCAPED_UNICODE);
            $vals[] = (int) $f['id'];
            $upd->execute($vals);
            fiche_postes_ecrire((int) $f['id'], $c, $taux);
        }
        db()->commit();
        redirect('fiches_recalculer', ['annee' => $annee, 'faites' => count($fiches),
                                     'sauv' => $sauvegarde ? basename($sauvegarde) : '']);
    }

    $stmt = db()->prepare(
        'SELECT f.*, pe.prenom, pe.nom FROM fiches f
           JOIN employes e ON e.id = f.employe_id
           LEFT JOIN personnes pe ON pe.id = e.personne_id
          WHERE f.annee = ? ORDER BY f.mois, pe.nom, pe.prenom'
    );
    $stmt->execute([$annee]);
    $lignes = [];
    foreach ($stmt->fetchAll() as $f) {
        [$c] = recalculer_fiche($f);
        $ecarts = [];
        foreach (RECALCUL_COLONNES as $col) {
            if (abs((float) ($c[$col] ?? 0) - (float) ($f[$col] ?? 0)) > 0.005) {
                $ecarts[$col] = [(float) $f[$col], (float) $c[$col]];
            }
        }
        $lignes[] = ['fiche' => $f, 'apres' => $c, 'ecarts' => $ecarts,
                     'payee' => trim((string) $f['date_paiement']) !== ''];
    }
    $annees = array_map('intval', db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($annee, $annees, true)) {
        $annees[] = $annee;
        rsort($annees);
    }
    render('fiches_recalculer', [
        'annee'  => $annee,
        'annees' => $annees,
        'lignes' => $lignes,
        'faites' => (int) ($_GET['faites'] ?? 0),
        'sauv'   => (string) ($_GET['sauv'] ?? ''),
        'vide'   => isset($_GET['vide']),
    ], 'Recalcul des fiches');
}

// Taux d'un poste pour une année, depuis son formulaire d'édition. Un poste au
// barème d'âge ou au taux propre à l'employé n'en a pas : il n'y a rien à
// écrire, et surtout rien à écraser.
function enregistrer_poste_taux(int $id, int $annee, array $post): void
{
    $st = db()->prepare('SELECT mode FROM postes_salariaux WHERE id = ?');
    $st->execute([$id]);
    $mode = (string) $st->fetchColumn();
    if ($mode === '' || $mode === 'taux_employe' || $mode === 'bareme_age') {
        return;
    }
    $pct = fn (string $v): float => (float) str_replace(',', '.', $v) / 100;
    db()->prepare('INSERT OR REPLACE INTO poste_taux (poste_id, annee, valeur, valeur_alt) VALUES (?, ?, ?, ?)')
        ->execute([$id, $annee, $pct((string) ($post['valeur'] ?? '0')),
            $mode === 'laa_seuil' ? $pct((string) ($post['valeur_alt'] ?? '0')) : null]);
}

// ------------------------------------------------------- POSTES SALARIAUX
// Les lignes d'un décompte : ce que l'employeur peut ajouter, renommer,
// réordonner ou désactiver. Ne touche JAMAIS aux fiches déjà enregistrées, qui
// portent leur propre copie (fiche_postes) — d'où le recalcul explicite, à part.
function route_postes(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            // Un code déjà pris ne crée rien (INSERT OR IGNORE) : sans ce
            // retour, l'écran affichait « Enregistré » et la ligne manquait.
            if (!enregistrer_poste(0, $_POST)) {
                redirect('postes', ['annee' => (int) ($_POST['annee'] ?? date('Y')), 'err' => 'code']);
            }
        } elseif ($section === 'edit') {
            // Un seul enregistrement pour toute la ligne : sa définition, son
            // taux de l'année affichée, et « masquer à 0 ». C'est le mode
            // édition qui ouvre l'ensemble, rien ne se modifie en lecture.
            $id = (int) ($_POST['id'] ?? 0);
            enregistrer_poste($id, $_POST);
            enregistrer_poste_taux($id, (int) ($_POST['annee'] ?? date('Y')), $_POST);
            // Les paliers d'âge se saisissent dans l'édition de LEUR ligne :
            // ils n'ont de sens que pour elle, et pour l'année affichée.
            if (($_POST['mode'] ?? '') === 'bareme_age') {
                enregistrer_baremes_age($id, (int) ($_POST['annee'] ?? date('Y')),
                    (array) ($_POST['bareme'] ?? []));
                db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                    ->execute(['lpp_age_reference',
                        ($_POST['lpp_age_reference'] ?? '') === 'anniversaire' ? 'anniversaire' : 'annee']);
            }
        } elseif ($section === 'reglages') {
            // Le seul réglage qui vaut pour toute l'année sans appartenir à une
            // ligne : la base « salaire coordonné », partagée par les postes
            // qui s'y adossent.
            $annee = (int) ($_POST['annee'] ?? date('Y'));
            $chf = fn (string $v): float => (float) str_replace([',', "'", ' '], ['.', '', ''], $v);
            $insT = db()->prepare('INSERT OR REPLACE INTO taux_par_annee (annee, cle, valeur) VALUES (?, ?, ?)');
            db()->beginTransaction();
            foreach (['coord_deduction', 'coord_plafond'] as $k) {
                $insT->execute([$annee, $k, (string) $chf((string) ($_POST[$k] ?? '0'))]);
            }
            db()->commit();
            redirect('postes', ['annee' => $annee, 'ok' => 1]);
        } elseif ($section === 'toggle_actif') {
            // Le seul réglage qui s'applique en lecture : allumer ou éteindre
            // une ligne, comme pour un axe analytique (?p=compta_axes).
            db()->prepare('UPDATE postes_salariaux SET actif = ? WHERE id = ?')
                ->execute([isset($_POST['actif']) ? 1 : 0, (int) ($_POST['id'] ?? 0)]);
            // Envoi parti en arrière-plan : rien à re-rendre, l'état visible est
            // déjà celui de l'interrupteur (assets/app.js, data-ajax).
            if (($_POST['retour'] ?? '') === 'json') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                return;
            }
        } elseif ($section === 'reorder') {
            // Glisser-déposer : la liste est plate, on renumérote dans l'ordre
            // reçu (le poste déplacé compris).
            $order = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['order'] ?? '')))));
            if ($order) {
                $upd = db()->prepare('UPDATE postes_salariaux SET ordre = ? WHERE id = ?');
                db()->beginTransaction();
                foreach ($order as $i => $pid) {
                    $upd->execute([($i + 1) * 10, $pid]);
                }
                db()->commit();
            }
            // En JSON quand le JavaScript est là : la ligne se déplace dans le
            // document sans recharger la page (lassoOrdreListe(), app.js).
            if (($_POST['retour'] ?? '') === 'json') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                return;
            }
        } elseif ($section === 'del') {
            // Un poste déjà figé dans une fiche ne se supprime pas : le
            // désactiver suffit, et l'historique garde son libellé.
            $id = (int) ($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT COUNT(*) FROM fiche_postes WHERE poste_id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() === 0) {
                db()->prepare('DELETE FROM postes_salariaux WHERE id = ?')->execute([$id]);
            } else {
                db()->prepare('UPDATE postes_salariaux SET actif = 0 WHERE id = ?')->execute([$id]);
            }
        }
        postes_oublier();
        redirect('postes', ['annee' => (int) ($_POST['annee'] ?? date('Y')), 'ok' => 1]);
    }
    $postes = db()->query('SELECT * FROM postes_salariaux ORDER BY ordre, id')->fetchAll();
    $st = db()->query('SELECT poste_id, COUNT(*) n FROM fiche_postes GROUP BY poste_id');
    $usages = [];
    foreach ($st as $r) {
        $usages[(int) $r['poste_id']] = (int) $r['n'];
    }
    // Une ligne et ce qu'elle prélève se lisent — et se règlent — au même
    // endroit : le taux appartient à une année, d'où le sélecteur d'année.
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    $anneesTaux   = array_map('intval', db()->query('SELECT DISTINCT annee FROM poste_taux')->fetchAll(PDO::FETCH_COLUMN));
    $anneesFiches = array_map('intval', db()->query('SELECT DISTINCT annee FROM fiches')->fetchAll(PDO::FETCH_COLUMN));
    $annees = array_unique(array_merge($anneesTaux, $anneesFiches,
        [$annee, (int) date('Y') - 1, (int) date('Y'), (int) date('Y') + 1]));
    rsort($annees);
    render('postes', [
        'saved'      => isset($_GET['ok']),
        'err'        => ($_GET['err'] ?? '') === 'code'
            ? 'Ce code est déjà utilisé par une autre ligne — choisissez-en un autre.' : null,
        'postes'     => $postes,
        'usages'     => $usages,
        'annee'      => $annee,
        'annees'     => $annees,
        'taux'       => taux_pour_annee($annee),
        'baremes'    => baremes_age_annee($annee),
        'ageRef'     => param('lpp_age_reference', 'annee'),
        'configuree' => in_array($annee, $anneesTaux, true),
    ], 'Lignes du décompte');
}

// Crée ($id = 0) ou met à jour un poste depuis un POST. Le code n'est jamais
// modifiable après coup : c'est lui qui relie une ligne figée à son poste.
// Renvoie false si rien n'a été écrit (libellé vide, code vide ou déjà pris).
function enregistrer_poste(int $id, array $post): bool
{
    $libelle = trim((string) ($post['libelle'] ?? ''));
    if ($libelle === '') {
        return false;
    }
    $dans = fn (string $v, array $ok) => in_array($v, $ok, true) ? $v : $ok[0];
    $champs = [
        'libelle' => $libelle,
        'sens'    => $dans((string) ($post['sens'] ?? ''), ['deduction', 'charge']),
        'mode'    => $dans((string) ($post['mode'] ?? ''), ['taux', 'laa_seuil', 'taux_employe', 'bareme_age']),
        'base'    => $dans((string) ($post['base'] ?? ''), ['brut', 'coordonne']),
        'rubrique_certificat' => $dans((string) ($post['rubrique_certificat'] ?? ''), ['', '9', '10.1', '12']),
        'groupe_compta'       => trim((string) ($post['groupe_compta'] ?? '')),
        'masquer_si_zero'     => isset($post['masquer_si_zero']) ? 1 : 0,
    ];
    // « actif » et l'ordre ne passent pas par ce formulaire : ce sont
    // l'interrupteur de la liste et le glisser-déposer.
    if ($id > 0) {
        $set = implode(' = ?, ', array_keys($champs)) . ' = ?';
        db()->prepare("UPDATE postes_salariaux SET $set WHERE id = ?")
            ->execute([...array_values($champs), $id]);
        return true;
    }
    $code = strtolower(trim((string) ($post['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code);
    $code = trim((string) $code, '_');
    if ($code === '') {
        return false;
    }
    // Le préfixe « emp_ » n'est pas décoratif : c'est lui qui décide de la
    // colonne visée (poste_colonne_montant()). Une charge nommée « caf »
    // écrirait dans ded_caf, une déduction nommée « emp_frais » dans les
    // charges patronales. On aligne donc le code sur la nature de la ligne, au
    // lieu de laisser cette collision possible.
    $code = preg_replace('/^emp_/', '', $code);
    if ($champs['sens'] === 'charge') {
        $code = 'emp_' . $code;
    }
    if ($code === '' || $code === 'emp_') {
        return false;
    }
    $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) FROM postes_salariaux')->fetchColumn();
    $champs = ['code' => $code] + $champs + [
        'ordre' => $ordre + 10, // en fin de liste, à glisser ensuite
        'actif' => 1,
    ];
    $ins = db()->prepare('INSERT OR IGNORE INTO postes_salariaux (' . implode(', ', array_keys($champs)) . ')
                          VALUES (' . implode(', ', array_fill(0, count($champs), '?')) . ')');
    $ins->execute(array_values($champs));
    return $ins->rowCount() > 0;
}

// ---------------------------------------------------------------- FICHES
function route_fiches(): void
{
    require_login();
    // Filtres de colonne (EXPÉRIMENTAL — Paiement/Date/Employé) : cases à
    // cocher, 0 à N valeurs simultanées, pas un select à valeur unique — voir
    // filtre_coche() (lib/helpers.php, partagé avec compta_ecritures/
    // facturation_liste/evenements_liste) pour le détail du marqueur <nom>_set.
    $statut    = filtre_coche('statut', 'fiches_statut', ['avenir', 'apayer', 'payees']);
    $annee     = filtre_coche('annee', 'fiches_annee');
    $employeId = filtre_coche('employe_id', 'fiches_employe');
    // Recherche texte (nom/prénom employé) : voir recherche_sql(), lib/helpers.php.
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $where  = ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $where .= ' AND f.annee IN (' . sql_in($annee) . ')';
        $params = array_merge($params, $annee);
    }
    if ($statut) {
        // Synchronisé avec fiche_a_venir() (lib/helpers.php) : à venir = pas
        // encore payée ET année/mois postérieurs au mois en cours.
        $nonPayee  = "(f.date_paiement IS NULL OR f.date_paiement = '')";
        $dateVenir = "(f.annee > CAST(strftime('%Y','now') AS INTEGER)"
            . " OR (f.annee = CAST(strftime('%Y','now') AS INTEGER) AND f.mois > CAST(strftime('%m','now') AS INTEGER)))";
        $statutConds = [];
        if (in_array('avenir', $statut, true)) { $statutConds[] = "$nonPayee AND $dateVenir"; }
        if (in_array('apayer', $statut, true)) { $statutConds[] = "$nonPayee AND NOT $dateVenir"; }
        if (in_array('payees', $statut, true)) { $statutConds[] = "f.date_paiement <> ''"; }
        $where .= ' AND (' . implode(' OR ', $statutConds) . ')';
    }
    if ($employeId) {
        $where .= ' AND f.employe_id IN (' . sql_in($employeId) . ')';
        $params = array_merge($params, $employeId);
    }
    // Médaillons du tableau de bord : une fiche du mois M se verse pendant
    // M+1 (« à faire ») et n'est « en retard » qu'à partir de M+2. Les cases
    // « Paiement » ne savent pas exprimer cette nuance, qui ne vaut que pour
    // les liens de la carte : filtre d'appoint porté par l'URL seule, jamais
    // mémorisé en session — même régime que « region » sur ?p=structures.
    // Se combine avec statut[]=apayer, qui écarte déjà les payées et les
    // fiches à venir. Sans lui, le médaillon annoncerait 8 et ouvrirait 13.
    $echeance = in_array($_GET['echeance'] ?? '', ['afaire', 'retard'], true)
        ? (string) $_GET['echeance'] : '';
    if ($echeance !== '') {
        $ecoules = "((CAST(strftime('%Y','now') AS INTEGER) - f.annee) * 12"
            . " + (CAST(strftime('%m','now') AS INTEGER) - f.mois))";
        $where .= $echeance === 'afaire' ? " AND $ecoules = 1" : " AND $ecoules >= 2";
    }
    // L'identité vit sur personnes depuis la migration 100 : employes n'a plus
    // de nom. Les trois requêtes ci-dessous partagent donc la même jointure.
    [$rechSql, $rechParams] = recherche_sql(['pe.nom', 'pe.prenom']);
    $where .= $rechSql;
    $params = array_merge($params, $rechParams);

    $joinFiches = ' FROM fiches f JOIN employes e ON e.id = f.employe_id LEFT JOIN personnes pe ON pe.id = e.personne_id';
    $stmtTot = db()->prepare('SELECT COUNT(*)' . $joinFiches . $where);
    $stmtTot->execute($params);
    $pgTotal = (int) $stmtTot->fetchColumn();

    // Totaux calculés en base sur tout le résultat filtré (pas seulement la
    // page affichée) — la ligne « Total » du tableau doit rester exacte même
    // paginée.
    $stmtSum = db()->prepare(
        'SELECT COALESCE(SUM(f.salaire_brut),0) AS brut, COALESCE(SUM(f.total_deductions),0) AS ded,
                COALESCE(SUM(f.ded_impot_source),0) AS impot, COALESCE(SUM(f.salaire_net),0) AS net,
                COALESCE(SUM(f.total_charges_emp),0) AS charges_emp, COALESCE(SUM(f.cout_total_emp),0) AS cout_emp
        ' . $joinFiches . $where
    );
    $stmtSum->execute($params);
    $totaux = $stmtSum->fetch();

    // Tri de colonne. L'ordre naturel — la plus récente d'abord — est celui
    // qu'on veut en arrivant ; les montants, eux, ne se comparent qu'une fois
    // triés, d'où leur présence ici.
    $tri = tri_colonne('fiches', [
        'date'     => ['f.annee', 'f.mois'],
        'employe'  => ['pe.nom COLLATE NOCASE', 'pe.prenom COLLATE NOCASE'],
        'brut'     => 'f.salaire_brut',
        'net'      => 'f.salaire_net',
        'cout'     => 'f.cout_total_emp',
        // Une fiche payée porte une date, une fiche à payer non : trier sur la
        // date range donc aussi par état, ce qu'on cherche en cliquant.
        'paiement' => "COALESCE(NULLIF(f.date_paiement, ''), '')",
    ]);
    $orderBy = $tri['sql'] !== '' ? $tri['sql'] . ', f.id DESC' : ' ORDER BY f.annee DESC, f.mois DESC, pe.nom';

    $pgPage   = pagination_page();
    $pgTaille = pagination_taille('fiches_taille');
    [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

    // avatar_* : la pastille d'identité affichée devant le nom (liste des
    // fiches). Elle vit sur l'employé, pas sur la fiche — une fiche fige ses
    // montants, pas l'apparence de la personne.
    $sql = 'SELECT f.*, pe.prenom, pe.nom AS emp_nom_actuel, e.avatar_couleur, e.avatar_photo
            FROM fiches f JOIN employes e ON e.id = f.employe_id
                 LEFT JOIN personnes pe ON pe.id = e.personne_id' . $where;
    $sql  .= $orderBy;
    $sql  .= $limitSql;
    $stmt  = db()->prepare($sql);
    $stmt->execute(array_merge($params, $limitParams));
    $fiches = $stmt->fetchAll();
    $annees = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
    $employes = db()->query('SELECT e.id, pe.prenom, pe.nom' . EMPLOYE_FROM . ' ORDER BY pe.nom, pe.prenom')->fetchAll();

    // Axes par fiche (une seule requête groupée)
    $axesParFiche = [];
    if ($fiches && module_actif('analytique')) {
        $ficheIds = array_column($fiches, 'id');
        $inPlh = sql_in($ficheIds);
        $stmtAx = db()->prepare(
            "SELECT fl.fiche_id, GROUP_CONCAT(DISTINCT COALESCE(a.code, a.libelle)) AS axes_csv
             FROM fiche_lignes fl JOIN axes_analytiques a ON a.id = fl.axe_analytique_id
             WHERE fl.fiche_id IN ($inPlh) AND fl.axe_analytique_id IS NOT NULL
             GROUP BY fl.fiche_id"
        );
        $stmtAx->execute($ficheIds);
        foreach ($stmtAx as $row) {
            $axesParFiche[(int) $row['fiche_id']] = $row['axes_csv'];
        }
    }

    // Vue deux colonnes : la fiche choisie (?sel=), à défaut la première de la
    // liste. Mêmes données que la page pleine — fiche_donnees_vue() — pour que
    // le volet droit montre la vraie fiche et non un résumé.
    $vue = vue_liste('fiches_vue');
    $duo = duo_selection($vue, $fiches, 'fiche_donnees_vue');

    render('fiches', array_merge($duo, [
        'vue' => $vue, 'duoSel' => $duo['duoSel'],
        'fiches' => $fiches, 'annee' => $annee, 'annees' => $annees, 'statut' => $statut,
        'employes' => $employes, 'employeId' => $employeId, 'recherche' => $recherche,
        'axesParFiche' => $axesParFiche, 'totaux' => $totaux, 'tri' => $tri,
        'pgRoute' => 'fiches',
        'pgParams' => array_filter(['annee' => $annee, 'statut' => $statut, 'employe_id' => $employeId, 'q' => $recherche]),
        'pgPage' => $pgPage, 'pgTaille' => $pgTaille, 'pgTotal' => $pgTotal]), 'Fiches de salaire');
}

// Lit les lignes de prestation postées → [lignes, totalHeures, salaireTravail]
function lire_lignes_postees(): array
{
    $lignes = [];
    $heures = 0.0;
    $salaireTravail = 0.0;
    $unitesPost = $_POST['l_unite'] ?? [];
    $qtesPost   = $_POST['l_quantite'] ?? [];
    $choixPost  = $_POST['l_taux_choix'] ?? [];
    $manuelPost = $_POST['l_taux_manuel'] ?? [];
    $axesPost   = $_POST['l_axe'] ?? [];
    $evenPost   = $_POST['l_evenement'] ?? [];
    foreach ($unitesPost as $i => $enc) {
        $qte    = (float) str_replace(',', '.', $qtesPost[$i] ?? '0');
        $choix  = (string) ($choixPost[$i] ?? '');
        $taux_h = ($choix === 'autre' || $choix === '')
            ? (float) str_replace(',', '.', $manuelPost[$i] ?? '0')
            : (float) str_replace(',', '.', $choix);
        if ($qte <= 0 || $taux_h <= 0 || !str_contains((string) $enc, '|')) {
            continue;
        }
        [$hu, $lib] = explode('|', (string) $enc, 2);
        $hu = (float) str_replace(',', '.', $hu);
        if ($hu <= 0 || trim($lib) === '') {
            continue;
        }
        $h = $hu * $qte;
        $axeId = ($axesPost[$i] ?? '') !== '' ? (int) $axesPost[$i] : null;
        $evId  = ($evenPost[$i] ?? '') !== '' ? (int) $evenPost[$i] : null;
        $lignes[] = ['libelle' => trim($lib), 'heures_unite' => $hu, 'quantite' => $qte, 'taux_horaire' => $taux_h, 'axe_analytique_id' => $axeId, 'evenement_id' => $evId];
        $heures += $h;
        $salaireTravail += $h * $taux_h;
    }
    return [$lignes, $heures, $salaireTravail];
}

// Recalcule et sauvegarde une fiche (création ou mise à jour) à partir d'une
// liste de lignes de prestation déjà normalisées (libelle/heures_unite/quantite/
// taux_horaire, plus axe_analytique_id/evenement_id optionnels). $emp doit déjà
// porter les éventuelles surcharges figées (supplement_vacances/impot_source_taux)
// — cette fonction ne fait que recalculer et écrire, pas de valeurs par défaut.
// Partagée entre le formulaire de fiche complet (route_fiche_form) et l'ajout
// rapide d'une ligne de prestation depuis un événement (route_evenement_ligne_ajouter).
function sauvegarder_fiche(array $emp, int $annee, int $mois, string $datePaiement, array $lignes, ?int $ficheId, int $afficherCoutEmp = 0): int
{
    $heures = 0.0;
    $salaireTravail = 0.0;
    foreach ($lignes as $l) {
        $h = (float) $l['heures_unite'] * (float) $l['quantite'];
        $heures += $h;
        $salaireTravail += $h * (float) $l['taux_horaire'];
    }

    // Taux figés selon l'année de la fiche, puis les deux résolutions qui
    // dépendent du contexte : le seuil d'heures (LAA) et l'âge (barèmes LPP).
    $taux = taux_pour_annee($annee);
    $taux = array_merge($taux, laa_effectif($taux, $heures, $annee, $mois));
    $taux = array_merge($taux, bareme_age_effectif($emp, $annee, $mois));
    $c    = calculer_fiche($emp, $salaireTravail, $taux);

    $data = [
        'employe_id'     => (int) $emp['id'],
        'annee'          => $annee,
        'mois'           => $mois,
        'date_paiement'  => $datePaiement,
        'employe_nom'    => $emp['prenom'] . ' ' . $emp['nom'],
        'employe_rue'    => $emp['rue'],
        'employe_npa'    => $emp['npa_localite'],
        'employe_avs'    => $emp['numero_avs'],
        'canton'         => $emp['canton'],
        'procedure'      => $emp['procedure'],
        'salaire_horaire'=> $heures > 0 ? round($salaireTravail / $heures, 2) : 0, // taux moyen (référence)
        'nombre_heures'  => $heures,
        'supplement_taux'=> $emp['supplement_vacances'],
        'afficher_cout_emp' => $afficherCoutEmp,
        'taux_json'      => json_encode($taux + ['impot_source' => (float) $emp['impot_source_taux']]),
    ] + fiche_montants_stockables($c);

    $cols  = implode(',', array_keys($data));
    $marks = ':' . implode(',:', array_keys($data));

    db()->beginTransaction();
    try {
        if ($ficheId) {
            $stmt = db()->prepare('SELECT date_paiement FROM fiches WHERE id = ?');
            $stmt->execute([$ficheId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Fiche introuvable.');
            }
            if (trim((string) $existing['date_paiement']) !== '') {
                throw new RuntimeException('La fiche ne peut pas être modifiée car elle a déjà été payée.');
            }
            $updateQuery = 'UPDATE fiches SET ' . implode(',', array_map(fn($k) => "$k = :$k", array_keys($data))) . ' WHERE id = :id';
            $data['id'] = $ficheId;
            db()->prepare($updateQuery)->execute($data);
            db()->prepare('DELETE FROM fiche_lignes WHERE fiche_id = ?')->execute([$ficheId]);
        } else {
            db()->prepare("INSERT INTO fiches ($cols) VALUES ($marks)")->execute($data);
            $ficheId = (int) db()->lastInsertId();
        }
        // Copie figée des lignes du décompte, dans la même transaction que la
        // fiche : l'une sans l'autre laisserait un décompte muet.
        fiche_postes_ecrire($ficheId, $c, $taux + ['impot_source' => (float) $emp['impot_source_taux']]);

        $insL = db()->prepare('INSERT INTO fiche_lignes (fiche_id, libelle, heures_unite, quantite, taux_horaire, axe_analytique_id, evenement_id, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $ordre => $l) {
            $insL->execute([$ficheId, $l['libelle'], $l['heures_unite'], $l['quantite'], $l['taux_horaire'], $l['axe_analytique_id'] ?? null, $l['evenement_id'] ?? null, $ordre]);
        }

        // Synchronise evenement_fiches avec les evenement_id désormais présents
        // dans les lignes — une prestation peut être ajoutée/retirée/réattribuée
        // à un autre événement aussi bien depuis la fiche événement que depuis
        // ce formulaire de fiche.
        $evenementIds = array_values(array_unique(array_filter(
            array_map(fn ($l) => (int) ($l['evenement_id'] ?? 0), $lignes)
        )));
        if ($evenementIds) {
            $in = sql_in($evenementIds);
            db()->prepare("DELETE FROM evenement_fiches WHERE fiche_id = ? AND evenement_id NOT IN ($in)")
                ->execute(array_merge([$ficheId], $evenementIds));
            $insEf = db()->prepare('INSERT OR IGNORE INTO evenement_fiches (evenement_id, fiche_id) VALUES (?, ?)');
            foreach ($evenementIds as $evId) {
                $insEf->execute([$evId, $ficheId]);
            }
        } else {
            db()->prepare('DELETE FROM evenement_fiches WHERE fiche_id = ?')->execute([$ficheId]);
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
    }
    return $ficheId;
}

// Événements disponibles pour associer une ligne de prestation (module événements).
function evenements_pour_ligne(): array
{
    if (!module_actif('evenements')) {
        return [];
    }
    return db()->query(
        "SELECT e.id, e.date, e.ville, e.salle, e.festival, s.nom AS projet
         FROM evenements e LEFT JOIN projets s ON s.id = e.projet_id
         ORDER BY e.date DESC"
    )->fetchAll();
}

function route_fiche_form(): void
{
    require_login();
    $employes     = db()->query('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.actif = 1 ORDER BY pe.nom, pe.prenom')->fetchAll();
    $tauxHoraires = db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll();
    $unites       = db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll();
    $axes         = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];
    $evenements   = evenements_pour_ligne();
    $tauxData     = taux_pour_annee_js();
    $renderForm = fn($err) => render('fiche_form', [
        'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites, 'axes' => $axes,
        'evenements' => $evenements, 'err' => $err, 'post' => $_POST, 'tauxData' => $tauxData,
        'edit_mode' => isset($_POST['fiche_id']), 'fiche_id' => (int) ($_POST['fiche_id'] ?? 0),
    ], 'Nouvelle fiche');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Pré-remplissage de l'employé si on arrive depuis sa page
        $pre = isset($_GET['employe_id']) ? ['employe_id' => (int) $_GET['employe_id']] : null;
        render('fiche_form', [
            'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites, 'axes' => $axes,
            'evenements' => $evenements, 'err' => null, 'post' => $pre, 'tauxData' => $tauxData,
        ], 'Nouvelle fiche');
        return;
    }

    check_csrf();
    $empId  = (int) ($_POST['employe_id'] ?? 0);
    $annee  = (int) ($_POST['annee'] ?? date('Y'));
    $mois   = (int) ($_POST['mois'] ?? date('n'));
    $datePaiement = trim($_POST['date_paiement'] ?? '');

    [$lignes, $heures, $salaireTravail] = lire_lignes_postees();

    $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();

    $err = null;
    if (!$emp) {
        $err = 'Employé introuvable.';
    } elseif ($mois < 1 || $mois > 12) {
        $err = 'Mois invalide.';
    } elseif (!$lignes || $heures <= 0) {
        $err = 'Ajoutez au moins une prestation avec une quantité et un taux horaire supérieurs à 0.';
    }
    if ($err) {
        $renderForm($err);
        return;
    }

    // Supplément vacances : par défaut celui de l'employé, écrasable ici (saisi en %)
    $suppInput = trim($_POST['supplement_vacances'] ?? '');
    if ($suppInput !== '') {
        $emp['supplement_vacances'] = (float) str_replace(',', '.', $suppInput) / 100;
    }
    // Impôt à la source : ajustable pour ce mois (sinon valeur de l'employé)
    $impotInput = trim($_POST['impot_source_taux'] ?? '');
    if ($impotInput !== '') {
        $emp['impot_source_taux'] = (float) str_replace(',', '.', $impotInput) / 100;
    }

    $ficheIdPoste = isset($_POST['fiche_id']) ? (int) $_POST['fiche_id'] : null;
    $afficherCoutEmp = isset($_POST['afficher_cout_emp']) ? 1 : 0;
    try {
        $ficheId = sauvegarder_fiche($emp, $annee, $mois, $datePaiement, $lignes, $ficheIdPoste, $afficherCoutEmp);
    } catch (PDOException $ex) {
        if (str_contains($ex->getMessage(), 'UNIQUE')) {
            $renderForm('Une fiche existe déjà pour cet employé sur ' . mois_nom($mois) . ' ' . $annee . '.');
            return;
        }
        throw $ex;
    } catch (RuntimeException $ex) {
        $renderForm($ex->getMessage());
        return;
    }

    if ($ficheIdPoste) {
        redirect('fiche_modifier', ['id' => $ficheId, 'success' => '1']); // reste sur la page de modification
    } else {
        redirect('fiche', ['id' => $ficheId, 'success' => '1']);
    }
}

// Les données de la vue d'une fiche de salaire : la page pleine
// (route_fiche()) comme le volet droit de la vue deux colonnes
// (route_fiches(), ?vue=duo) les lisent ici — une seule fiche à tenir.
// Rend null quand l'identifiant ne désigne aucune fiche.
function fiche_donnees_vue(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        return null;
    }
    $modifiable = trim((string) $f['date_paiement']) === '';
    // E-mail de l'employé (destinataire) + expéditeur configuré, pour le bouton d'envoi.
    $stmt = db()->prepare('SELECT pe.email' . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([(int) $f['employe_id']]);
    $emailEmploye = trim((string) $stmt->fetchColumn());
    $emailExp     = trim((string) param('employeur_email_expediteur'));
    $axes = module_actif('analytique')
        ? db()->query('SELECT id, code, libelle FROM axes_analytiques WHERE actif = 1 ORDER BY code, libelle')->fetchAll()
        : [];
    // Écritures rapprochables : les DÉBITS (un salaire sort du compte) encore
    // libres, plus celle déjà liée à cette fiche. Même principe que le
    // rapprochement d'une facture (route_facture(), lib/routes_facturation.php),
    // au signe près — une facture encaisse, un salaire décaisse.
    $ecrituresLibres = [];
    if (module_accessible('compta')) {
        $stmt = db()->prepare(
            'SELECT id, date_op, texte, tiers, montant FROM ecritures
              WHERE (fiche_id IS NULL OR fiche_id = ?) AND montant < 0 ORDER BY date_op DESC'
        );
        $stmt->execute([$id]);
        $ecrituresLibres = $stmt->fetchAll();
    }
    return [
        'f' => $f, 'modifiable' => $modifiable, 'saved' => $_GET['ok'] ?? null,
        'mail' => $_GET['mail'] ?? null,
        'emailEmploye' => $emailEmploye, 'emailExp' => $emailExp,
        'axes' => $axes, 'ecrituresLibres' => $ecrituresLibres,
    ];
}

function route_fiche(): void
{
    require_login();
    $donnees = fiche_donnees_vue((int) ($_GET['id'] ?? 0));
    if ($donnees === null) {
        redirect('fiches');
    }
    $f = $donnees['f'];
    render('fiche', $donnees, 'Fiche ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']);
}

// Sauvegarde AJAX de l'axe analytique d'une ligne de fiche (modifiable même après paiement).
function route_fiche_ligne_axe_enregistrer(): void
{
    require_login();
    check_csrf();
    header('Content-Type: application/json; charset=UTF-8');
    $ligneId = (int) ($_POST['ligne_id'] ?? 0);
    $axeId   = (int) ($_POST['axe_id'] ?? 0) ?: null;
    $stmt = db()->prepare('SELECT 1 FROM fiche_lignes WHERE id = ?');
    $stmt->execute([$ligneId]);
    if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false]); return; }
    if ($axeId !== null) {
        $stmt2 = db()->prepare('SELECT 1 FROM axes_analytiques WHERE id = ? AND actif = 1');
        $stmt2->execute([$axeId]);
        if (!$stmt2->fetchColumn()) { echo json_encode(['ok' => false]); return; }
    }
    db()->prepare('UPDATE fiche_lignes SET axe_analytique_id = ? WHERE id = ?')->execute([$axeId, $ligneId]);
    echo json_encode(['ok' => true]);
}

// Agrège les fiches d'une année en totaux pour le certificat de salaire.
function agreger_certificat(array $fiches): array
{
    $champs = ['salaire_brut', 'ded_avs', 'ded_ac', 'ded_amat', 'ded_laa', 'ded_lpp',
        'ded_impot_source', 'total_deductions', 'salaire_net',
        'total_charges_emp', 'cout_total_emp'];
    $tot = array_fill_keys($champs, 0.0);
    foreach ($fiches as $f) {
        foreach ($champs as $c) {
            $tot[$c] += (float) ($f[$c] ?? 0);
        }
    }
    return $tot;
}

// Totaux par rubrique du certificat de salaire, lus sur les lignes FIGÉES des
// fiches (fiche_postes.rubrique_certificat). C'est le poste qui déclare la case
// qu'il alimente — 9 pour AVS/AC/A.mat/LAA, 10.1 pour la LPP, 12 pour l'impôt à
// la source. Sans cette déclaration, ajouter une ligne fausserait un formulaire
// officiel sans prévenir.
//
// Renvoie [] si aucune fiche n'a de copie figée : l'appelant retombe alors sur
// les colonnes, comme partout ailleurs pendant la bascule.
function agreger_rubriques_certificat(array $fiches): array
{
    $ids = array_map(fn ($f) => (int) $f['id'], $fiches);
    if (!$ids) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT rubrique_certificat AS r, SUM(montant) AS total
           FROM fiche_postes
          WHERE fiche_id IN (' . sql_in($ids) . ") AND rubrique_certificat <> ''
          GROUP BY rubrique_certificat"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt as $l) {
        $out[(string) $l['r']] = (float) $l['total'];
    }
    return $out;
}

// Charge un employé + ses fiches d'une année + les totaux. Renvoie null si employé introuvable.
function certificat_contexte(int $empId, ?int $annee): ?array
{
    $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        return null;
    }
    $stmt = db()->prepare('SELECT DISTINCT annee FROM fiches WHERE employe_id = ? ORDER BY annee DESC');
    $stmt->execute([$empId]);
    $annees = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $annee = $annee ?? ($annees[0] ?? (int) date('Y'));

    $stmt = db()->prepare('SELECT * FROM fiches WHERE employe_id = ? AND annee = ? ORDER BY mois');
    $stmt->execute([$empId, $annee]);
    $fiches = $stmt->fetchAll();

    return ['emp' => $emp, 'annee' => $annee, 'annees' => $annees,
        'fiches' => $fiches, 'tot' => agreger_certificat($fiches),
        'rubriques' => agreger_rubriques_certificat($fiches)];
}

function route_certificat(): void
{
    require_login();
    $empId = (int) ($_GET['employe_id'] ?? 0);
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : null;
    $ctx = certificat_contexte($empId, $annee);
    if ($ctx === null) {
        redirect('employes');
    }
    render('certificat', $ctx, 'Certificat ' . $ctx['emp']['prenom'] . ' ' . $ctx['emp']['nom']);
}

function route_certificat_imprimer(): void
{
    require_login();
    $empId = (int) ($_GET['employe_id'] ?? 0);
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : null;
    $ctx = certificat_contexte($empId, $annee);
    if ($ctx === null) {
        redirect('employes');
    }
    render_bare('certificat_imprimer', $ctx);
}

// Génère le XML « eCertificat de salaire CSI » (schéma eLohnausweis-ssk) pour une
// année. Si $employeId est fourni, un seul employé ; sinon tous ceux ayant des fiches.
// Montants en francs entiers (le certificat de salaire se remplit en CHF arrondis).
function build_certificat_xml(int $annee, ?int $employeId): string
{
    $NS  = 'https://www.elohnausweis-ssk.ch/de/assets/documents/SalaryDeclarationElohnOnline.xsd';
    $doc = new DOMDocument('1.0', 'UTF-8');

    $el = fn(string $name, ?string $text = null): DOMElement => dom_el($doc, $NS, $name, $text, 'sd');

    $root = $el('SalaryDeclaration');
    $root->setAttribute('schemaVersion', '0.0');
    $doc->appendChild($root);

    // ----- Company -----
    $company = $el('Company');
    $root->appendChild($company);
    $cd = $el('CompanyDescription');
    $company->appendChild($cd);
    $cd->appendChild($el('AddressPosition', 'RIGHT'));
    $name = $el('Name');
    $name->appendChild($el('HR-RC-Name', (string) param('employeur_nom')));
    $cd->appendChild($name);

    [$czip, $ccity] = split_npa((string) param('employeur_npa'));
    $addr = $el('Address');
    $addr->appendChild($el('Street', (string) param('employeur_rue')));
    $addr->appendChild($el('Postbox'));
    $addr->appendChild($el('ZIP-Code', $czip));
    $addr->appendChild($el('City', $ccity));
    $addr->appendChild($el('Country', (string) param('employeur_pays') ?: 'Suisse'));
    $cd->appendChild($addr);

    $bur = $el('BUR-REE');
    $cwt = $el('CompanyWorkingTime');
    $cwt->setAttribute('CompanyWorkingTimeID', '#1');
    $heures = number_format((float) str_replace(',', '.', (string) param('employeur_heures_hebdo', '40')), 2, '.', '');
    $cwt->appendChild($el('WeeklyHours', $heures));
    $bur->appendChild($cwt);
    $cd->appendChild($bur);
    $cd->appendChild($el('PhoneNumber', (string) param('employeur_telephone')));

    // ----- Staff -----
    $staff = $el('Staff');
    $company->appendChild($staff);

    if ($employeId) {
        $stmt = db()->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
        $stmt->execute([$employeId]);
        $employes = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT DISTINCT ' . EMPLOYE_SELECT . EMPLOYE_FROM . '
            JOIN fiches f ON f.employe_id = e.id WHERE f.annee = ? ORDER BY pe.nom, pe.prenom');
        $stmt->execute([$annee]);
        $employes = $stmt->fetchAll();
    }

    foreach ($employes as $emp) {
        $ctx = certificat_contexte((int) $emp['id'], $annee);
        if ($ctx === null || !$ctx['fiches']) {
            continue;
        }
        $staff->appendChild(build_person_xml($el, $emp, $ctx));
    }
    $company->appendChild($el('SalaryCounters'));

    // ----- Description générale -----
    $gen = $el('GeneralSalaryDeclarationDescription');
    $root->appendChild($gen);
    $gen->appendChild($el('CreationDate', (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')));
    $gen->appendChild($el('AccountingPeriod', (string) $annee));
    $cp = $el('ContactPerson');
    $cp->appendChild($el('Name', (string) param('employeur_contact_nom')));
    $cp->appendChild($el('PhoneNumber', (string) param('employeur_contact_tel')));
    $gen->appendChild($cp);

    return $doc->saveXML();
}

// Construit le nœud <sd:Person> d'un employé pour une année (rubriques form 11).
function build_person_xml(callable $el, array $emp, array $ctx): DOMElement
{
    $tot   = $ctx['tot'];
    $annee = (int) $ctx['annee'];
    // Montants arrondis au franc, net = brut − cotisations − LPP (cohérence interne).
    $brut  = (int) round($tot['salaire_brut']);
    $c9    = (int) round($tot['ded_avs'] + $tot['ded_ac'] + $tot['ded_amat'] + $tot['ded_laa']);
    $lpp   = (int) round($tot['ded_lpp']);
    $impot = (int) round($tot['ded_impot_source']);
    $net   = $brut - $c9 - $lpp;

    $moisList = array_map(fn($f) => (int) $f['mois'], $ctx['fiches']);
    $minM = $moisList ? min($moisList) : 1;
    $maxM = $moisList ? max($moisList) : 12;
    $from  = sprintf('%d-%02d-01', $annee, $minM);
    $until = sprintf('%d-%02d-%02d', $annee, $maxM, cal_days_in_month(CAL_GREGORIAN, $maxM, $annee));

    [$zip, $city] = split_npa((string) $emp['npa_localite']);

    $person = $el('Person');
    // Particulars
    $part = $el('Particulars');
    $person->appendChild($part);
    $part->appendChild($el('Language', 'fr-CH'));
    if (trim((string) $emp['date_naissance']) !== '') {
        $part->appendChild($el('DateOfBirth', date('Y-m-d', strtotime($emp['date_naissance']))));
    }
    $si = $el('Social-InsuranceIdentification');
    $si->appendChild($el('SV-AS-Number', avs_lire((string) $emp['numero_avs'])));
    $part->appendChild($si);
    $part->appendChild($el('Lastname', (string) $emp['nom']));
    $part->appendChild($el('Firstname', (string) $emp['prenom']));
    $addr = $el('Address');
    $addr->appendChild($el('Street', (string) $emp['rue']));
    $addr->appendChild($el('Postbox'));
    $addr->appendChild($el('ZIP-Code', $zip));
    $addr->appendChild($el('City', $city));
    $addr->appendChild($el('Country', 'Suisse'));
    $part->appendChild($addr);

    $person->appendChild($el('Work'));

    // TaxSalaries > TaxSalary
    $taxes = $el('TaxSalaries');
    $person->appendChild($taxes);
    $ts = $el('TaxSalary');
    $taxes->appendChild($ts);
    $ts->appendChild($el('LohnausweisTyp', 'LOHNAUSWEIS'));
    $period = $el('Period');
    $period->appendChild($el('from', $from));
    $period->appendChild($el('until', $until));
    $ts->appendChild($period);
    $ts->appendChild($el('Year', (string) $annee));
    $ts->appendChild($el('Income', (string) $brut)); // ch. 1

    // ch. 2 — prestations accessoires (vides)
    $fb = $el('FringeBenefits');
    $fb->appendChild($el('CompanyCar'));
    $fb->appendChild($el('FoodLodging'));
    $fbOther = $el('Other');
    $fbOther->appendChild($el('Sum'));
    $fbOther->appendChild($el('Text'));
    $fb->appendChild($fbOther);
    $ts->appendChild($fb);

    // ch. 3, 4, 7 (vides) — chacun Sum/Text
    foreach (['SporadicBenefits', 'CapitalPayment', 'OtherBenefits'] as $tag) {
        $node = $el($tag);
        $node->appendChild($el('Sum'));
        $node->appendChild($el('Text'));
        // ch.7 OtherBenefits doit suivre GrossIncome ? Non : positionné comme dans l'export.
        if ($tag === 'OtherBenefits') {
            $ts->appendChild($el('OwnershipRight'));               // ch. 5
            $ts->appendChild($el('BoardOfDirectorsRemuneration')); // ch. 6
        }
        $ts->appendChild($node);
    }

    $ts->appendChild($el('GrossIncome', (string) $brut)); // ch. 8
    $ts->appendChild($el('AHV-ALV-NBUV-AVS-AC-AANP-Contribution', (string) $c9)); // ch. 9
    $bvg = $el('BVG-LPP-Contribution'); // ch. 10
    $bvg->appendChild($el('Purchase'));            // 10.2
    $bvg->appendChild($el('Regular', $lpp > 0 ? (string) $lpp : null)); // 10.1
    $ts->appendChild($bvg);
    $ts->appendChild($el('NetIncome', (string) $net)); // ch. 11
    $ts->appendChild($el('DeductionAtSource', $impot > 0 ? (string) $impot : null)); // ch. 12

    // ch. 13 — frais (vides)
    $charges = $el('Charges');
    $lump = $el('LumpSum');
    $lump->appendChild($el('Car'));
    $lump->appendChild($el('Representation'));
    $lumpOther = $el('Other');
    $lumpOther->appendChild($el('Sum'));
    $lumpOther->appendChild($el('Text'));
    $lump->appendChild($lumpOther);
    $charges->appendChild($lump);
    $charges->appendChild($el('Education'));
    $eff = $el('Effective');
    $effOther = $el('Other');
    $effOther->appendChild($el('Sum'));
    $effOther->appendChild($el('Text'));
    $eff->appendChild($effOther);
    $charges->appendChild($eff);
    $ts->appendChild($charges);

    $ts->appendChild($el('OtherFringeBenefits')); // ch. 14
    $ts->appendChild($el('Remark'));              // ch. 15

    return $person;
}

function route_certificat_exporter_xml(): void
{
    require_login();
    $annee     = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    $employeId = isset($_GET['employe_id']) ? (int) $_GET['employe_id'] : null;
    $xml = build_certificat_xml($annee, $employeId);

    $asso = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) param('employeur_nom')) ?? '', '_') ?: 'asso';
    $nom  = $annee . '_CS_' . $asso . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Cache-Control: no-store');
    echo $xml;
    exit;
}

function route_fiche_imprimer(): void
{
    require_login();
    $id   = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        redirect('fiches');
    }
    render_bare('fiche_imprimer', ['f' => $f]);
}

// ⚠️ Une fiche PAYÉE ne se supprime pas : l'historique est figé (CLAUDE.md).
// La règle vit ICI et dans la requête elle-même, pas seulement dans l'absence
// de bouton — l'écran de modification d'une fiche payée ne s'ouvre pas, mais
// un POST direct passait. Même principe que route_facture_supprimer(), qui
// ne supprime qu'un brouillon.
function route_fiche_supprimer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (!fiche_supprimer($id)) {
            redirect('fiche', ['id' => $id, 'err' => 'payee']);
        }
    }
    redirect('fiches');
}

// Supprime une fiche NON payée. Faux si la fiche existe et est payée (rien
// n'est supprimé) ; vrai sinon — supprimée, ou déjà absente. La condition est
// dans la requête elle-même : pas de fenêtre entre la lecture et l'écriture.
function fiche_supprimer(int $id): bool
{
    $suppr = db()->prepare("DELETE FROM fiches WHERE id = ? AND trim(COALESCE(date_paiement, '')) = ''");
    $suppr->execute([$id]);
    if ($suppr->rowCount() > 0) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    return !$stmt->fetchColumn();
}

function route_fiche_modifier(): void
{
    require_login();
    $id   = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        redirect('fiches');
    }
    if (trim((string) $f['date_paiement']) !== '') {
        redirect('fiche', ['id' => $id]); // fiche payée → non modifiable
    }

    $employes     = db()->query('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.actif = 1 ORDER BY pe.nom, pe.prenom')->fetchAll();
    $tauxHoraires = db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll();
    $unites       = db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll();
    $axes         = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];
    $evenements   = evenements_pour_ligne();

    $stmtLignes = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ? ORDER BY ordre');
    $stmtLignes->execute([$id]);
    $lignes = $stmtLignes->fetchAll();

    $postData = [
        'employe_id'        => $f['employe_id'],
        'annee'             => $f['annee'],
        'mois'              => $f['mois'],
        'date_paiement'     => $f['date_paiement'],
        'supplement_vacances' => nombre_court((float) $f['supplement_taux'] * 100, 4),
        'afficher_cout_emp' => $f['afficher_cout_emp'],
    ];
    // Impôt source : pas stocké en colonne dédiée → repris du JSON figé
    $tj = json_decode($f['taux_json'] ?: '{}', true) ?: [];
    if (!empty($tj['impot_source'])) {
        $postData['impot_source_taux'] = nombre_court((float) $tj['impot_source'] * 100, 4);
    }

    foreach ($lignes as $i => $ligne) {
        $postData['l_unite'][$i]    = $ligne['heures_unite'] . '|' . $ligne['libelle'];
        $postData['l_quantite'][$i] = $ligne['quantite'];
        $taux_h = (float) $ligne['taux_horaire'];
        $match = null;
        foreach ($tauxHoraires as $t) {
            if ((float) $t['montant'] === $taux_h) {
                $match = (string) $t['montant'];
                break;
            }
        }
        if ($match !== null) {
            $postData['l_taux_choix'][$i]  = $match;
            $postData['l_taux_manuel'][$i] = '';
        } else {
            $postData['l_taux_choix'][$i]  = 'autre';
            $postData['l_taux_manuel'][$i] = nombre_court($taux_h);
        }
        $postData['l_axe'][$i] = (string) ($ligne['axe_analytique_id'] ?? '');
        $postData['l_evenement'][$i] = (string) ($ligne['evenement_id'] ?? '');
    }

    render('fiche_form', [
        'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites, 'axes' => $axes,
        'evenements' => $evenements, 'err' => null, 'post' => $postData, 'edit_mode' => true, 'fiche_id' => $id,
        'tauxData' => taux_pour_annee_js(),
        'saved' => isset($_GET['success']), // reste sur la page de modification après Enregistrer
    ], 'Modifier la fiche');
}

// Date de paiement d'une fiche, et l'écriture bancaire qui l'a payée. Les deux
// dans le même formulaire — et donc la même route — parce que c'est le même
// geste : constater le versement. Symétrique de route_facture_paiement().
function route_fiche_paiement(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id   = (int) ($_POST['id'] ?? 0);
        $date = trim($_POST['date_paiement'] ?? '');

        $stmt = db()->prepare('SELECT ecriture_id FROM fiches WHERE id = ?');
        $stmt->execute([$id]);
        $ancienne = (int) ($stmt->fetchColumn() ?: 0);

        // L'écriture proposée doit être un débit encore libre (ou déjà celle de
        // cette fiche) : sans cette vérification, un identifiant forgé
        // détournerait l'écriture d'une autre fiche.
        $ecritureId = null;
        $brut = (int) ($_POST['ecriture_id'] ?? 0);
        if ($brut && module_accessible('compta')) {
            $stmt = db()->prepare(
                'SELECT 1 FROM ecritures WHERE id = ? AND montant < 0 AND (fiche_id IS NULL OR fiche_id = ?)'
            );
            $stmt->execute([$brut, $id]);
            if ($stmt->fetchColumn()) {
                $ecritureId = $brut;
            }
        }

        db()->beginTransaction();
        db()->prepare('UPDATE fiches SET date_paiement = ?, ecriture_id = ? WHERE id = ?')
            ->execute([$date, $ecritureId, $id]);
        // Le lien inverse suit, dans la même transaction : une écriture délaissée
        // redevient libre, la nouvelle pointe cette fiche.
        if ($ancienne && $ancienne !== $ecritureId) {
            db()->prepare('UPDATE ecritures SET fiche_id = NULL WHERE id = ? AND fiche_id = ?')
                ->execute([$ancienne, $id]);
        }
        if ($ecritureId) {
            db()->prepare('UPDATE ecritures SET fiche_id = ? WHERE id = ?')->execute([$id, $ecritureId]);
        }
        db()->commit();
        redirect('fiche', ['id' => $id, 'ok' => 'date']);
    }
    redirect('fiches');
}

function route_fiche_cout_employeur(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id  = (int) ($_POST['id'] ?? 0);
        $val = isset($_POST['afficher_cout_emp']) ? 1 : 0;
        db()->prepare('UPDATE fiches SET afficher_cout_emp = ? WHERE id = ?')->execute([$val, $id]);
        redirect('fiche', ['id' => $id, 'ok' => 'cout']);
    }
    redirect('fiches');
}

function route_fiche_envoyer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fiches');
    }
    check_csrf();
    $id   = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        redirect('fiches');
    }

    // Destinataire : e-mail de l'employé. Expéditeur : paramètre configuré.
    $stmt = db()->prepare('SELECT pe.email' . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([(int) $f['employe_id']]);
    $destinataire = trim((string) $stmt->fetchColumn());
    $expediteur   = trim((string) param('employeur_email_expediteur'));

    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        redirect('fiche', ['id' => $id, 'mail' => 'no_dest']);
    }
    if (!filter_var($expediteur, FILTER_VALIDATE_EMAIL)) {
        redirect('fiche', ['id' => $id, 'mail' => 'no_exp']);
    }

    [$ok] = envoyer_fiche_email($f, $destinataire, $expediteur);
    if ($ok) {
        db()->prepare('UPDATE fiches SET email_envoye_le = ? WHERE id = ?')
            ->execute([date('c'), $id]);
        redirect('fiche', ['id' => $id, 'mail' => 'ok']);
    }
    redirect('fiche', ['id' => $id, 'mail' => 'err']);
}

// Page « Cotisations » : résumé complet (par période) + charges totales.
function route_cotisations(): void
{
    require_login();
    $annee     = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    $employeId = isset($_GET['employe_id']) ? (int) $_GET['employe_id'] : 0;
    $groupe    = $_GET['groupe'] ?? 'mois'; // annee | semestre | trimestre | mois

    $employes = db()->query('SELECT e.id, pe.prenom, pe.nom' . EMPLOYE_FROM . ' ORDER BY pe.nom, pe.prenom')->fetchAll();
    $annees   = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);

    if ($groupe === 'annee') {
        $sql = 'SELECT * FROM fiches';
        $params = [];
        if ($employeId) {
            $sql .= ' WHERE employe_id = ?';
            $params[] = $employeId;
        }
        $sql .= ' ORDER BY annee, mois';
    } else {
        $sql = 'SELECT * FROM fiches WHERE annee = ?';
        $params = [$annee];
        if ($employeId) {
            $sql .= ' AND employe_id = ?';
            $params[] = $employeId;
        }
        $sql .= ' ORDER BY mois';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fiches = $stmt->fetchAll();

    $champs = ['brut', 'charges_soc', 'impots', 'net', 'charges_pat', 'cout_emp'];
    $vide = array_fill_keys($champs, 0.0) + ['nb' => 0, 'label' => ''];

    $buckets = [];
    $totaux  = $vide;
    foreach ($fiches as $f) {
        [$cle, $label] = periode_cle($groupe, (int) $f['mois'], (int) $f['annee']);
        if (!isset($buckets[$cle])) {
            $buckets[$cle] = $vide;
            $buckets[$cle]['label'] = $label;
        }
        $chargesSoc = (float) $f['total_deductions'] - (float) $f['ded_impot_source'];
        $vals = [
            'brut'        => (float) $f['salaire_brut'],
            'charges_soc' => $chargesSoc,
            'impots'      => (float) $f['ded_impot_source'],
            'net'         => (float) $f['salaire_net'],
            'charges_pat' => (float) ($f['total_charges_emp'] ?? 0),
            'cout_emp'    => (float) ($f['cout_total_emp'] ?? 0),
        ];
        $buckets[$cle]['nb']++; $totaux['nb']++;
        foreach ($champs as $c) {
            $buckets[$cle][$c] += $vals[$c];
            $totaux[$c]        += $vals[$c];
        }
    }
    ksort($buckets);

    // --- Retenues à verser aux organismes (part employé + part patronale) ------
    // Tableau trimestriel pour l'année sélectionnée : T1..T4, sous-totaux S1/S2,
    // total annuel ; colonnes OCAS, LAA, LPP, impôt à la source.
    // OCAS = AVS/AC/A.mat (employé) + AVS/AC/A.mat/AF/frais/CPE/LFP (employeur).
    $retDeFiche = function (array $f): array {
        return [
            'ocas'  => (float) $f['ded_avs'] + (float) $f['ded_ac'] + (float) $f['ded_amat']
                     + (float) $f['emp_avs'] + (float) $f['emp_ac'] + (float) $f['emp_amat']
                     + (float) $f['emp_af'] + (float) $f['emp_frais'] + (float) $f['emp_cpe'] + (float) $f['emp_lfp'],
            'laa'   => (float) $f['ded_laa'] + (float) $f['emp_laa'],
            'lpp'   => (float) $f['ded_lpp'] + (float) $f['emp_lpp'],
            'impot' => (float) $f['ded_impot_source'],
        ];
    };
    // Année choisie indépendamment via la liste déroulante de la section
    // (repli sur l'année du filtre principal). Requête dédiée à cette année.
    $retAnnee = isset($_GET['ret_annee']) ? (int) $_GET['ret_annee'] : $annee;
    $retSql    = 'SELECT * FROM fiches WHERE annee = ?';
    $retParams = [$retAnnee];
    if ($employeId) {
        $retSql .= ' AND employe_id = ?';
        $retParams[] = $employeId;
    }
    $retStmt = db()->prepare($retSql);
    $retStmt->execute($retParams);
    $retFiches = $retStmt->fetchAll();

    $retCols = ['ocas', 'laa', 'lpp', 'impot'];
    $retZero = array_fill_keys($retCols, 0.0);
    $trim    = [1 => $retZero, 2 => $retZero, 3 => $retZero, 4 => $retZero];
    foreach ($retFiches as $f) {
        $t = (int) ceil((int) $f['mois'] / 3);
        foreach ($retDeFiche($f) as $k => $v) {
            $trim[$t][$k] += $v;
        }
    }
    $retNb = count($retFiches);
    $somme = function (array ...$lignes) use ($retCols): array {
        $r = array_fill_keys($retCols, 0.0);
        foreach ($lignes as $l) {
            foreach ($retCols as $c) {
                $r[$c] += $l[$c];
            }
        }
        return $r;
    };
    $s1  = $somme($trim[1], $trim[2]);
    $s2  = $somme($trim[3], $trim[4]);
    $tot = $somme($s1, $s2);
    $retenues = [
        ['label' => 'T1 (janv.–mars)',  'vals' => $trim[1], 'type' => ''],
        ['label' => 'T2 (avr.–juin)',   'vals' => $trim[2], 'type' => ''],
        ['label' => 'Sous-total S1',    'vals' => $s1,      'type' => 'sous'],
        ['label' => 'T3 (juil.–sept.)', 'vals' => $trim[3], 'type' => ''],
        ['label' => 'T4 (oct.–déc.)',   'vals' => $trim[4], 'type' => ''],
        ['label' => 'Sous-total S2',    'vals' => $s2,      'type' => 'sous'],
        ['label' => 'Total annuel',     'vals' => $tot,     'type' => 'total'],
    ];

    render('cotisations', [
        'annee'     => $annee,
        'annees'    => $annees,
        'employes'  => $employes,
        'employeId' => $employeId,
        'groupe'    => $groupe,
        'buckets'   => $buckets,
        'totaux'    => $totaux,
        'champs'    => $champs,
        'retenues'  => $retenues,
        'retCols'   => $retCols,
        'retNb'     => $retNb,
        'retAnnee'  => $retAnnee,
    ], 'Cotisations');
}
