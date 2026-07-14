<?php
// Handlers de routes (un par action). index.php se contente de dispatcher.

// ----------------------------------------------------------------- AUTH
// Secret d'installation : si SETUP_SECRET est défini (non vide), l'écran setup
// n'est accessible qu'avec la bonne clé (?key=… ou champ caché du formulaire).
function setup_secret_ok(): bool
{
    if (!defined('SETUP_SECRET') || SETUP_SECRET === '') {
        return true; // protection désactivée (local)
    }
    $key = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
    return $key !== '' && hash_equals(SETUP_SECRET, $key);
}

function route_setup(): void
{
    if (has_users()) {
        redirect('login');
    }
    if (!setup_secret_ok()) {
        // On ne révèle pas l'existence de l'écran : réponse « introuvable ».
        http_response_code(404);
        exit('Page introuvable.');
    }
    $key = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';
        $err   = null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Adresse e-mail invalide.';
        } elseif (strlen($mdp) < PASSWORD_MIN) {
            $err = 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
        }
        if ($err) {
            render('setup', ['err' => $err, 'email' => $email, 'key' => $key], 'Installation');
            return;
        }
        $stmt = db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)');
        $stmt->execute([$email, password_hash($mdp, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST])]);
        session_regenerate_id(true);
        $_SESSION['uid']           = (int) db()->lastInsertId();
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
        redirect('resumes');
    }
    render('setup', ['err' => null, 'email' => '', 'key' => $key], 'Installation');
}

function route_login(): void
{
    if (current_user()) {
        redirect('resumes');
    }
    $ip = client_ip();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        // Anti-force-brute : blocage temporaire après trop d'échecs.
        if (login_is_locked($ip)) {
            $min = (int) ceil(LOGIN_WINDOW / 60);
            render('login', ['err' => "Trop de tentatives. Réessayez dans $min minutes.", 'email' => ''], 'Connexion');
            return;
        }
        usleep(random_int(200000, 500000)); // ralentit l'automatisation
        $email = trim($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';
        $stmt  = db()->prepare('SELECT * FROM utilisateurs WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($mdp, $u['mot_de_passe'])) {
            login_clear_failures($ip);
            session_regenerate_id(true);
            $_SESSION['uid']           = (int) $u['id'];
            $_SESSION['login_time']    = time();
            $_SESSION['last_activity'] = time();
            redirect('resumes');
        }
        login_record_failure($ip, $email);
        render('login', ['err' => 'Identifiants incorrects.', 'email' => $email], 'Connexion');
        return;
    }
    $msg = isset($_GET['expired']) ? 'Session expirée. Reconnectez-vous.' : null;
    render('login', ['err' => null, 'info' => $msg, 'email' => ''], 'Connexion');
}

function route_logout(): void
{
    logout_session();
    redirect('login');
}

function route_compte(): void
{
    require_login();
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $prenom  = trim($_POST['prenom'] ?? '');
        $nom     = trim($_POST['nom'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $actuel  = $_POST['mot_de_passe_actuel'] ?? '';
        $nouveau = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirm = $_POST['confirmer'] ?? '';

        $err = null;
        if (!password_verify($actuel, $u['mot_de_passe'])) {
            $err = 'Mot de passe actuel incorrect.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Adresse e-mail invalide.';
        } elseif ($nouveau !== '' && strlen($nouveau) < PASSWORD_MIN) {
            $err = 'Le nouveau mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
        } elseif ($nouveau !== '' && $nouveau !== $confirm) {
            $err = 'La confirmation du nouveau mot de passe ne correspond pas.';
        } else {
            $stmt = db()->prepare('SELECT id FROM utilisateurs WHERE email = ? AND id <> ?');
            $stmt->execute([$email, $u['id']]);
            if ($stmt->fetch()) {
                $err = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
            }
        }
        if ($err) {
            render('compte', ['u' => ['prenom' => $prenom, 'nom' => $nom, 'email' => $email] + $u, 'err' => $err, 'saved' => null], 'Mon compte');
            return;
        }
        $hash = $nouveau !== '' ? password_hash($nouveau, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]) : $u['mot_de_passe'];
        db()->prepare('UPDATE utilisateurs SET prenom = ?, nom = ?, email = ?, mot_de_passe = ? WHERE id = ?')
            ->execute([$prenom, $nom, $email, $hash, $u['id']]);
        redirect('compte', ['ok' => 1]);
    }
    render('compte', ['u' => $u, 'err' => null, 'saved' => $_GET['ok'] ?? null], 'Mon compte');
}

