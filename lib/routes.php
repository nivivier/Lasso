<?php
// Handlers de routes (un par action). index.php se contente de dispatcher.

// ----------------------------------------------------------------- AUTH
// Secret d'installation : si SETUP_SECRET est défini (non vide), l'écran setup
// n'est accessible qu'avec la bonne clé (?key=… ou champ caché du formulaire).
function setup_secret_defini(): bool
{
    return defined('SETUP_SECRET') && SETUP_SECRET !== '';
}

function setup_secret_ok(): bool
{
    if (!setup_secret_defini()) {
        return true; // protection désactivée (local) — en prod, voir route_installation()
    }
    $key = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
    return $key !== '' && hash_equals(SETUP_SECRET, $key);
}

function route_installation(): void
{
    if (has_users()) {
        redirect('connexion');
    }
    // En production, l'écran de création du premier compte exige un secret
    // d'installation. Sans lui, il resterait ouvert à quiconque passe entre la
    // mise en ligne et la première connexion de l'administrateur.
    //
    // Ce cas est distingué de la clé erronée juste en dessous, et répond par un
    // message explicite plutôt qu'un 404 muet : c'est l'exploitant qui a besoin
    // de savoir pourquoi l'écran refuse de s'ouvrir, et le message ne donne
    // aucune prise — il n'existe aucun moyen de créer un compte sans le secret.
    // Un 404 ici rendrait une première installation légitime indiagnosticable.
    if (APP_ENV === 'prod' && !setup_secret_defini()) {
        http_response_code(503);
        exit("Installation désactivée : définissez SETUP_SECRET dans lib/config.local.php "
            . "(une longue valeur aléatoire), puis ouvrez ?p=installation&key=<ce secret>.");
    }
    if (!setup_secret_ok()) {
        // Clé absente ou fausse alors qu'un secret est défini : on ne révèle pas
        // l'existence de l'écran.
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
            render('installation', ['err' => $err, 'email' => $email, 'key' => $key], 'Installation');
            return;
        }
        $stmt = db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)');
        $stmt->execute([$email, hacher_mot_de_passe($mdp)]);
        $uid = (int) db()->lastInsertId();
        // Premier compte : accès complet (administrateur) pour pouvoir
        // configurer l'application dès l'installation — les comptes créés
        // ensuite démarrent sans aucun droit (voir route_utilisateurs()).
        enregistrer_permissions_utilisateur($uid, array_fill_keys(PERMISSION_MODULES, 'ecriture'));
        session_regenerate_id(true);
        $_SESSION['uid']           = $uid;
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
        redirect('tableau_bord');
    }
    render('installation', ['err' => null, 'email' => '', 'key' => $key], 'Installation');
}

function route_connexion(): void
{
    if (current_user()) {
        redirect('tableau_bord');
    }
    $ip = client_ip();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        // L'identifiant est lu AVANT le contrôle de blocage : celui-ci compte
        // désormais aussi les échecs visant ce compte, toutes IP confondues
        // (login_is_locked()), ce qui ferme l'angle mort du comptage par IP seule.
        $email = trim($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';
        // Anti-force-brute : blocage temporaire après trop d'échecs.
        if (login_is_locked($ip, $email)) {
            $min = (int) ceil(LOGIN_WINDOW / 60);
            render('connexion', ['err' => "Trop de tentatives. Réessayez dans $min minutes.", 'email' => ''], 'Connexion');
            return;
        }
        usleep(random_int(200000, 500000)); // ralentit l'automatisation
        $stmt  = db()->prepare('SELECT * FROM utilisateurs WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($mdp, $u['mot_de_passe'])) {
            // Les deux compteurs sont purgés : sans le volet e-mail, un
            // utilisateur légitime qui se trompe cinq fois puis réussit
            // resterait bloqué par son propre compteur de compte au prochain
            // essai, jusqu'à expiration de la fenêtre.
            login_clear_failures($ip, $email);
            rehacher_si_necessaire((int) $u['id'], $mdp, (string) $u['mot_de_passe']);
            // Horodaté par PHP, pas par SQLite : la valeur est relue et affichée
            // avec date(), donc elle doit venir de la même horloge (datetime('now')
            // rend de l'UTC, ce qui décalerait l'heure affichée).
            db()->prepare('UPDATE utilisateurs SET derniere_connexion_le = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), (int) $u['id']]);
            session_regenerate_id(true);
            $_SESSION['uid']           = (int) $u['id'];
            $_SESSION['login_time']    = time();
            $_SESSION['last_activity'] = time();
            redirect('tableau_bord');
        }
        login_record_failure($ip, $email);
        render('connexion', ['err' => 'Identifiants incorrects.', 'email' => $email], 'Connexion');
        return;
    }
    $msg = isset($_GET['expired']) ? 'Session expirée. Reconnectez-vous.' : null;
    if (isset($_GET['reinit'])) {
        $msg = 'Mot de passe modifié. Connectez-vous avec le nouveau.';
    }
    // Retour d'une restauration : c'est le seul endroit où l'on puisse encore
    // dire ce qui s'est passé — la session vient d'être détruite, et les
    // comptes ne sont plus les mêmes. Le nom du .bak y figure parce que c'est
    // la seule chose à retenir si l'on s'est trompé d'archive.
    if (isset($_GET['restaure'])) {
        $n = (int) ($_GET['fichiers'] ?? 0);
        $msg = 'Sauvegarde restaurée' . ($n > 0 ? ' (' . $n . ' fichier(s) déposé(s) remis en place)' : '')
            . '. Connectez-vous avec un compte de cette sauvegarde.';
        $avant = basename((string) ($_GET['avant'] ?? ''));
        if ($avant !== '') {
            $msg .= ' La base précédente a été conservée sous « ' . $avant .' » à côté de la base.';
        }
    }
    render('connexion', ['err' => null, 'info' => $msg, 'email' => ''], 'Connexion');
}

function route_deconnexion(): void
{
    logout_session();
    redirect('connexion');
}

// « J'ai oublié mon mot de passe » : on demande l'adresse, on envoie un lien.
//
// La réponse est TOUJOURS la même, que le compte existe ou non : sinon ce
// formulaire deviendrait un moyen de vérifier quelles adresses ont un compte.
// C'est aussi pour cela qu'aucune erreur d'envoi n'est montrée à l'écran.
function route_motdepasse_oublie(): void
{
    if (current_user()) {
        redirect('mon_compte');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render('motdepasse_oublie', ['envoye' => isset($_GET['envoye'])], 'Mot de passe oublié');
        return;
    }
    check_csrf();
    $email = trim($_POST['email'] ?? '');
    usleep(random_int(200000, 500000)); // même ralentissement que la connexion
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = db()->prepare('SELECT id, email FROM utilisateurs WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        $ip = client_ip();
        if ($u && !reinit_trop_de_demandes($ip, (int) $u['id'])) {
            $jeton = reinit_creer_jeton((int) $u['id'], $ip);
            $lien  = url_site() . '/?p=motdepasse_reinitialiser&jeton=' . rawurlencode($jeton);
            [$ok] = envoyer_email_reinit((string) $u['email'], $lien);
            if (!$ok) {
                // Journalisé côté serveur uniquement : l'écran ne dit rien de
                // plus, mais l'exploitant doit pouvoir diagnostiquer.
                error_log('[app] Réinitialisation : échec de l\'envoi.');
            }
        }
    }
    redirect('motdepasse_oublie', ['envoye' => 1]);
}

// Choix du nouveau mot de passe, sur présentation du jeton reçu par e-mail.
// Le jeton est revalidé à l'affichage ET à l'enregistrement : entre les deux,
// il a pu expirer ou servir ailleurs.
function route_motdepasse_reinitialiser(): void
{
    if (current_user()) {
        redirect('mon_compte');
    }
    $jeton   = (string) ($_POST['jeton'] ?? $_GET['jeton'] ?? '');
    $demande = reinit_demande($jeton);
    if (!$demande) {
        render('motdepasse_reinitialiser', ['jeton' => '', 'err' => null, 'invalide' => true], 'Nouveau mot de passe');
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render('motdepasse_reinitialiser', ['jeton' => $jeton, 'err' => null, 'invalide' => false], 'Nouveau mot de passe');
        return;
    }
    check_csrf();
    $nouveau = $_POST['mot_de_passe'] ?? '';
    $confirm = $_POST['confirmer'] ?? '';
    $err = null;
    if (strlen($nouveau) < PASSWORD_MIN) {
        $err = 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
    } elseif ($nouveau !== $confirm) {
        $err = 'La confirmation ne correspond pas.';
    }
    if ($err) {
        render('motdepasse_reinitialiser', ['jeton' => $jeton, 'err' => $err, 'invalide' => false], 'Nouveau mot de passe');
        return;
    }
    db()->beginTransaction();
    db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
        ->execute([hacher_mot_de_passe($nouveau), (int) $demande['utilisateur_id']]);
    // Le jeton est consommé, et les autres demandes en attente du compte sont
    // annulées : une seule des demandes envoyées doit pouvoir servir.
    db()->prepare('UPDATE reinit_motdepasse SET utilise_le = ? WHERE id = ?')
        ->execute([time(), (int) $demande['id']]);
    db()->prepare('DELETE FROM reinit_motdepasse WHERE utilisateur_id = ? AND utilise_le IS NULL')
        ->execute([(int) $demande['utilisateur_id']]);
    // Le compte a pu être bloqué par les tentatives ratées qui ont mené jusqu'ici.
    login_clear_failures(client_ip(), (string) $demande['email']);
    db()->commit();
    // Pas de connexion automatique : celui qui ouvre le lien prouve qu'il a
    // accès à la boîte, pas qu'il est devant le bon écran.
    redirect('connexion', ['reinit' => 1]);
}

function route_mon_compte(): void
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
            render('mon_compte', ['u' => ['prenom' => $prenom, 'nom' => $nom, 'email' => $email] + $u, 'err' => $err, 'saved' => null], 'Mon compte');
            return;
        }
        $hash = $nouveau !== '' ? hacher_mot_de_passe($nouveau) : $u['mot_de_passe'];
        // Compte rattaché à une personne : son identité vient du carnet, et le
        // formulaire ne l'a même pas proposée. On ne la réécrit donc pas.
        $rattache = (int) ($u['personne_id'] ?? 0) > 0;
        db()->prepare('UPDATE utilisateurs SET prenom = ?, nom = ?, email = ?, mot_de_passe = ? WHERE id = ?')
            ->execute([$rattache ? '' : $prenom, $rattache ? '' : $nom, $email, $hash, $u['id']]);
        redirect('mon_compte', ['ok' => 1]);
    }
    render('mon_compte', ['u' => $u, 'err' => null, 'saved' => $_GET['ok'] ?? null], 'Mon compte');
}

