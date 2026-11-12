<?php
// Journal d'activité : QUI a fait QUOI, et QUAND, dans toute l'application.
//
// Une ligne par requête POST d'un utilisateur connecté — c'est-à-dire par
// mutation, puisque toute mutation passe par un POST (CLAUDE.md, contrôle des
// droits). Le relevé est posé UNE FOIS, par le dispatcher (index.php), et non
// route par route : la table `historique` du carnet d'adresses montrait ce que
// donne la seconde méthode — elle couvrait les structures et les lieux, et
// rien d'autre. Une route ajoutée demain est journalisée sans qu'on y pense.
//
// ⚠️ On ne garde JAMAIS le contenu du formulaire : il porte des mots de passe
// (comptes, boîtes d'envoi), des salaires, des numéros AVS. Seulement la route,
// la cible (un identifiant) et le sous-geste (section/action, des valeurs
// d'une liste fermée), plus l'issue lue sur la réponse.
//
// Ce que le journal ne sait pas dire : le détail d'une modification (avant →
// après). Pour une fiche, l'historique figé y pourvoit ; pour le reste, la
// sauvegarde. Le journal répond à « qui a touché à ça, et quand ».

// horodatage_maintenant(), horodatage_ts() : ce fichier sert aussi sans
// helpers.php (scripts, tests, relevé d'erreur précoce).
require_once __DIR__ . '/horodatage.php';

require_once __DIR__ . '/db.php';

// Paramètres de POST sûrs à garder : des identifiants et des sous-gestes,
// jamais une saisie libre.
const JOURNAL_CIBLES = ['id', 'fiche_id', 'employe_id', 'personne_id', 'structure_id', 'facture_id',
    'evenement_id', 'ecriture_id', 'piece_id', 'projet_id', 'campagne_id', 'utilisateur_id', 'annee'];
const JOURNAL_GESTES = ['section', 'action', 'mode'];

// Durée de conservation : au-delà, les lignes sont purgées (un coup sur cent,
// à l'écriture). Cinq ans couvrent un contrôle de la caisse de compensation.
const JOURNAL_CONSERVATION_JOURS = 1825;

// Ce qu'on retient de la requête : [cible_type, cible_id, geste]. Pur, testable.
function journal_extraire(array $post): array
{
    $cibleType = '';
    $cibleId = null;
    foreach (JOURNAL_CIBLES as $cle) {
        $v = $post[$cle] ?? null;
        if (is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0) {
            [$cibleType, $cibleId] = [$cle, (int) $v];
            break;
        }
    }
    $geste = '';
    foreach (JOURNAL_GESTES as $cle) {
        $v = $post[$cle] ?? null;
        // Un sous-geste est un mot-clé (« lettrer », « rouvrir ») ; tout ce qui
        // ressemble à une saisie est écarté plutôt que tronqué.
        if (is_string($v) && preg_match('/^[a-z0-9_]{1,40}$/', $v)) {
            $geste = $v;
            break;
        }
    }
    return [$cibleType, $cibleId, $geste];
}

// L'issue, lue sur la réponse déjà envoyée : une redirection qui porte une
// erreur, un code ≥ 400 ou un message de refus posé en session → « refus ».
function journal_issue(array $entetes, int $code, bool $flashErreur): string
{
    if ($code >= 400 || $flashErreur) {
        return 'refus';
    }
    foreach ($entetes as $h) {
        if (stripos($h, 'Location:') === 0 && preg_match('/[?&](err|refuse)=/', $h)) {
            return 'refus';
        }
    }
    return 'ok';
}