// -------------------------------------------------------------- COMPTES (admin)
// Gestion des comptes utilisateurs : liste, création, réinitialisation, suppression.
// Tous les comptes ont les mêmes droits (pas de rôles) ; usage 1–2 personnes.
function route_comptes(): void
{
    require_login();
    $err = null;
    $emailSaisi = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';
        $emailSaisi = $email;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Adresse e-mail invalide.';
        } elseif (strlen($mdp) < PASSWORD_MIN) {
            $err = 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
        } else {
            $stmt = db()->prepare('SELECT id FROM utilisateurs WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $err = 'Cette adresse e-mail est déjà utilisée par un compte.';
            }
        }
        if (!$err) {
            db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)')
                ->execute([$email, password_hash($mdp, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST])]);
            redirect('comptes', ['ok' => 'created']);
        }
    }
    $comptes = db()->query('SELECT id, email, cree_le FROM utilisateurs ORDER BY cree_le, id')->fetchAll();
    render('comptes', [
        'comptes'    => $comptes,
        'err'        => $err,
        'emailSaisi' => $emailSaisi,
        'ok'         => $_GET['ok'] ?? null,
        'flagErr'    => $_GET['err'] ?? null,
        'moi'        => (int) current_user()['id'],
    ], 'Paramètres — Comptes');
}

function route_compte_reset(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('comptes');
    }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $mdp = $_POST['nouveau_mot_de_passe'] ?? '';
    if (strlen($mdp) < PASSWORD_MIN) {
        redirect('comptes', ['err' => 'short']);
    }
    db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
        ->execute([password_hash($mdp, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]), $id]);
    redirect('comptes', ['ok' => 'reset']);
}

function route_compte_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('comptes');
    }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $moi = (int) current_user()['id'];
    if ($id === $moi) {
        redirect('comptes', ['err' => 'self']);     // pas d'auto-suppression
    }
    $total = (int) db()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    if ($total <= 1) {
        redirect('comptes', ['err' => 'last']);      // ne jamais vider la table
    }
    db()->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$id]);
    redirect('comptes', ['ok' => 'deleted']);
}

// -------------------------------------------------------------- EMPLOYÉS
function route_employes(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $pgTaille = pagination_taille('employes_taille');

    // Aucun filtre structuré sur cette page (juste la recherche texte) : le
    // total "sans recherche" est simplement le total de la table.
    $totalSansRecherche = (int) db()->query('SELECT COUNT(*) FROM employes')->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    if ($modeClient) {
        // Mode client (voir pagination_mode_client()) : toutes les lignes,
        // recherche/pagination 100% en JS (lassoListeClient()) — pas de
        // requête LIKE ni de LIMIT ici.
        $employes = db()->query('SELECT * FROM employes ORDER BY actif DESC, nom, prenom')->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['nom', 'prenom', 'rue', 'npa_localite', 'email']);
        $stmtTot = db()->prepare('SELECT COUNT(*) FROM employes WHERE 1=1' . $rechSql);
        $stmtTot->execute($rechParams);
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);
        $stmt = db()->prepare('SELECT * FROM employes WHERE 1=1' . $rechSql . ' ORDER BY actif DESC, nom, prenom' . $limitSql);
        $stmt->execute(array_merge($rechParams, $limitParams));
        $employes = $stmt->fetchAll();
    }

    // Dernière fiche de salaire par employé (seulement pour les employés affichés).
    $derniere = [];
    if ($employes) {
        $empIds = array_column($employes, 'id');
        $inPlh  = implode(',', array_fill(0, count($empIds), '?'));
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
    render('employes', ['employes' => $employes, 'derniere' => $derniere, 'recherche' => $recherche,
        'modeClient' => $modeClient,
        'pgRoute' => 'employes', 'pgParams' => $recherche !== '' ? ['q' => $recherche] : [],
        'pgPage' => $pgPage, 'pgTaille' => $pgTaille, 'pgTotal' => $pgTotal], 'Employés');
}

