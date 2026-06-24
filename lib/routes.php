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
    $employes = db()->query('SELECT * FROM employes ORDER BY actif DESC, nom, prenom')->fetchAll();
    // Dernière fiche de salaire par employé
    $derniere = [];
    $q = db()->query('SELECT employe_id, annee, mois, salaire_brut, salaire_net
                      FROM fiches ORDER BY annee DESC, mois DESC');
    foreach ($q as $r) {
        $eid = (int) $r['employe_id'];
        if (!isset($derniere[$eid])) {
            $derniere[$eid] = $r;
        }
    }
    render('employes', ['employes' => $employes, 'derniere' => $derniere], 'Employés');
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
        $stmt = db()->prepare('SELECT COUNT(*) FROM fiches WHERE employe_id = ?');
        $stmt->execute([$id]);
        $nb = (int) $stmt->fetchColumn();
        if ($nb === 0) {
            db()->prepare('DELETE FROM employes WHERE id = ?')->execute([$id]);
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
        }
        redirect('taux_horaires', ['ok' => 1]);
    }
    render('taux_horaires', [
        'saved'        => isset($_GET['ok']),
        'tauxHoraires' => db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll(),
    ], 'Salaires horaires standard');
}

function route_unites(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            $libelle = trim($_POST['u_libelle'] ?? '');
            $heures  = (float) str_replace(',', '.', $_POST['u_heures'] ?? '0');
            if ($libelle !== '' && $heures > 0) {
                db()->prepare('INSERT INTO unites (libelle, heures) VALUES (?, ?)')->execute([$libelle, $heures]);
            }
        } elseif ($section === 'del') {
            db()->prepare('DELETE FROM unites WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        }
        redirect('unites', ['ok' => 1]);
    }
    render('unites', [
        'saved'  => isset($_GET['ok']),
        'unites' => db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll(),
    ], 'Unités de temps');
}