function journal_ecrire(string $route, ?array $utilisateur, array $post, string $issue): void
{
    if ($utilisateur === null) {
        return;
    }
    [$cibleType, $cibleId, $geste] = journal_extraire($post);
    $nom = function_exists('utilisateur_nom') ? utilisateur_nom($utilisateur) : (string) ($utilisateur['email'] ?? '');
    // Horodaté par PHP, en UTC comme toute la base (horodatage_maintenant()) :
    // une base créée avant ce choix garde pour défaut datetime('now',
    // 'localtime'), qui suit le fuseau du SYSTÈME de l'hébergeur.
    db()->prepare('INSERT INTO journal_activite (utilisateur_id, utilisateur, route, geste, cible_type, cible_id, issue, cree_le)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $utilisateur['id'], $nom, substr($route, 0, 80), $geste, $cibleType, $cibleId, $issue, horodatage_maintenant()]);
    if (random_int(1, 100) === 1) {
        db()->prepare("DELETE FROM journal_activite WHERE cree_le < datetime('now', ?)")
            ->execute(['-' . JOURNAL_CONSERVATION_JOURS . ' days']);
    }
}

// Relevé de fin de requête, armé par index.php pour un POST. Une panne du
// journal ne doit jamais casser la requête qu'il observe : elle est
// journalisée côté serveur, et c'est tout.
function journal_armer(string $route): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $post = $_POST;
    register_shutdown_function(function () use ($route, $post) {
        try {
            $u = function_exists('current_user') ? current_user() : null;
            $issue = journal_issue(headers_list(), (int) http_response_code(), !empty($_SESSION['flash_err']));
            journal_ecrire($route, $u, $post, $issue);
        } catch (Throwable $e) {
            error_log('[journal] ' . $e->getMessage());
        }
    });
}

// Libellé lisible d'une route : « fiche_supprimer » → « Fiche — supprimer ».
// Les routes suivent la convention objet_verbe de docs/NOMMAGE.md ; une route
// sans verbe (« employe_form », « compta_ecritures ») est un écran qui
// enregistre ce qu'il affiche.
function journal_libelle(string $route, string $geste): string
{
    $verbes = ['supprimer', 'enregistrer', 'creer', 'ajouter', 'modifier', 'importer', 'exporter',
        'envoyer', 'lier', 'delier', 'restaurer', 'fusionner', 'recalculer', 'rapprocher',
        'cloturer', 'payee', 'paiement', 'valider', 'dupliquer', 'reordonner', 'archiver', 'acquitter'];
    $parts = explode('_', $route);
    $verbe = in_array(end($parts), $verbes, true) ? array_pop($parts) : '';
    $objet = ucfirst(str_replace('_', ' ', implode('_', $parts)));
    $accents = ['creer' => 'créer', 'delier' => 'délier', 'cloturer' => 'clôturer', 'payee' => 'payée',
        'reordonner' => 'réordonner', 'recalculer' => 'recalculer'];
    $libelle = $objet . ($verbe !== '' ? ' — ' . ($accents[$verbe] ?? $verbe) : ' — enregistrer');
    return $geste !== '' ? $libelle . ' (' . str_replace('_', ' ', $geste) . ')' : $libelle;
}

// ------------------------------------------------------------------- ERREURS
// En production les erreurs ne s'affichent pas (index.php) : elles partaient
// dans le journal d'erreurs de PHP, que personne ne lit sur un hébergement
// mutualisé. Une page cassée depuis trois semaines ne se voyait qu'au jour où
// quelqu'un s'en plaignait. Elles sont donc AUSSI relevées en base, montrées
// sur ?p=journal et signalées aux administrateurs sur le tableau de bord.
//
// Ce qu'on garde : le niveau, le message, le fichier et la ligne, la route et
// le compte. ⚠️ Jamais la pile d'appels : ses arguments portent les valeurs
// traitées (un salaire, un mot de passe passé à une fonction). Le message est
// tronqué ; une requête préparée n'y met jamais les valeurs.
//
// La même erreur (même fichier, même ligne, même message aux nombres près)
// reste UNE ligne dont le compteur monte : une boucle qui avertit mille fois
// ne noie pas le journal. Une erreur qui revient après avoir été marquée vue
// redevient nouvelle.

const JOURNAL_ERREURS_CONSERVATION_JOURS = 365;

function journal_erreur_niveau(int $type): string
{
    return match ($type) {
        E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'Avertissement',
        E_NOTICE, E_USER_NOTICE => 'Notice',
        E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'Erreur fatale',
        default => 'Erreur',
    };
}

// Pur, testable : l'empreinte qui regroupe les répétitions d'une même erreur.
function journal_erreur_empreinte(string $niveau, string $message, string $fichier, int $ligne): string
{
    return sha1($niveau . '|' . $fichier . '|' . $ligne . '|' . preg_replace('/\d+/', '#', $message));
}