function route_employe_voir(): void
{
    require_login();
    $id   = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        redirect('employes');
    }
    $stmt = db()->prepare('SELECT * FROM fiches WHERE employe_id = ? ORDER BY annee DESC, mois DESC');
    $stmt->execute([$id]);
    $fiches = $stmt->fetchAll();
    render('employe_voir', ['emp' => $emp, 'fiches' => $fiches], $emp['prenom'] . ' ' . $emp['nom']);
}

function route_employe(): void
{
    require_login();
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $emp = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if (!$emp) {
            redirect('employes');
        }
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
        if ($err) {
            render('employe_form', ['emp' => array_merge((array) $emp, $champs), 'err' => $err], 'Employé');
            return;
        }
        if (!in_array($champs['canton'], CANTONS, true)) {
            $champs['canton'] = 'Genève';
        }
        if (!in_array($champs['procedure'], PROCEDURES, true)) {
            $champs['procedure'] = 'Ordinaire';
        }
        if ($id) {
            $sql = 'UPDATE employes SET prenom=:prenom, nom=:nom, email=:email, rue=:rue, npa_localite=:npa_localite,
                    numero_avs=:numero_avs, date_naissance=:date_naissance, canton=:canton, procedure=:procedure,
                    supplement_vacances=:supplement_vacances,
                    impot_source_taux=:impot_source_taux, actif=:actif WHERE id=:id';
            $champs['id'] = $id;
            db()->prepare($sql)->execute($champs);
        } else {
            $sql = 'INSERT INTO employes (prenom, nom, email, rue, npa_localite, numero_avs, date_naissance, canton, procedure,
                    supplement_vacances, impot_source_taux, actif)
                    VALUES (:prenom, :nom, :email, :rue, :npa_localite, :numero_avs, :date_naissance, :canton, :procedure,
                    :supplement_vacances, :impot_source_taux, :actif)';
            db()->prepare($sql)->execute($champs);
        }
        redirect('employes');
    }
    // À l'affichage, l'override saisi en % : on ne touche pas aux valeurs stockées (fractions)
    render('employe_form', ['emp' => $emp, 'err' => null], $id ? 'Modifier employé' : 'Nouvel employé');
}

function route_employe_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (supprimer_si_non_reference('employes', $id, 'fiches', 'employe_id')) {
            redirect('employes');
        }
        // A des fiches → suppression refusée
        redirect('employe_voir', ['id' => $id, 'err' => 'fiches']);
    }
    redirect('employes');
}

// ------------------------------------------------------ EMPLOYEUR / TAUX
function route_parametres(): void
{
    require_login();
    redirect('employeur');
}

// Activation/désactivation des modules optionnels (salaires, compta, analytique).
// Un module à la fois (interrupteur à bascule immédiate, comme les règles de
// lettrage et les axes analytiques) : POST { module, actif? }.
function route_parametres_modules(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $module = (string) ($_POST['module'] ?? '');
        if (array_key_exists($module, MODULES)) {
            $actifs = modules_actifs();
            $actifs = isset($_POST['actif'])
                ? array_unique([...$actifs, $module])
                : array_diff($actifs, [$module]);
            set_modules_actifs($actifs);
        }
        redirect('parametres_modules');
    }
    render('parametres_modules', ['actifs' => modules_actifs()], 'Modules');
}

function route_employeur(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = ['employeur_nom', 'employeur_rue', 'employeur_npa', 'employeur_pays',
                   'employeur_telephone', 'employeur_heures_hebdo',
                   'employeur_contact_nom', 'employeur_contact_tel'];
        // Logos : traités avant l'écriture pour pouvoir afficher une erreur d'upload.
        $logos = [];
        try {
            foreach (['logo_clair' => 'employeur_logo_clair', 'logo_sombre' => 'employeur_logo_sombre'] as $field => $cle) {
                $path = handle_logo_upload($field);
                if ($path !== null) {
                    $logos[$cle] = $path;
                }
            }
        } catch (RuntimeException $ex) {
            render('employeur', ['saved' => null, 'err' => $ex->getMessage()], 'Employeur');
            return;
        }

        $stmt = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        foreach ($champs as $k) {
            $stmt->execute([$k, trim($_POST[$k] ?? '')]);
        }
        // Couleurs : ignore une valeur invalide plutôt que de casser la palette.
        foreach (['employeur_couleur_principale', 'employeur_couleur_evidence'] as $cleCouleur) {
            $couleur = trim($_POST[$cleCouleur] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) {
                $stmt->execute([$cleCouleur, strtolower($couleur)]);
            }
        }
        foreach ($logos as $cle => $path) {
            $ancien = param($cle); // ancien fichier à supprimer s'il était uploadé
            $stmt->execute([$cle, $path]);
            if ($ancien !== '' && str_starts_with($ancien, 'uploads/') && is_file(__DIR__ . '/../' . $ancien)) {
                @unlink(__DIR__ . '/../' . $ancien);
            }
        }
        redirect('employeur', ['ok' => 1]);
    }
    render('employeur', ['saved' => isset($_GET['ok']), 'err' => null], 'Employeur');
}