// -------------------------------------------------------------- COMPTES (admin)
// Gestion des comptes utilisateurs : liste, création, réinitialisation,
// suppression, droits par module (voir SPEC_PERMISSIONS.md). Réservé à
// l'écriture cœur (index.php) : un compte avec accès en lecture seule au
// cœur ne voit même pas cette page.
function route_utilisateurs(): void
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
            // Aucun droit par défaut (principe du moindre privilège) : à
            // l'inverse du tout premier compte (route_installation), qui reçoit
            // tout pour pouvoir configurer l'application dès l'installation.
            db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)')
                ->execute([$email, hacher_mot_de_passe($mdp)]);
            // Le compte est relu en base plutôt que reconstitué de mémoire : la
            // ligne rendue doit dire ce qui existe vraiment, dates comprises.
            $nouveau = db()->prepare('SELECT id, email, prenom, nom, personne_id, derniere_connexion_le, cree_le
                                        FROM utilisateurs WHERE id = ?');
            $nouveau->execute([(int) db()->lastInsertId()]);
            $ligne = $nouveau->fetch();
            if (reponse_ajout_json(null, rendre_fragment('_compte_ligne', [
                'c'         => $ligne,
                'niveaux'   => permissions_utilisateur((int) $ligne['id']),
                'moi'       => (int) current_user()['id'],
                'personnes' => personnes_options(),
            ]))) {
                return;
            }
            redirect('utilisateurs', ['ok' => 'created']);
        }
        // Erreur métier : en arrière-plan elle revient en JSON et s'affiche
        // au-dessus du formulaire, qui garde la saisie (docs/UI.md § 5).
        if (reponse_ajout_json($err)) {
            return;
        }
    }
    // personne_id + le nom de la personne rattachée : le compte de connexion est
    // l'une des trois choses qui peuvent désigner la même personne (un contact,
    // une employée, un compte — SPEC_PERSONNES.md), et c'est ici qu'on fait le
    // lien, faute de quoi la colonne « Rattachements » de l'annuaire ne pouvait
    // jamais dire « compte ».
    $comptes = db()->query('SELECT id, email, prenom, nom, personne_id, derniere_connexion_le, cree_le
                            FROM utilisateurs ORDER BY cree_le, id')->fetchAll();
    $permissions = [];
    foreach ($comptes as $c) {
        $permissions[(int) $c['id']] = permissions_utilisateur((int) $c['id']);
    }
    render('utilisateurs', [
        'comptes'     => $comptes,
        'personnes'   => personnes_options(),
        'permissions' => $permissions,
        'err'         => $err,
        'emailSaisi'  => $emailSaisi,
        'ok'          => $_GET['ok'] ?? null,
        'flagErr'     => $_GET['err'] ?? null,
        'moi'         => (int) current_user()['id'],
    ], 'Paramètres — Comptes');
}

