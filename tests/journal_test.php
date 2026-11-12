<?php
// Journal d'activité et suppressions gardées par le serveur.
// Lancement : php tests/journal_test.php
//
// Deux promesses à tenir : le journal ne garde JAMAIS une saisie (mots de
// passe, salaires, AVS passent par les mêmes POST que les identifiants qu'il
// relève), et une suppression interdite l'est dans le code, pas seulement par
// l'absence d'un bouton — la fiche payée en était le contre-exemple.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_journal_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/personnes.php';
require_once __DIR__ . '/../lib/journal.php';
require_once __DIR__ . '/../lib/routes_salaires.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        printf("  ok    %s\n", $label);
        return;
    }
    $fails++;
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

$pdo = db();

echo "1) Ce que le journal retient d'un POST\n";
check('la cible et le sous-geste', ['fiche_id', 12, 'lettrer'], journal_extraire(['csrf' => 'x', 'fiche_id' => '12', 'section' => 'lettrer']));
check('« id » passe avant les autres', ['id', 3, ''], journal_extraire(['personne_id' => '9', 'id' => '3']));
check('une saisie libre n\'est pas un geste', ['', null, ''], journal_extraire(['section' => 'Bonjour à tous', 'id' => 'abc']));
$post = ['mot_de_passe' => 'secret123', 'smtp_pass' => 'pw', 'numero_avs' => '756.1234.5678.97', 'salaire' => '5000', 'id' => '4'];
$u = ['id' => 1, 'email' => 'test@example.test'];
$pdo->exec("INSERT INTO utilisateurs (id, email, mot_de_passe) VALUES (1, 'test@example.test', 'x')");
journal_ecrire('utilisateur_enregistrer', $u, $post, 'ok');
$ligne = $pdo->query('SELECT * FROM journal_activite ORDER BY id DESC LIMIT 1')->fetch();
$dump = implode('|', array_map('strval', $ligne));
check('aucune saisie dans la ligne écrite', false, (bool) preg_match('/secret123|756\.1234|5000|\bpw\b/', $dump));
check('… mais la route, la cible et l\'auteur', ['utilisateur_enregistrer', 'id', 4, 'test@example.test'],
    [$ligne['route'], $ligne['cible_type'], (int) $ligne['cible_id'], $ligne['utilisateur']]);
journal_ecrire('fiche_supprimer', null, ['id' => '1'], 'ok');
check('sans compte connecté, rien n\'est écrit', 1, (int) $pdo->query('SELECT COUNT(*) FROM journal_activite')->fetchColumn());

echo "\n2) L'issue se lit sur la réponse\n";
check('redirection simple → fait', 'ok', journal_issue(['Location: ?p=fiches'], 302, false));
check('redirection avec err= → refus', 'refus', journal_issue(['Location: ?p=fiche&id=3&err=payee'], 302, false));
check('droits refusés → refus', 'refus', journal_issue(['Location: ?p=tableau_bord&refuse=1'], 302, false));
check('code 403 → refus', 'refus', journal_issue([], 403, false));
check('message de refus en session → refus', 'refus', journal_issue(['Location: ?p=compta_ecritures'], 303, true));

echo "\n3) Libellés\n";
check('objet — verbe', 'Fiche — supprimer', journal_libelle('fiche_supprimer', ''));
check('écran qui enregistre', 'Employe form — enregistrer', journal_libelle('employe_form', ''));
check('avec sous-geste', 'Compta exercice — clôturer (rouvrir)', journal_libelle('compta_exercice_cloturer', 'rouvrir'));

echo "\n4) Une fiche payée ne se supprime pas, même par un POST direct\n";
$pdo->exec("INSERT INTO personnes (id, prenom, nom) VALUES (1, 'Test', 'Employé')");
$pdo->exec("INSERT INTO employes (id, personne_id) VALUES (1, 1)");
// Toutes les colonnes obligatoires d'une fiche, à zéro : le contenu importe peu.
$cols = ['employe_id' => 1, 'annee' => 2026, 'mois' => 1];
foreach ($pdo->query('PRAGMA table_info(fiches)') as $c) {
    if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && !isset($cols[$c['name']]) && $c['name'] !== 'id') {
        $cols[$c['name']] = str_contains(strtolower((string) $c['type']), 'text') ? '' : 0;
    }
}
$insFiche = function (array $extra) use ($pdo, $cols): int {
    $v = $extra + $cols;
    $pdo->prepare('INSERT INTO fiches (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')
        ->execute(array_values($v));
    return (int) $pdo->lastInsertId();
};
$payee = $insFiche(['date_paiement' => '2026-01-25']);
$brouillon = $insFiche(['mois' => 2]);
check('la fiche payée est refusée', false, fiche_supprimer($payee));
check('… et toujours là', 1, (int) $pdo->query("SELECT COUNT(*) FROM fiches WHERE id = $payee")->fetchColumn());
check('la fiche non payée part', true, fiche_supprimer($brouillon));
check('… et n\'est plus là', 0, (int) $pdo->query("SELECT COUNT(*) FROM fiches WHERE id = $brouillon")->fetchColumn());
check('une fiche absente ne compte pas comme un refus', true, fiche_supprimer(99999));