// Paramètres d'envoi des e-mails (expéditeur, contact, SMTP authentifié).
function route_emails(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = ['employeur_email_contact', 'employeur_email_expediteur',
                   'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user'];
        $emailContact = trim($_POST['employeur_email_contact'] ?? '');
        $emailExp     = trim($_POST['employeur_email_expediteur'] ?? '');
        $smtpUser     = trim($_POST['smtp_user'] ?? '');
        if (($emailContact !== '' && !filter_var($emailContact, FILTER_VALIDATE_EMAIL))
            || ($emailExp !== '' && !filter_var($emailExp, FILTER_VALIDATE_EMAIL))
            || ($smtpUser !== '' && !filter_var($smtpUser, FILTER_VALIDATE_EMAIL))) {
            render('emails', ['saved' => null, 'err' => 'Adresse e-mail invalide.'], 'E-mails');
            return;
        }
        $stmt = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        foreach ($champs as $k) {
            $stmt->execute([$k, trim($_POST[$k] ?? '')]);
        }
        // Mot de passe SMTP : mis à jour uniquement s'il est saisi (jamais réaffiché).
        $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
        if ($smtpPass !== '') {
            $stmt->execute(['smtp_pass', $smtpPass]);
        }
        redirect('emails', ['ok' => 1]);
    }
    render('emails', ['saved' => isset($_GET['ok']), 'err' => null], 'E-mails');
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
    ], 'Salaires horaires');
}

// Fusionné dans « Salaires horaires » : on redirige les anciens liens.
function route_unites(): void
{
    require_login();
    redirect('taux_horaires');
}