function route_export(): void
{
    require_login();
    $annees = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
    $anneesCompta = array_map('intval', db()->query("SELECT DISTINCT substr(date_op,1,4) FROM ecritures ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN));
    render('export', ['annees' => array_map('intval', $annees), 'anneesCompta' => $anneesCompta], 'Exporter les données');
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
    $annee     = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
    $statut    = $_GET['statut'] ?? 'tous'; // tous | apayer | payees
    $employeId = isset($_GET['employe_id']) ? (int) $_GET['employe_id'] : 0;
    $sql    = 'SELECT f.*, e.prenom, e.nom AS emp_nom_actuel
               FROM fiches f JOIN employes e ON e.id = f.employe_id
               WHERE f.annee = ?';
    $params = [$annee];
    if ($statut === 'apayer') {
        $sql .= " AND (f.date_paiement IS NULL OR f.date_paiement = '')";
    } elseif ($statut === 'payees') {
        $sql .= " AND f.date_paiement <> ''";
    }
    if ($employeId) {
        $sql .= ' AND f.employe_id = ?';
        $params[] = $employeId;
    }
    $sql  .= ' ORDER BY f.mois DESC, e.nom';
    $stmt  = db()->prepare($sql);
    $stmt->execute($params);
    $fiches = $stmt->fetchAll();
    $annees = db()->query('SELECT DISTINCT annee FROM fiches ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
    $employes = db()->query('SELECT id, prenom, nom FROM employes ORDER BY nom, prenom')->fetchAll();
    render('fiches', ['fiches' => $fiches, 'annee' => $annee, 'annees' => $annees, 'statut' => $statut,
        'employes' => $employes, 'employeId' => $employeId], 'Fiches de salaire');
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
        $lignes[] = ['libelle' => trim($lib), 'heures_unite' => $hu, 'quantite' => $qte, 'taux_horaire' => $taux_h];
        $heures += $h;
        $salaireTravail += $h * $taux_h;
    }
    return [$lignes, $heures, $salaireTravail];
}

function route_fiche_new(): void
{
    require_login();
    $employes     = db()->query('SELECT * FROM employes WHERE actif = 1 ORDER BY nom, prenom')->fetchAll();
    $tauxHoraires = db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll();
    $unites       = db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll();
    $renderForm = fn($err) => render('fiche_form', [
        'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites,
        'err' => $err, 'post' => $_POST,
        'edit_mode' => isset($_POST['fiche_id']), 'fiche_id' => (int) ($_POST['fiche_id'] ?? 0),
    ], 'Nouvelle fiche');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Pré-remplissage de l'employé si on arrive depuis sa page
        $pre = isset($_GET['employe_id']) ? ['employe_id' => (int) $_GET['employe_id']] : null;
        render('fiche_form', [
            'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites,
            'err' => null, 'post' => $pre,
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

    $taux = taux_pour_annee($annee); // taux figés selon l'année de la fiche
    // LAA : taux réduit ou plein selon le total d'heures du mois (seuil = jours/7×8)
    $taux = array_merge($taux, laa_effectif($taux, $heures, $annee, $mois));
    $c    = calculer_fiche($emp, $salaireTravail, $taux);

    $data = [
        'employe_id'     => $empId,
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
        'afficher_cout_emp' => isset($_POST['afficher_cout_emp']) ? 1 : 0,
        'taux_json'      => json_encode($taux + ['impot_source' => (float) $emp['impot_source_taux']]),
    ] + $c;

    $cols  = implode(',', array_keys($data));
    $marks = ':' . implode(',:', array_keys($data));
    try {
        db()->beginTransaction();
        if (isset($_POST['fiche_id'])) {
            $ficheId = (int) $_POST['fiche_id'];
            $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ?');
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
        $insL = db()->prepare('INSERT INTO fiche_lignes (fiche_id, libelle, heures_unite, quantite, taux_horaire, ordre) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $ordre => $l) {
            $insL->execute([$ficheId, $l['libelle'], $l['heures_unite'], $l['quantite'], $l['taux_horaire'], $ordre]);
        }
        db()->commit();
    } catch (PDOException $ex) {
        db()->rollBack();
        if (str_contains($ex->getMessage(), 'UNIQUE')) {
            $renderForm('Une fiche existe déjà pour cet employé sur ' . mois_nom($mois) . ' ' . $annee . '.');
            return;
        }
        throw $ex;
    } catch (RuntimeException $ex) {
        db()->rollBack();
        $renderForm($ex->getMessage());
        return;
    }
    redirect('fiche', ['id' => $ficheId, 'success' => '1']);
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
    render('fiche_view', [
        'f' => $f, 'modifiable' => $modifiable, 'saved' => $_GET['ok'] ?? null,
        'mail' => $_GET['mail'] ?? null,
        'emailEmploye' => $emailEmploye, 'emailExp' => $emailExp,
    ], 'Fiche ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']);
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

    // Crée un élément <sd:Nom> avec texte optionnel.
    $el = function (string $name, ?string $text = null) use ($doc, $NS): DOMElement {
        $n = $doc->createElementNS($NS, 'sd:' . $name);
        if ($text !== null && $text !== '') {
            $n->appendChild($doc->createTextNode($text));
        }
        return $n;
    };

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

    $stmtLignes = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ? ORDER BY ordre');
    $stmtLignes->execute([$id]);
    $lignes = $stmtLignes->fetchAll();

    $postData = [
        'employe_id'        => $f['employe_id'],
        'annee'             => $f['annee'],
        'mois'              => $f['mois'],
        'date_paiement'     => $f['date_paiement'],
        'supplement_vacances' => rtrim(rtrim(number_format((float) $f['supplement_taux'] * 100, 4, '.', ''), '0'), '.'),
        'afficher_cout_emp' => $f['afficher_cout_emp'],
    ];
    // Impôt source : pas stocké en colonne dédiée → repris du JSON figé
    $tj = json_decode($f['taux_json'] ?: '{}', true) ?: [];
    if (!empty($tj['impot_source'])) {
        $postData['impot_source_taux'] = rtrim(rtrim(number_format((float) $tj['impot_source'] * 100, 4, '.', ''), '0'), '.');
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
            $postData['l_taux_manuel'][$i] = rtrim(rtrim(number_format($taux_h, 2, '.', ''), '0'), '.');
        }
    }

    render('fiche_form', [
        'employes' => $employes, 'tauxHoraires' => $tauxHoraires, 'unites' => $unites,
        'err' => null, 'post' => $postData, 'edit_mode' => true, 'fiche_id' => $id,
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

function route_resumes(): void
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
    $aPayerListe = []; // fiches encore à payer (pour la section « Salaires à verser »)
    foreach ($fiches as $f) {
        [$cle, $label] = periode_cle($groupe, (int) $f['mois'], (int) $f['annee']);
        if (!isset($buckets[$cle])) {
            $buckets[$cle] = $vide;
            $buckets[$cle]['label'] = $label;
        }
        $aPayer     = trim((string) $f['date_paiement']) === '';
        // Une fiche dont la période commence le mois prochain ou plus tard n'est pas encore « à verser ».
        $estFutur   = (int) $f['annee'] > (int) date('Y')
            || ((int) $f['annee'] === (int) date('Y') && (int) $f['mois'] > (int) date('n'));
        if ($aPayer && !$estFutur) {
            $aPayerListe[] = $f;
        }
        $chargesSoc = (float) $f['total_deductions'] - (float) $f['ded_impot_source'];
        $vals = [
            'brut'        => (float) $f['salaire_brut'],
            'charges_soc' => $chargesSoc,
            'impots'      => (float) $f['ded_impot_source'],
            'net'         => (float) $f['salaire_net'],
            'reste'       => $aPayer ? (float) $f['salaire_net'] : 0.0,
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

    render('resumes', [
        'annee'     => $annee,
        'annees'    => $annees,
        'employes'  => $employes,
        'employeId' => $employeId,
        'groupe'    => $groupe,
        'buckets'   => $buckets,
        'totaux'    => $totaux,
        'champs'    => $champs,
        'aPayer'    => $aPayerListe,
        'retenues'  => $retenues,
        'retCols'   => $retCols,
        'retNb'     => $retNb,
        'retAnnee'  => $retAnnee,
    ], 'Tableau de bord');
}