// Édition d'un compte depuis ?p=utilisateurs : identité, adresse de connexion, et
// mot de passe si l'on en saisit un. Un seul enregistrement pour toute la
// ligne — c'est le crayon qui ouvre l'ensemble, rien n'est modifiable en
// lecture. Cette route n'existe que pour un administrateur (écriture sur
// « cœur », voir index.php).
function route_utilisateur_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('utilisateurs');
    }
    check_csrf();
    $id    = (int) ($_POST['id'] ?? 0);
    $mdp   = (string) ($_POST['nouveau_mot_de_passe'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));

    $stmt = db()->prepare('SELECT email FROM utilisateurs WHERE id = ?');
    $stmt->execute([$id]);
    $ancienEmail = $stmt->fetchColumn();
    if ($ancienEmail === false) {
        redirect('utilisateurs');
    }
    if ($mdp !== '' && strlen($mdp) < PASSWORD_MIN) {
        redirect('utilisateurs', ['err' => 'short']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect('utilisateurs', ['err' => 'email']);
    }
    $dejaPris = db()->prepare('SELECT id FROM utilisateurs WHERE email = ? AND id <> ?');
    $dejaPris->execute([$email, $id]);
    if ($dejaPris->fetch()) {
        redirect('utilisateurs', ['err' => 'email_pris']);
    }

    // Les droits font partie de la ligne : un seul enregistrement pour tout ce
    // que le crayon a ouvert. Le refus (dernier administrateur) est vérifié
    // AVANT d'écrire quoi que ce soit, pour ne pas laisser la ligne à moitié
    // enregistrée.
    $niveaux = is_array($_POST['niveaux'] ?? null) ? $_POST['niveaux'] : [];
    if (!enregistrer_permissions_utilisateur($id, $niveaux)) {
        redirect('utilisateurs', ['err' => 'last_admin']);
    }
    // Le rattachement à une personne de l'annuaire. '' = aucune, et une fiche
    // qui n'existe pas (ou plus) vaut aucune : la colonne accepte NULL, et
    // c'est ce qu'on y met plutôt qu'un identifiant mort.
    $personneId = (int) ($_POST['personne_id'] ?? 0);
    if ($personneId && !personne_charger($personneId)) {
        $personneId = 0;
    }
    // Rattaché à une personne, le compte n'a plus d'identité à lui : on VIDE
    // ses champs plutôt que de laisser deux noms vivre côte à côte et diverger.
    // C'est ce que la refonte des personnes devait finir (SPEC_PERSONNES.md).
    db()->prepare('UPDATE utilisateurs SET prenom = ?, nom = ?, email = ?, personne_id = ? WHERE id = ?')
        ->execute([
            $personneId ? '' : trim((string) ($_POST['prenom'] ?? '')),
            $personneId ? '' : trim((string) ($_POST['nom'] ?? '')),
            $email,
            $personneId ?: null,
            $id,
        ]);
    // L'adresse a changé : les liens de réinitialisation en attente ont été
    // envoyés à l'ancienne, ils ne doivent plus ouvrir ce compte.
    if ((string) $ancienEmail !== $email) {
        db()->prepare('DELETE FROM reinit_motdepasse WHERE utilisateur_id = ? AND utilise_le IS NULL')
            ->execute([$id]);
    }
    // Mot de passe laissé vide = inchangé : on ne réinitialise que sur demande.
    if ($mdp !== '') {
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
            ->execute([hacher_mot_de_passe($mdp), $id]);
        redirect('utilisateurs', ['ok' => 'reset']);
    }
    redirect('utilisateurs', ['ok' => 'modified']);
}

function route_utilisateur_supprimer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('utilisateurs');
    }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $moi = (int) current_user()['id'];
    if ($id === $moi) {
        redirect('utilisateurs', ['err' => 'self']);     // pas d'auto-suppression
    }
    $total = (int) db()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    if ($total <= 1) {
        redirect('utilisateurs', ['err' => 'last']);      // ne jamais vider la table
    }
    // Ne jamais supprimer le dernier administrateur (écriture cœur), même
    // s'il reste d'autres comptes non-admin.
    if (permission_donne_ecriture(permissions_utilisateur($id), 'coeur') && nb_admins() <= 1) {
        redirect('utilisateurs', ['err' => 'last_admin']);
    }
    db()->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$id]);
    redirect('utilisateurs', ['ok' => 'deleted']);
}

// Met à jour la matrice de droits (lecture/écriture par module) d'un compte.
// POST { id, niveaux[module] = ''|'lecture'|'ecriture' }. Refuse (message
// d'erreur dédié) si l'opération viderait le dernier compte administrateur —
// voir enregistrer_permissions_utilisateur().

// Activation/désactivation des modules optionnels (salaires, compta, analytique).
// Un module à la fois (interrupteur à bascule immédiate, comme les règles de
// lettrage et les axes analytiques) : POST { module, actif? }.
function route_modules(): void
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
        redirect('modules');
    }
    render('modules', ['actifs' => modules_actifs()], 'Modules');
}