function route_export(): void
{
    require_login();
    $annees = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
    $anneesCompta = array_map('intval', db()->query("SELECT DISTINCT substr(date_op,1,4) FROM ecritures ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN));
    // camt.053 est un format mono-compte (une IBAN par relevé) — seuls les
    // comptes avec IBAN renseignée sont proposés.
    $comptesCamt = module_actif('compta')
        ? db()->query("SELECT id, libelle FROM comptes_bancaires WHERE trim(iban) <> '' ORDER BY ordre, libelle")->fetchAll()
        : [];
    render('export', [
        'annees' => array_map('intval', $annees), 'anneesCompta' => $anneesCompta, 'comptesCamt' => $comptesCamt,
        'errCamt' => ($_GET['err'] ?? '') === 'camt_compte',
    ], 'Exporter les données');
}

// Import de fiches de salaire depuis un fichier JSON (format d'export, type
// « fiches_salaire »). N'insère que les fiches nouvelles : une fiche déjà
// présente (même employé/année/mois) est ignorée, jamais écrasée (historique figé).
function route_import_fiches(): void
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
    render('import_fiches', [
        'errFiches' => $err, 'resultatsFiches' => $resultats, 'resumeFiches' => $resume, 'simuleFiches' => $simule,
        'errFactures' => null, 'resultatsFactures' => null, 'resumeFactures' => null, 'simuleFactures' => true,
        'msgEcritures' => null,
        'errEvenements' => null, 'resultatsEvenements' => null, 'resumeEvenements' => null, 'simuleEvenements' => true,
    ], 'Importer');
}

// Évalue (et, si !$simule, insère) une liste de fiches. Correspondance employé
// par numéro AVS. Renvoie [resultats par ligne, résumé chiffré].
function importer_fiches_salaire(array $fiches, bool $simule): array
{
    $findEmp = db()->prepare('SELECT id FROM employes WHERE replace(numero_avs, " ", "") = ?');
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
            $findEmp->execute([$avs]);
            $empId = $findEmp->fetchColumn();
            if ($empId === false) {
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
                    'employe_avs' => (string) ($f['employe_avs'] ?? $avs),
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

function route_taux(): void
{
    require_login();
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $annee = (int) ($_POST['annee'] ?? date('Y'));
        $insT = db()->prepare('INSERT OR REPLACE INTO taux_par_annee (annee, cle, valeur) VALUES (?, ?, ?)');
        foreach (array_keys(TAUX_DEFAUT) as $k) {
            $val = (float) str_replace(',', '.', $_POST[$k] ?? '0') / 100;
            $insT->execute([$annee, $k, (string) $val]);
        }
        redirect('taux', ['annee' => $annee, 'ok' => 1]);
    }
    $anneesTaux   = db()->query('SELECT DISTINCT annee FROM taux_par_annee')->fetchAll(PDO::FETCH_COLUMN);
    $anneesFiches = db()->query('SELECT DISTINCT annee FROM fiches')->fetchAll(PDO::FETCH_COLUMN);
    $annees = array_unique(array_map('intval', array_merge(
        $anneesTaux, $anneesFiches,
        [$annee, (int) date('Y') - 1, (int) date('Y'), (int) date('Y') + 1]
    )));
    rsort($annees);
    render('taux', [
        'saved'      => isset($_GET['ok']),
        'annee'      => $annee,
        'annees'     => $annees,
        'taux'       => taux_stockes($annee),
        'configuree' => in_array($annee, array_map('intval', $anneesTaux), true),
    ], 'Taux');
}

// ---------------------------------------------------------------- FICHES
function route_fiches(): void
{
    require_login();
    // Filtres : GET prioritaire, sinon dernière valeur en session (conservée au
    // retour depuis une fiche), sinon défaut.
    $annee     = (int) filtre_persistant('annee', 'fiches_annee', 0); // 0 = « Toutes les années » par défaut
    $statut    = filtre_persistant('statut', 'fiches_statut', 'tous'); // tous | apayer | payees
    $employeId = (int) filtre_persistant('employe_id', 'fiches_employe', 0);
    $where  = ' WHERE 1=1';
    $params = [];
    if ($annee > 0) { // 0 = « Toutes les années »
        $where .= ' AND f.annee = ?';
        $params[] = $annee;
    }
    if ($statut === 'apayer') {
        $where .= " AND (f.date_paiement IS NULL OR f.date_paiement = '')";
    } elseif ($statut === 'payees') {
        $where .= " AND f.date_paiement <> ''";
    }
    if ($employeId) {
        $where .= ' AND f.employe_id = ?';
        $params[] = $employeId;
    }

    $stmtTot = db()->prepare('SELECT COUNT(*) FROM fiches f' . $where);
    $stmtTot->execute($params);
    $pgTotal = (int) $stmtTot->fetchColumn();

    // Totaux calculés en base sur tout le résultat filtré (pas seulement la
    // page affichée) — la ligne « Total » du tableau doit rester exacte même
    // paginée.
    $stmtSum = db()->prepare(
        'SELECT COALESCE(SUM(f.salaire_brut),0) AS brut, COALESCE(SUM(f.total_deductions),0) AS ded,
                COALESCE(SUM(f.ded_impot_source),0) AS impot, COALESCE(SUM(f.salaire_net),0) AS net,
                COALESCE(SUM(f.total_charges_emp),0) AS charges_emp, COALESCE(SUM(f.cout_total_emp),0) AS cout_emp
         FROM fiches f' . $where
    );
    $stmtSum->execute($params);
    $totaux = $stmtSum->fetch();

    $pgPage   = pagination_page();
    $pgTaille = pagination_taille('fiches_taille');
    [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

    $sql = 'SELECT f.*, e.prenom, e.nom AS emp_nom_actuel
            FROM fiches f JOIN employes e ON e.id = f.employe_id' . $where;
    $sql  .= $annee > 0 ? ' ORDER BY f.mois DESC, e.nom' : ' ORDER BY f.annee DESC, f.mois DESC, e.nom';
    $sql  .= $limitSql;
    $stmt  = db()->prepare($sql);
    $stmt->execute(array_merge($params, $limitParams));
    $fiches = $stmt->fetchAll();
    $annees = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
    $employes = db()->query('SELECT id, prenom, nom FROM employes ORDER BY nom, prenom')->fetchAll();

    // Axes par fiche (une seule requête groupée)
    $axesParFiche = [];
    if ($fiches && module_actif('analytique')) {
        $ficheIds = array_column($fiches, 'id');
        $inPlh = implode(',', array_fill(0, count($ficheIds), '?'));
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

    render('fiches', ['fiches' => $fiches, 'annee' => $annee, 'annees' => $annees, 'statut' => $statut,
        'employes' => $employes, 'employeId' => $employeId, 'axesParFiche' => $axesParFiche, 'totaux' => $totaux,
        'pgRoute' => 'fiches', 'pgParams' => ['annee' => $annee, 'statut' => $statut, 'employe_id' => $employeId],
        'pgPage' => $pgPage, 'pgTaille' => $pgTaille, 'pgTotal' => $pgTotal], 'Fiches de salaire');
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
// Partagée entre le formulaire de fiche complet (route_fiche_new) et l'ajout
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

    $taux = taux_pour_annee($annee); // taux figés selon l'année de la fiche
    $taux = array_merge($taux, laa_effectif($taux, $heures, $annee, $mois));
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
    ] + $c;

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
            $in = implode(',', array_fill(0, count($evenementIds), '?'));
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
        "SELECT e.id, e.date, e.ville, e.salle, e.festival, s.nom AS spectacle
         FROM evenements e LEFT JOIN spectacles s ON s.id = e.spectacle_id
         ORDER BY e.date DESC"
    )->fetchAll();
}

function route_fiche_new(): void
{
    require_login();
    $employes     = db()->query('SELECT * FROM employes WHERE actif = 1 ORDER BY nom, prenom')->fetchAll();
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

    $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
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
        redirect('fiche_edit', ['id' => $ficheId, 'success' => '1']); // reste sur la page de modification
    } else {
        redirect('fiche', ['id' => $ficheId, 'success' => '1']);
    }
}

function route_fiche(): void
{
    require_login();
    $id   = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        redirect('fiches');
    }
    $modifiable = trim((string) $f['date_paiement']) === '';
    // E-mail de l'employé (destinataire) + expéditeur configuré, pour le bouton d'envoi.
    $stmt = db()->prepare('SELECT email FROM employes WHERE id = ?');
    $stmt->execute([(int) $f['employe_id']]);
    $emailEmploye = trim((string) $stmt->fetchColumn());
    $emailExp     = trim((string) param('employeur_email_expediteur'));
    $axes = module_actif('analytique')
        ? db()->query('SELECT id, code, libelle FROM axes_analytiques WHERE actif = 1 ORDER BY code, libelle')->fetchAll()
        : [];
    render('fiche_view', [
        'f' => $f, 'modifiable' => $modifiable, 'saved' => $_GET['ok'] ?? null,
        'mail' => $_GET['mail'] ?? null,
        'emailEmploye' => $emailEmploye, 'emailExp' => $emailExp,
        'axes' => $axes,
    ], 'Fiche ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']);
}