echo "\n5) Toute route de suppression garde sa règle dans le code\n";
// Les suppressions refusées dans certains cas, et la condition qui le dit.
// Si l'une de ces lignes disparaît, la règle ne tient plus que par l'interface.
$gardes = [
    'lib/routes_salaires.php'    => ["date_paiement, '')) = ''", 'supprimer_si_non_reference(\'employes\''],
    'lib/routes_facturation.php' => ["statut = 'brouillon'", 'supprimer_si_non_reference(\'structures\''],
    'lib/routes_evenements.php'  => ['supprimer_si_non_reference(\'projets\''],
    'lib/routes.php'             => ["'last_admin'", "'self'"],
];
foreach ($gardes as $f => $motifs) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $f);
    foreach ($motifs as $m) {
        check("$f garde « $m »", true, str_contains($src, $m));
    }
}

echo "\n5) Les erreurs du serveur sont relevées en base\n";
$pdo->exec('DELETE FROM journal_erreurs');
journal_erreurs_armer();
$avant = ini_get('display_errors');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
for ($i = 0; $i < 3; $i++) {
    trigger_error("Valeur $i introuvable", E_USER_WARNING);   // une boucle : UNE occurrence
}
@trigger_error('masquée par @', E_USER_WARNING);
trigger_error('obsolète', E_USER_DEPRECATED);
journal_exception(new RuntimeException('panne ' . __DIR__ . '/../lib/x.php'));
restore_error_handler();
ini_set('display_errors', (string) $avant);
$errs = $pdo->query('SELECT niveau, message, fichier, occurrences, vue FROM journal_erreurs ORDER BY id')->fetchAll();
check('deux erreurs distinctes (avertissement + exception)', 2, count($errs));
check('l\'avertissement répété dans une requête compte une fois', 1, (int) ($errs[0]['occurrences'] ?? 0));
check('son niveau', 'Avertissement', $errs[0]['niveau'] ?? null);
check('le fichier, relatif à la racine', 'tests/journal_test.php', $errs[0]['fichier'] ?? null);
check('« @ » et les avis d\'obsolescence sont ignorés', false,
    (bool) preg_grep('/masquée|obsolète/', array_column($errs, 'message')));
check('le chemin du serveur ne fuit pas dans le message', false, str_contains((string) ($errs[1]['message'] ?? ''), dirname(__DIR__)));
check('nouvelle tant qu\'aucun administrateur ne l\'a vue', 2, journal_erreurs_nouvelles());
check('la même erreur, nombres mis à part, a la même empreinte', true,
    journal_erreur_empreinte('Notice', 'ligne 12 absente', 'a.php', 3) === journal_erreur_empreinte('Notice', 'ligne 99 absente', 'a.php', 3));
$pdo->exec('UPDATE journal_erreurs SET vue = 1');
// Une autre requête : un autre processus (le regroupement par requête garde
// sa mémoire le temps du processus).
$ligne = (int) $pdo->query("SELECT ligne FROM journal_erreurs WHERE niveau = 'Avertissement'")->fetchColumn();
$code = 'define("APP_DB_PATH", ' . var_export($tmp, true) . '); require ' . var_export(dirname(__DIR__) . '/lib/config.php', true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/lib/journal.php', true) . ';'
    . 'journal_erreur("Avertissement", "Valeur 7 introuvable", ' . var_export(__FILE__, true) . ', ' . $ligne . ');';
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
check('revenue après avoir été vue, elle redevient nouvelle', 1, journal_erreurs_nouvelles());
check('… et son compteur monte', 2, (int) $pdo->query("SELECT occurrences FROM journal_erreurs WHERE niveau = 'Avertissement'")->fetchColumn());
$idx = (string) file_get_contents(__DIR__ . '/../index.php');
check('index.php arme le relevé', true, str_contains($idx, 'journal_erreurs_armer();'));
check('le gestionnaire d\'exceptions relève', true, str_contains($idx, 'journal_exception($e);'));
check('la pile d\'appels n\'est jamais gardée', false,
    (bool) preg_match('/getTrace|debug_backtrace/', (string) file_get_contents(__DIR__ . '/../lib/journal.php')));

@unlink($tmp);
foreach (glob($tmp . '-*') ?: [] as $f) {
    @unlink($f);
}
echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