// N'échoue JAMAIS : appelée depuis les gestionnaires d'erreurs, une exception
// ici masquerait l'erreur d'origine. Une panne de la base (qui peut être
// l'erreur elle-même) finit dans le journal de PHP, comme avant.
function journal_erreur(string $niveau, string $message, string $fichier, int $ligne): void
{
    static $enCours = false;
    static $dejaVues = [];
    if ($enCours) {
        return;
    }
    $enCours = true;
    try {
        $racine = dirname(__DIR__) . '/';
        $fichier = str_starts_with($fichier, $racine) ? substr($fichier, strlen($racine)) : basename($fichier);
        $message = mb_substr(str_replace($racine, '', $message), 0, 500);
        $empreinte = journal_erreur_empreinte($niveau, $message, $fichier, $ligne);
        // Une fois par requête : une boucle de mille avertissements compte un.
        if (isset($dejaVues[$empreinte])) {
            return;
        }
        $dejaVues[$empreinte] = true;
        $route = preg_match('/^[a-z0-9_]{1,60}$/', (string) ($_GET['p'] ?? '')) ? (string) $_GET['p'] : '';
        $route = PHP_SAPI === 'cli' ? 'cli' : (($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($route ?: '—'));
        $qui = '';
        if (session_status() === PHP_SESSION_ACTIVE && function_exists('current_user')) {
            $u = current_user();
            $qui = $u ? (function_exists('utilisateur_nom') ? utilisateur_nom($u) : (string) ($u['email'] ?? '')) : '';
        }
        // En UTC, écrit par PHP : même raison que journal_activite ci-dessus.
        $maintenant = horodatage_maintenant();
        db()->prepare("INSERT INTO journal_erreurs (empreinte, niveau, message, fichier, ligne, route, utilisateur, premier_le, dernier_le)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(empreinte) DO UPDATE SET
                           occurrences = occurrences + 1, dernier_le = excluded.dernier_le,
                           route = excluded.route, utilisateur = excluded.utilisateur, vue = 0")
            ->execute([$empreinte, $niveau, $message, $fichier, $ligne, $route, $qui, $maintenant, $maintenant]);
        if (random_int(1, 100) === 1) {
            db()->prepare("DELETE FROM journal_erreurs WHERE dernier_le < datetime('now', ?)")
                ->execute(['-' . JOURNAL_ERREURS_CONSERVATION_JOURS . ' days']);
        }
    } catch (Throwable $e) {
        error_log('[journal] erreur non relevée : ' . $e->getMessage());
    } finally {
        $enCours = false;
    }
}

// Armé une fois par index.php. Les avertissements et notices passent par le
// gestionnaire d'erreurs, qui rend la main à PHP (affichage en dev, journal de
// PHP partout) ; les erreurs fatales, qu'aucun gestionnaire n'attrape, sont
// relues en fin de requête. Les exceptions non attrapées sont relevées par le
// gestionnaire d'exceptions d'index.php. Une erreur masquée par « @ » et les
// avis d'obsolescence (une montée de version de PHP, pas une panne) sont
// laissés de côté.
function journal_erreurs_armer(): void
{
    set_error_handler(function (int $type, string $message, string $fichier, int $ligne): bool {
        if ((error_reporting() & $type) && !($type & (E_DEPRECATED | E_USER_DEPRECATED))) {
            journal_erreur(journal_erreur_niveau($type), $message, $fichier, $ligne);
        }
        return false;
    });
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            journal_erreur('Erreur fatale', (string) $e['message'], (string) $e['file'], (int) $e['line']);
        }
    });
}

function journal_exception(Throwable $e): void
{
    journal_erreur('Exception', get_class($e) . ' : ' . $e->getMessage(), $e->getFile(), $e->getLine());
}

// Pour le tableau de bord : les erreurs qu'aucun administrateur n'a encore vues.
function journal_erreurs_nouvelles(): int
{
    try {
        return (int) db()->query('SELECT COUNT(*) FROM journal_erreurs WHERE vue = 0')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