// Sauvegarde AJAX de l'axe analytique d'une ligne de fiche (modifiable même après paiement).
function route_fiche_ligne_axe_save(): void
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

// Charge un employé + ses fiches d'une année + les totaux. Renvoie null si employé introuvable.
function certificat_contexte(int $empId, ?int $annee): ?array
{
    $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
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
        'fiches' => $fiches, 'tot' => agreger_certificat($fiches)];
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

function route_certificat_print(): void
{
    require_login();
    $empId = (int) ($_GET['employe_id'] ?? 0);
    $annee = isset($_GET['annee']) ? (int) $_GET['annee'] : null;
    $ctx = certificat_contexte($empId, $annee);
    if ($ctx === null) {
        redirect('employes');
    }
    render_bare('certificat_print', $ctx);
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
        $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
        $stmt->execute([$employeId]);
        $employes = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT DISTINCT e.* FROM employes e
            JOIN fiches f ON f.employe_id = e.id WHERE f.annee = ? ORDER BY e.nom, e.prenom');
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
    $si->appendChild($el('SV-AS-Number', (string) $emp['numero_avs']));
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

function route_certificat_xml(): void
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

function route_fiche_print(): void
{
    require_login();
    $id   = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) {
        redirect('fiches');
    }
    render_bare('fiche_print', ['f' => $f]);
}