// Liste de pays configurable (Paramètres → Pays, voir migration_43 et
// pays_liste()/pays_drapeau()/pays_options_*() dans lib/helpers.php) — partagée
// par tous les champs pays de l'app (structures, lieux, employeur, événements,
// facturation). Liste à plat (pas de hiérarchie) : même interface de
// glisser-déposer que projets.php/compta_plan.php/parametres_structures.php
// (lassoPlanArbre()), sans reparent puisqu'il n'y a qu'un seul niveau.
function route_pays(): void
{
    require_login();
    // Carte id → ligne (pays + régions) pour raisonner sur la hiérarchie.
    $paysMap = function (): array {
        $m = [];
        foreach (pays_liste_arbre() as $r) { $m[(int) $r['id']] = $r; }
        return $m;
    };
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        $map = $paysMap();
        if ($section === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            if ($parent === null) {
                // Nouveau PAYS : code ISO2 obligatoire, nom + code uniques.
                $code = strtoupper(trim($_POST['code_iso2'] ?? ''));
                if ($nom !== '' && preg_match('/^[A-Z]{2}$/', $code)) {
                    $existe = db()->prepare('SELECT 1 FROM pays_liste WHERE parent_id IS NULL AND (nom = ? OR code_iso2 = ?)');
                    $existe->execute([$nom, $code]);
                    if (!$existe->fetchColumn()) {
                        $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM pays_liste WHERE parent_id IS NULL')->fetchColumn();
                        db()->prepare('INSERT INTO pays_liste (nom, code_iso2, ordre) VALUES (?, ?, ?)')->execute([$nom, $code, $ordre]);
                    }
                }
            } else {
                // Nouvelle RÉGION sous un pays (sans code), nom unique dans le pays.
                $parentValide = isset($map[$parent]) && plan_pid($map[$parent]['parent_id'] ?? null) === 0;
                if ($nom !== '' && $parentValide) {
                    $existe = db()->prepare('SELECT 1 FROM pays_liste WHERE parent_id = ? AND nom = ?');
                    $existe->execute([$parent, $nom]);
                    if (!$existe->fetchColumn()) {
                        $stmtOrdre = db()->prepare('SELECT COALESCE(MAX(ordre),0)+1 FROM pays_liste WHERE parent_id = ?');
                        $stmtOrdre->execute([$parent]);
                        db()->prepare('INSERT INTO pays_liste (parent_id, nom, code_iso2, ordre) VALUES (?, ?, NULL, ?)')
                            ->execute([$parent, $nom, (int) $stmtOrdre->fetchColumn()]);
                    }
                }
            }
        } elseif ($section === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '' && isset($map[$id])) {
                $estPays = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                $ancien = (string) $map[$id]['nom'];
                if ($estPays) {
                    // Renommage d'un pays : propage à adresse_pays/pays/employeur_pays.
                    $code = strtoupper(trim($_POST['code_iso2'] ?? ''));
                    if (preg_match('/^[A-Z]{2}$/', $code)) {
                        db()->beginTransaction();
                        db()->prepare('UPDATE pays_liste SET nom=?, code_iso2=? WHERE id=?')->execute([$nom, $code, $id]);
                        if ($ancien !== $nom) {
                            db()->prepare('UPDATE structures SET adresse_pays=? WHERE adresse_pays=?')->execute([$nom, $ancien]);
                            db()->prepare("UPDATE parametres SET valeur=? WHERE cle='employeur_pays' AND valeur=?")->execute([$nom, $ancien]);
                            // Cache de géocodage (lieux_geocodage.cle = ville|departement_canton|pays,
                            // tout en minuscules — voir geocodage_cle()) : le pays fait partie de la
                            // clé, donc un renommage la rend orpheline sans ce correctif. Sans lui,
                            // ?p=structures&non_localises=1 réafficherait à tort des fiches déjà
                            // géolocalisées avec succès (la comparaison ne retrouve plus la clé).
                            $ancienCle = mb_strtolower($ancien, 'UTF-8');
                            $nomCle = mb_strtolower($nom, 'UTF-8');
                            db()->prepare(
                                "UPDATE lieux_geocodage SET cle = SUBSTR(cle, 1, LENGTH(cle) - LENGTH(?)) || ?
                                 WHERE cle LIKE '%|' || ? ESCAPE '\\'"
                            )->execute([$ancienCle, $nomCle, like_echappe($ancienCle)]);
                        }
                        db()->commit();
                    }
                } else {
                    // Renommage d'une région : propage à grande_region des fiches du
                    // même pays (scopé pour ne pas toucher une région homonyme d'un
                    // autre pays).
                    $paysNom = (string) ($map[plan_pid($map[$id]['parent_id'] ?? null)]['nom'] ?? '');
                    db()->beginTransaction();
                    db()->prepare('UPDATE pays_liste SET nom=? WHERE id=?')->execute([$nom, $id]);
                    if ($ancien !== $nom && $paysNom !== '') {
                        db()->prepare('UPDATE structures SET grande_region=? WHERE grande_region=? AND adresse_pays=?')->execute([$nom, $ancien, $paysNom]);
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'move') {
            $id = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            if (isset($map[$id])) {
                $pidParent = plan_pid($map[$id]['parent_id'] ?? null);
                $freres = plan_enfants($map)[$pidParent] ?? [];
                $ids = array_map(fn ($r) => (int) $r['id'], $freres);
                $pos = array_search($id, $ids, true);
                $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    $upd = db()->prepare('UPDATE pays_liste SET ordre = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($ids as $i => $pid) { $upd->execute([$i, $pid]); }
                    db()->commit();
                }
            }
        } elseif ($section === 'reorder') {
            $id = (int) ($_POST['id'] ?? 0);
            $parentPost = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $order = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            if (isset($map[$id]) && $order) {
                $estPays = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                if ($estPays) {
                    // Un pays reste racine : simple réordonnancement entre pays.
                    $parent = null;
                    $parentValide = true;
                } else {
                    // Une région reste sous un pays (jamais promue racine ni nichée
                    // sous une autre région).
                    $parentValide = $parentPost !== null && isset($map[$parentPost]) && plan_pid($map[$parentPost]['parent_id'] ?? null) === 0;
                    $parent = $parentPost;
                }
                if ($parentValide) {
                    db()->beginTransaction();
                    // Reparent (déplacement d'une région d'un pays à l'autre) + propage
                    // le pays des fiches concernées pour rester cohérent avec le texte.
                    if (!$estPays) {
                        $ancienParent = plan_pid($map[$id]['parent_id'] ?? null);
                        if ($ancienParent !== (int) $parent) {
                            $ancienPays = (string) ($map[$ancienParent]['nom'] ?? '');
                            $nouveauPays = (string) ($map[(int) $parent]['nom'] ?? '');
                            $regionNom = (string) $map[$id]['nom'];
                            if ($ancienPays !== '' && $nouveauPays !== '') {
                                db()->prepare('UPDATE structures SET adresse_pays=? WHERE grande_region=? AND adresse_pays=?')->execute([$nouveauPays, $regionNom, $ancienPays]);
                            }
                        }
                    }
                    db()->prepare('UPDATE pays_liste SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
                    $upd = db()->prepare('UPDATE pays_liste SET ordre = ? WHERE id = ?');
                    $i = 0;
                    foreach ($order as $oid) {
                        if ($oid === $id || (isset($map[$oid]) && plan_pid($map[$oid]['parent_id'] ?? null) === plan_pid($parent))) {
                            $upd->execute([$i++, $oid]);
                        }
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $cible = trim($_POST['reaffecter_vers'] ?? '');
            if (isset($map[$id])) {
                $estPays = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                $nom = (string) $map[$id]['nom'];
                if ($estPays) {
                    // Un pays encore utilisé, ayant des régions, ou dernier pays : refus.
                    $stmtEnf = db()->prepare('SELECT COUNT(*) FROM pays_liste WHERE parent_id = ?');
                    $stmtEnf->execute([$id]);
                    $stmtRefS = db()->prepare('SELECT COUNT(*) FROM structures WHERE adresse_pays = ?');
                    $stmtRefS->execute([$nom]);
                    $stmtRefE = db()->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'employeur_pays' AND valeur = ?");
                    $stmtRefE->execute([$nom]);
                    $total = (int) db()->query('SELECT COUNT(*) FROM pays_liste WHERE parent_id IS NULL')->fetchColumn();
                    if ((int) $stmtEnf->fetchColumn() === 0 && (int) $stmtRefS->fetchColumn() === 0 && (int) $stmtRefE->fetchColumn() === 0 && $total > 1) {
                        db()->prepare('DELETE FROM pays_liste WHERE id = ?')->execute([$id]);
                    } else {
                        redirect('pays', ['err' => 'used']);
                        return;
                    }
                } else {
                    // Région : réaffecter grande_region des fiches (du même pays) vers
                    // une autre région du pays, ou '' (vider), puis supprimer.
                    $parentId = plan_pid($map[$id]['parent_id'] ?? null);
                    $paysNom = (string) ($map[$parentId]['nom'] ?? '');
                    $stmtRefS = db()->prepare('SELECT COUNT(*) FROM structures WHERE grande_region = ? AND adresse_pays = ?');
                    $stmtRefS->execute([$nom, $paysNom]);
                    $utilisee = (int) $stmtRefS->fetchColumn() > 0;
                    if (!$utilisee) {
                        db()->prepare('DELETE FROM pays_liste WHERE id = ?')->execute([$id]);
                    } else {
                        // Cible validée par IDENTIFIANT (une AUTRE région du même pays),
                        // pas par nom : deux régions peuvent ne différer que par la
                        // casse (variantes d'import « Hauts-De-France » /
                        // « Hauts-de-France »), et une comparaison de noms les
                        // rejetterait à tort. '' = vider le champ.
                        $cibleValide = $cible === '';
                        if (!$cibleValide) {
                            $ok = db()->prepare('SELECT id FROM pays_liste WHERE nom = ? AND parent_id = ?');
                            $ok->execute([$cible, $parentId]);
                            $cibleId = $ok->fetchColumn();
                            $cibleValide = $cibleId !== false && (int) $cibleId !== $id;
                        }
                        if ($cibleValide) {
                            db()->beginTransaction();
                            db()->prepare('UPDATE structures SET grande_region = ? WHERE grande_region = ? AND adresse_pays = ?')->execute([$cible, $nom, $paysNom]);
                            db()->prepare('DELETE FROM pays_liste WHERE id = ?')->execute([$id]);
                            db()->commit();
                        } else {
                            redirect('pays', ['err' => 'region_used']);
                            return;
                        }
                    }
                }
            }
        }
        redirect('pays', ['ok' => 1]);
    }

    // Nombre de structures par pays et par région, indexé par id de la ligne :
    // affiché sur chaque ligne, et pour une région c'est aussi ce qui décide si
    // sa suppression exige une réaffectation.
    // DEUX agrégats, pas une requête par région : la boucle en faisait une par
    // ligne (31 requêtes pour 3,3 ms, contre 0,5 ms ainsi) — et surtout c'est le
    // motif que le reste du code évite partout ailleurs.
    // Les régions sont comptées sur le COUPLE (pays, région) : deux pays peuvent
    // porter une région homonyme.
    $map = $paysMap();
    $usageParPays = [];
    foreach (db()->query('SELECT adresse_pays AS nom, COUNT(*) n FROM structures GROUP BY adresse_pays') as $r) {
        $usageParPays[(string) $r['nom']] = (int) $r['n'];
    }
    $usageParRegion = [];
    foreach (db()->query("SELECT adresse_pays, grande_region, COUNT(*) n FROM structures WHERE grande_region <> '' GROUP BY 1, 2") as $r) {
        $usageParRegion[(string) $r['adresse_pays'] . "\0" . (string) $r['grande_region']] = (int) $r['n'];
    }
    $usagePays = [];
    $usageRegion = [];
    foreach ($map as $id => $r) {
        if (plan_pid($r['parent_id'] ?? null) === 0) {
            $usagePays[(int) $id] = $usageParPays[(string) $r['nom']] ?? 0;
            continue;
        }
        $paysNom = (string) ($map[plan_pid($r['parent_id'] ?? null)]['nom'] ?? '');
        $usageRegion[(int) $id] = $usageParRegion[$paysNom . "\0" . (string) $r['nom']] ?? 0;
    }
    render('pays', [
        'saved' => isset($_GET['ok']),
        'err' => $_GET['err'] ?? null,
        'lignes' => plan_liste_ordonnee($map),
        'map' => $map,
        'usageRegion' => $usageRegion,
        'usagePays' => $usagePays,
    ], 'Paramètres — Pays');
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
            foreach (['logo_clair'       => 'employeur_logo_clair',
                      'logo_sombre'      => 'employeur_logo_sombre',
                      'logo_mini_clair'  => 'employeur_logo_mini_clair',
                      'logo_mini_sombre' => 'employeur_logo_mini_sombre'] as $field => $cle) {
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
        // Couleurs : déplacées vers l'onglet Application → Apparence (route_apparence).
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

// Apparence (section de l'onglet Application) : couleurs de l'application et
// image de fond. Les teintes dérivées sont recalculées au rendu
// (couleurs_css_vars), il suffit donc d'enregistrer les deux couleurs de
// base ; valeur invalide ignorée. Image de fond : même traitement que les
// logos employeur (handle_logo_upload(), lib/helpers.php), remplace
// assets/fond.jpg par défaut tant qu'aucun fichier n'est envoyé — suppression
// de l'image personnalisée gérée à part par un bouton dédié, voir
// route_apparence_fond_supprimer(). Effets clair/flouté (cases à cocher
// combinables) appliqués en filter CSS sur un ::before dédié, jamais sur
// <body> — voir couleurs_css_vars().
function route_apparence(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        try {
            $fond = handle_logo_upload('fond');
        } catch (RuntimeException $ex) {
            render('apparence', ['saved' => null, 'err' => $ex->getMessage()], 'Apparence');
            return;
        }
        $stmt = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        foreach (['employeur_couleur_principale', 'employeur_couleur_evidence'] as $cleCouleur) {
            $couleur = trim($_POST[$cleCouleur] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) {
                $stmt->execute([$cleCouleur, strtolower($couleur)]);
            }
        }
        foreach (['employeur_fond_clair', 'employeur_fond_floute'] as $cleEffet) {
            $stmt->execute([$cleEffet, isset($_POST[$cleEffet]) ? '1' : '']);
        }
        // Une couleur par module, ou la couleur principale partout. Même forme
        // que les deux effets ci-dessus, mais sa valeur par défaut est « oui »
        // (param_couleurs_modules()) : une case décochée s'écrit donc en clair,
        // sinon l'absence de ligne vaudrait « oui » et le réglage ne pourrait
        // jamais être éteint.
        $stmt->execute(['employeur_couleurs_modules', isset($_POST['employeur_couleurs_modules']) ? '1' : '0']);
        // Thème : liste blanche, une valeur inattendue est ignorée plutôt
        // qu'écrite (elle finirait en attribut data-theme sur <html>).
        $theme = (string) ($_POST['employeur_theme'] ?? '');
        if (isset(THEMES[$theme])) {
            $stmt->execute(['employeur_theme', $theme]);
        }
        // Densité des lignes : même précaution, pour la même raison — la valeur
        // finit en attribut data-densite sur <html>.
        $densite = (string) ($_POST['employeur_densite'] ?? '');
        if (isset(DENSITES[$densite])) {
            $stmt->execute(['employeur_densite', $densite]);
        }
        if ($fond !== null) {
            $ancien = param('employeur_fond', '');
            $stmt->execute(['employeur_fond', $fond]);
            if ($ancien !== '' && str_starts_with($ancien, 'uploads/') && is_file(__DIR__ . '/../' . $ancien)) {
                @unlink(__DIR__ . '/../' . $ancien);
            }
        }
        // Fond : quatre décors calculés ou l'image personnalisée. Choisir
        // l'image sans en avoir envoyé une ne donnerait rien à voir — le choix
        // est refusé et dit pourquoi, plutôt que d'être accepté puis rattrapé
        // en silence par le repli de param_fond_decor().
        $decor = fond_decor_valide((string) ($_POST['employeur_fond_decor'] ?? ''));
        $imageDispo = $fond !== null || (string) param('employeur_fond', '') !== '';
        if ($decor === 'image' && !$imageDispo) {
            render('apparence', [
                'saved' => null,
                'err'   => "Envoyez d'abord une image de fond pour pouvoir la choisir.",
            ], 'Apparence');
            return;
        }
        $stmt->execute(['employeur_fond_decor', $decor]);
        redirect('apparence', ['ok' => 1]);
    }
    render('apparence', ['saved' => isset($_GET['ok']), 'err' => null], 'Apparence');
}

// Supprime l'image de fond personnalisée (bouton dédié, ?p=apparence) et
// revient à assets/fond.jpg — n'affecte ni les couleurs ni les effets
// clair/flouté, contrairement à route_apparence() (formulaire principal).
function route_apparence_fond_supprimer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $ancien = param('employeur_fond', '');
        $stmt = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        $stmt->execute(['employeur_fond', '']);
        // Le choix suit l'image : la laisser sur « image » afficherait le décor
        // de repli tout en prétendant, dans la page, qu'une image est choisie.
        if (fond_decor_valide((string) param('employeur_fond_decor', 'maillage')) === 'image') {
            $stmt->execute(['employeur_fond_decor', 'maillage']);
        }
        if ($ancien !== '' && str_starts_with($ancien, 'uploads/') && is_file(__DIR__ . '/../' . $ancien)) {
            @unlink(__DIR__ . '/../' . $ancien);
        }
    }
    redirect('apparence', ['ok' => 1]);
}

// Enregistre UNE boîte d'expédition du mailing (?p=emails_booking) : création
// si $id vaut 0, mise à jour sinon. Le mot de passe n'est écrasé que s'il est
// saisi — il n'est jamais réaffiché, donc jamais renvoyé par le navigateur, et
// un champ vide signifie « inchangé », pas « effacé ».
function emails_enregistrer_expediteur(int $id, array $ligne): void
{
    $champ = fn (string $k): string => trim((string) ($ligne[$k] ?? ''));
    $secure = in_array($champ('smtp_secure'), ['ssl', 'tls'], true) ? $champ('smtp_secure') : '';
    if ($id > 0) {
        db()->prepare('UPDATE mailing_expediteurs SET nom = ?, email = ?, smtp_host = ?, smtp_port = ?, smtp_secure = ?, smtp_user = ? WHERE id = ?')
            ->execute([$champ('nom'), $champ('email'), $champ('smtp_host'), $champ('smtp_port'), $secure, $champ('smtp_user'), $id]);
        if ($champ('smtp_pass') !== '') {
            db()->prepare('UPDATE mailing_expediteurs SET smtp_pass = ? WHERE id = ?')->execute([$champ('smtp_pass'), $id]);
        }
        return;
    }
    $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM mailing_expediteurs')->fetchColumn();
    db()->prepare('INSERT INTO mailing_expediteurs (nom, email, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, ordre)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$champ('nom'), $champ('email'), $champ('smtp_host'), $champ('smtp_port'), $secure,
                   $champ('smtp_user'), $champ('smtp_pass'), $ordre]);
}

// Envois généraux : les adresses et le serveur SMTP des fiches de salaire et des
// factures — les deux seuls envois qui les empruntent. Le mailing du booking a
// ses propres boîtes, sur ?p=emails_booking.
function route_emails(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = ['employeur_email_contact', 'employeur_email_expediteur',
                   'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user'];
        $emailContact = trim($_POST['employeur_email_contact'] ?? '');
        $emailExp     = trim($_POST['employeur_email_expediteur'] ?? '');
        // smtp_user n'est PAS validé comme une adresse : l'identifiant d'une
        // boîte SMTP n'en est pas toujours une (« u123456 » chez certains
        // hébergeurs), et le refuser rendait la configuration impossible.
        if (($emailContact !== '' && !filter_var($emailContact, FILTER_VALIDATE_EMAIL))
            || ($emailExp !== '' && !filter_var($emailExp, FILTER_VALIDATE_EMAIL))) {
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

// Envois pour le booking : les boîtes d'expédition des campagnes (chacune avec
// son propre SMTP) et le débit d'envoi. Écran séparé des envois généraux : rien
// n'y est partagé, ni les adresses, ni le serveur, ni le rythme.
function route_emails_booking(): void
{
    require_login();
    if (!module_actif('booking')) {
        redirect('emails');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'expediteur_save') {
            $ligne = (array) ($_POST['exp'] ?? []);
            $email = trim((string) ($ligne['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                render('emails_booking', [
                    'saved' => null,
                    'err' => 'Adresse d\'expéditeur invalide : « ' . $email . ' ».',
                    'expediteurs' => mailing_expediteurs(),
                ], 'E-mails — Booking');
                return;
            }
            emails_enregistrer_expediteur((int) ($_POST['id'] ?? 0), $ligne);
        } elseif ($section === 'expediteur_delete') {
            // Les modèles et campagnes qui la désignaient repassent à
            // l'expéditeur par défaut (ON DELETE SET NULL, migration_71) — une
            // campagne en file ne reste jamais sans boîte d'envoi.
            db()->prepare('DELETE FROM mailing_expediteurs WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        } elseif ($section === 'debit') {
            // Section nommée, et non « tout le reste » : un POST sans section
            // reconnue écrasait le délai et le plafond par leurs valeurs par
            // défaut, sans que personne ne l'ait demandé.
            $stmt = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
            $stmt->execute(['mailing_delai_secondes', (string) max(0, (int) ($_POST['mailing_delai_secondes'] ?? 10))]);
            $stmt->execute(['mailing_max_par_jour', (string) max(1, (int) ($_POST['mailing_max_par_jour'] ?? 200))]);
        }
        redirect('emails_booking', ['ok' => 1]);
    }
    render('emails_booking', [
        'saved' => isset($_GET['ok']),
        'err' => null,
        'expediteurs' => mailing_expediteurs(),
    ], 'E-mails — Booking');
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
    $anneesEvenements = module_actif('evenements')
        ? array_map('intval', db()->query("SELECT DISTINCT strftime('%Y', date) FROM evenements ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN))
        : [];
    render('export', [
        'annees' => array_map('intval', $annees), 'anneesCompta' => $anneesCompta, 'comptesCamt' => $comptesCamt,
        'anneesEvenements' => $anneesEvenements,
        'errCamt' => ($_GET['err'] ?? '') === 'camt_compte',
        'errSauvegarde' => ($_GET['err'] ?? '') === 'sauvegarde',
    ], 'Exporter les données');
}

// « Importer des données » : LA page d'import, partagée par tous les modules
// (fiches, factures, écritures, événements, structures). Rattachée à aucun
// module — comme ?p=structures et ?p=recherche, pour la même raison : elle
// n'appartient à aucun, et le filtrage se fait ligne par ligne dans la vue
// (module_actif() + peut_ecrire(), un type d'import par module).
// Elle n'affiche que le choix ; chaque geste d'import garde sa propre route
// verbale (<objet>_importer, N4 de docs/NOMMAGE.md), qui revient rendre cette
// même page avec ses résultats via rendre_import().
function route_import(): void
{
    require_login();
    rendre_import();
}

// Rend la page d'import avec le jeu COMPLET de variables attendues par la vue
// (render() ne fournit aucun défaut pour une clé absente, cf. extract()) :
// chaque route d'import ne remplit que les siennes, les autres restent vides.
function rendre_import(array $vars = []): void
{
    render('import', $vars + [
        'errFiches' => null, 'resultatsFiches' => null, 'resumeFiches' => null, 'simuleFiches' => true,
        'errFactures' => null, 'resultatsFactures' => null, 'resumeFactures' => null, 'simuleFactures' => true,
        'msgEcritures' => null,
        'errEvenements' => null, 'resultatsEvenements' => null, 'resumeEvenements' => null, 'simuleEvenements' => true,
        // Retour de ?p=sauvegarde_restaurer quand elle a refusé : la
        // restauration réussie, elle, ne revient jamais ici — elle déconnecte.
        'errRestaure' => (string) ($_GET['err_restaure'] ?? ''),
        'motifRestaure' => (string) ($_GET['motif'] ?? ''),
    ], 'Importer');
}


// -------------------------------------------------------------- SAUVEGARDE
// Restaurer une sauvegarde complète : l'opération la plus destructrice de
// l'application, et la seule qui remplace la base entière.
//
// Trois raisons pour qu'elle vive ici et non dans le formulaire unifié de
// ?p=import : elle n'appartient à aucun module mais à l'administration
// (écriture « coeur », comme la sauvegarde) ; elle REMPLACE au lieu d'ajouter,
// là où tous les autres imports fusionnent ; et elle n'a rien à simuler. Une
// entrée de plus dans le menu déroulant des types de données aurait rangé « je
// remplace tout » au milieu de « j'ajoute quelques lignes ».
//
// La session tombe à la fin : les comptes de la base restaurée ne sont pas
// ceux d'avant, et l'identifiant en session ne désigne plus forcément
// quelqu'un. Se retrouver devant l'écran de connexion est d'ailleurs le signal
// le plus clair que la base n'est plus la même.
function route_sauvegarde_restaurer(): void
{
    require_login();
    check_csrf();

    // Le champ du formulaire unifié de ?p=import, partagé par tous les types.
    $f = $_FILES['fichier'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        // UPLOAD_ERR_INI_SIZE / FORM_SIZE : le fichier dépasse ce que le serveur
        // accepte. C'est le cas le plus probable sur un hébergement mutualisé,
        // où une archive complète dépasse vite les quelques mégaoctets par
        // défaut — d'où un message qui le dit au lieu d'un « échec ».
        $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        $trop = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        redirect('import', ['err_restaure' => $trop ? 'taille' : 'fichier']);
    }

    try {
        $resultat = sauvegarde_restaurer($f['tmp_name']);
    } catch (Throwable $e) {
        error_log('Lasso — restauration refusée : ' . $e->getMessage());
        redirect('import', ['err_restaure' => 'refus', 'motif' => $e->getMessage()]);
    }

    // Le chemin de la base d'avant voyage par la session… qu'on détruit juste
    // après. Il part donc dans l'URL de l'écran de connexion, en nom de fichier
    // seul : c'est une information à recopier, pas un lien.
    $avant = $resultat['precedente'] ? basename($resultat['precedente']) : '';
    logout_session();
    redirect('connexion', ['restaure' => '1', 'fichiers' => (string) $resultat['fichiers'], 'avant' => $avant]);
}

function route_sauvegarde(): void
{
    require_login();
    // La base ET les fichiers déposés, dans une archive (lib/sauvegarde.php) :
    // la base ne mémorise que l'emplacement des logos, photos, icônes et pièces
    // jointes, jamais leur contenu.
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) param('employeur_nom')) ?? '', '_') ?: 'sauvegarde';
    try {
        [$tmp, $nom] = sauvegarde_construire($slug, maj_version_locale());
    } catch (Throwable $e) {
        // Disque plein, droits manquants, archive impossible à finaliser : mieux
        // vaut revenir sur la page d'export avec un message qu'un téléchargement
        // tronqué que rien ne distingue d'une sauvegarde valide.
        error_log('Lasso — sauvegarde impossible : ' . $e->getMessage());
        redirect('export', ['err' => 'sauvegarde']);
    }
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
// Applique une action du panneau « Organiser les cartes » puis revient au
// tableau de bord, panneau ouvert. Les cartes disponibles sont postées par le
// panneau lui-même (`dispo[]`) : c'est la vue qui sait lesquelles ont quelque
// chose à montrer, et elle seule — voir dashboard_ordre().
function route_resumes_reglages(): void
{
    $dispo = array_values(array_unique(array_filter(
        array_map('strval', (array) ($_POST['dispo'] ?? [])),
        fn (string $id) => $id !== ''
    )));
    if ($_POST['section'] === 'reinit') {
        preference_definir(DASHBOARD_PREFERENCE, '');
        redirect('tableau_bord', ['reglages' => 1]);
    }
    [$ordre, $cachees] = dashboard_disposition($dispo);

    // Dépôt du glisser-déposer : l'ordre COMPLET arrive d'un coup, comme pour le
    // déroulé d'un événement ou les lignes du décompte (docs/UI.md § 4). On ne
    // garde que les cartes connues, et celles qu'un ordre partiel aurait
    // oubliées reprennent la file — un identifiant inventé ne peut donc rien
    // faire, et rien ne disparaît.
    if ($_POST['section'] === 'ordre') {
        $recu = array_values(array_filter(
            array_map('trim', explode(',', (string) ($_POST['order'] ?? ''))),
            fn (string $id): bool => $id !== '' && in_array($id, $ordre, true)
        ));
        $ordre = array_merge(array_unique($recu), array_values(array_diff($ordre, $recu)));
        dashboard_disposition_definir($ordre, $cachees);
        // Même convention que les autres listes plates : en JSON quand le
        // script est là, pour que la carte se déplace sans recharger la page.
        if (($_POST['retour'] ?? '') === 'json') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            return;
        }
        redirect('tableau_bord', ['reglages' => 1]);
    }

    $carte = (string) ($_POST['carte'] ?? '');
    $i = array_search($carte, $ordre, true);
    if ($i === false) {
        redirect('tableau_bord', ['reglages' => 1]);
    }
    if ($_POST['section'] === 'visible') {
        $k = array_search($carte, $cachees, true);
        if ($k === false) {
            $cachees[] = $carte;
        } else {
            array_splice($cachees, $k, 1);
        }
    } elseif ($_POST['section'] === 'deplacer') {
        // Échange avec la voisine, comme partout ailleurs dans l'application.
        // Les bornes ne sont pas gardées ici : le panneau désactive déjà les
        // boutons du haut et du bas, et un indice hors liste ne ferait rien.
        $j = $i + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        if ($j >= 0 && $j < count($ordre)) {
            [$ordre[$i], $ordre[$j]] = [$ordre[$j], $ordre[$i]];
        }
    }
    dashboard_disposition_definir($ordre, $cachees);
    redirect('tableau_bord', ['reglages' => 1]);
}

function route_tableau_bord(): void
{
    require_login();
    // Organisation des cartes : un choix d'AFFICHAGE propre au compte, pas une
    // écriture sur un module. D'où le POST accepté ici, sur une route du cœur —
    // il ne touche qu'à utilisateur_preferences, et seulement pour son auteur.
    //
    // Un aller-retour par déplacement, comme le rangement des pays ou du plan
    // comptable (?p=pays, section=move) : même geste, même code
    // d'apparence, et rien à faire fonctionner en JavaScript.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        route_resumes_reglages();
    }
    $aujAnnee = (int) date('Y');
    $aujMois  = (int) date('n');
    // module_accessible() et non module_actif() : le tableau de bord fait partie
    // du cœur, donc s'ouvre pour tout compte connecté — ses widgets doivent donc
    // vérifier le droit de LECTURE de chaque module, sinon un compte limité à un
    // seul module y verrait les données de tous les autres. Le rail de
    // navigation posait déjà les deux conditions ; cette page ne posait que la
    // première. Les conditions de views/tableau_bord.php ont été alignées.
    $aPayer = [];
    // Une fiche du mois M se verse pendant M+1 : elle est « à faire » tout ce
    // mois-là, et « en retard » seulement à partir de M+2. Une fiche du mois
    // courant n'est donc encore ni l'un ni l'autre — elle figure dans la liste
    // (elle reste à verser) mais n'appelle aucun geste aujourd'hui. L'état
    // voyage AVEC la fiche : c'est lui qui décide, dans la carte, quelles
    // lignes se détachent sur fond ambre.
    if (module_accessible('salaires')) {
        // avatar_* : la pastille d'identité posée devant le nom, comme dans la
        // liste des fiches. Elle vit sur l'EMPLOYÉ, pas sur la fiche — qui fige
        // ses montants, pas l'apparence de la personne. Jointure externe : une
        // fiche dont l'employé a disparu reste à verser, et doit rester visible.
        $sqlAPayer = "SELECT f.*, e.avatar_couleur, e.avatar_photo
                      FROM fiches f LEFT JOIN employes e ON e.id = f.employe_id
                      WHERE trim(f.date_paiement) = '' ORDER BY f.annee, f.mois";
        foreach (db()->query($sqlAPayer) as $f) {
            $moisEcoules = ($aujAnnee - (int) $f['annee']) * 12 + ($aujMois - (int) $f['mois']);
            if ($moisEcoules < 0) {
                continue; // fiche d'un mois à venir
            }
            $f['echeance_etat'] = match (true) {
                $moisEcoules === 1 => 'afaire',
                $moisEcoules >= 2  => 'retard',
                default            => '',
            };
            $aPayer[] = $f;
        }
    }
    $facturesEmises = module_accessible('facturation')
        ? db()->query(
            "SELECT f.*, d.nom AS structure_nom FROM factures f JOIN structures d ON d.id = f.structure_id
             WHERE f.statut = 'emise' ORDER BY f.date_echeance"
        )->fetchAll()
        : [];
    $comptaSeries = module_accessible('compta') ? compta_dashboard_series() : [];
    $piecesDash = pieces_dashboard(5);
    $prochainsEvenements = module_accessible('evenements') ? evenements_a_venir(5) : [];
    $suisaAFaire   = module_accessible('evenements') ? nb_evenements_suisa_a_faire() : 0;
    $suisaEnvoye   = module_accessible('evenements') ? nb_evenements_suisa_envoyes() : 0;
    $suisaManquant = module_accessible('evenements') ? nb_evenements_suisa_manquants() : 0;
    // Widget « Suivi du booking » : étiquettes disponibles, celle que ce compte
    // suivait la dernière fois (à défaut la première de la liste), et la
    // répartition de ses structures par ancienneté de contact.
    // Campagnes du tableau de bord : celles qui demandent du travail d'abord.
    // Le décompte des campagnes à venir porte sur TOUTES les campagnes, pas
    // seulement sur les neuf que la carte montre.
    $campagnesToutes = module_accessible('booking') ? campagnes_liste() : [];
    // Recherche de fonds : les campagnes dont la saison court, et les bilans
    // dus — la seconde échéance, celle qu'on oublie une fois l'argent reçu.
    $fondsDash = module_accessible('fonds') ? fonds_dashboard(5) : ['campagnes' => [], 'nbCampagnes' => 0, 'bilans' => [], 'nbBilans' => 0];
    // Les pastilles des projets financés, ajoutées ici et non dans
    // fonds_dashboard() : elles viennent du module Événements, dont les règles
    // de la recherche de fonds n'ont pas à dépendre
    // (fonds_campagnes_avec_projets(), lib/routes_fonds.php).
    $fondsDash['campagnes'] = fonds_campagnes_avec_projets($fondsDash['campagnes']);
    $campagnesDash = $campagnesToutes ? campagnes_dashboard(9, $campagnesToutes) : [];
    $campagnesAVenir = campagnes_dashboard_a_venir($campagnesToutes);
    render('tableau_bord', [
        'aPayer' => $aPayer,
        'facturesEmises' => $facturesEmises, 'comptaSeries' => $comptaSeries,
        'piecesDash' => $piecesDash,
        'prochainsEvenements' => $prochainsEvenements, 'suisaAFaire' => $suisaAFaire, 'suisaEnvoye' => $suisaEnvoye, 'suisaManquant' => $suisaManquant,
        'campagnesDash' => $campagnesDash, 'campagnesAVenir' => $campagnesAVenir,
        'fondsDash' => $fondsDash,
    ], 'Tableau de bord');
}


// ------------------------------------------------------- RECHERCHE UNIFIÉE
// Route du cœur : accessible à tout compte connecté, sans rattachement à un
// module. Ce n'est pas un relâchement — c'est recherche_globale() qui filtre
// source par source sur module_actif() ET peut_lire(), parce qu'une même page
// couvre plusieurs modules aux droits distincts (voir lib/recherche.php).
function route_recherche(): void
{
    require_login();
    $q = trim((string) ($_GET['q'] ?? ''));
    $groupes = $q !== '' ? recherche_globale($q) : [];
    render('recherche', [
        'q'       => $q,
        'groupes' => $groupes,
        'total'   => recherche_total($groupes),
        'tropCourt' => $q !== '' && mb_strlen($q) < RECHERCHE_MIN,
    ], $q !== '' ? "Recherche : $q" : 'Recherche');
}