function route_fiche_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        db()->prepare('DELETE FROM fiches WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }
    redirect('fiches');
}

function route_fiche_edit(): void
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

    $employes     = db()->query('SELECT * FROM employes WHERE actif = 1 ORDER BY nom, prenom')->fetchAll();
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

function route_fiche_date(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id   = (int) ($_POST['id'] ?? 0);
        $date = trim($_POST['date_paiement'] ?? '');
        db()->prepare('UPDATE fiches SET date_paiement = ? WHERE id = ?')->execute([$date, $id]);
        redirect('fiche', ['id' => $id, 'ok' => 'date']);
    }
    redirect('fiches');
}

function route_fiche_cout(): void
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

function route_fiche_email(): void
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
    $stmt = db()->prepare('SELECT email FROM employes WHERE id = ?');
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

// -------------------------------------------------------------- SAUVEGARDE
function route_backup(): void
{
    require_login();
    // Snapshot cohérent de la base (indépendant du WAL) via VACUUM INTO.
    $tmp = tempnam(sys_get_temp_dir(), 'bk_') . '.sqlite';
    @unlink($tmp);
    db()->exec("VACUUM INTO " . db()->quote($tmp));
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) param('employeur_nom')) ?? '', '_') ?: 'sauvegarde';
    $nom = $slug . '_' . date('Y-m-d_His') . '.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

// --------------------------------------------------------------- RÉSUMÉS
// Clé + libellé de période pour un mois/année donné.
function periode_cle(string $groupe, int $mois, int $annee): array
{
    return match ($groupe) {
        'mois'      => [sprintf('%02d', $mois), mois_nom($mois)],
        'trimestre' => (function () use ($mois) {
            $t = (int) ceil($mois / 3);
            return [(string) $t, $t . 'ᵉ trimestre (T' . $t . ')'];
        })(),
        'semestre'  => (function () use ($mois) {
            $s = $mois <= 6 ? 1 : 2;
            return [(string) $s, $s . 'ᵉ semestre (S' . $s . ')'];
        })(),
        'annee'     => [(string) $annee, 'Année ' . $annee],
        default     => ['1', ''],
    };
}

// Tableau de bord : salaires à verser + factures émises.
function route_resumes(): void
{
    require_login();
    $aujAnnee = (int) date('Y');
    $aujMois  = (int) date('n');
    $aPayer = [];
    if (module_actif('salaires')) {
        foreach (db()->query("SELECT * FROM fiches WHERE trim(date_paiement) = '' ORDER BY annee, mois") as $f) {
            $estFutur = (int) $f['annee'] > $aujAnnee || ((int) $f['annee'] === $aujAnnee && (int) $f['mois'] > $aujMois);
            if (!$estFutur) {
                $aPayer[] = $f;
            }
        }
    }
    $facturesEmises = module_actif('facturation')
        ? db()->query(
            "SELECT f.*, d.nom AS debiteur_nom FROM factures f JOIN debiteurs d ON d.id = f.debiteur_id
             WHERE f.statut = 'emise' ORDER BY f.date_echeance"
        )->fetchAll()
        : [];
    $comptaSeries = module_actif('compta') ? compta_dashboard_series() : [];
    $prochainsEvenements = module_actif('evenements') ? evenements_a_venir(5) : [];
    render('resumes', [
        'aPayer' => $aPayer, 'facturesEmises' => $facturesEmises, 'comptaSeries' => $comptaSeries,
        'prochainsEvenements' => $prochainsEvenements,
    ], 'Tableau de bord');
}

// Page « Cotisations » : résumé complet (par période) + charges totales.
function route_resume(): void
{
    require_login();
    $annee     = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    $employeId = isset($_GET['employe_id']) ? (int) $_GET['employe_id'] : 0;
    $groupe    = $_GET['groupe'] ?? 'mois'; // annee | semestre | trimestre | mois

    $employes = db()->query('SELECT id, prenom, nom FROM employes ORDER BY nom, prenom')->fetchAll();
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

    render('resume', [
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
