<?php
// Tests du générateur de base de démonstration (scripts/base_demo.php).
// Lancement : php tests/base_demo_test.php
//
// Ce que ces tests protègent, et pourquoi ils valent leur seconde d'exécution :
// le générateur est le SEUL endroit du projet qui écrit dans presque toutes les
// tables. Il rompt donc au premier changement de schéma — une colonne devenue
// NOT NULL, une contrainte d'unicité ajoutée —, et il rompt en silence, parce
// que personne ne le lance avant d'en avoir besoin. Le vérifier ici, c'est le
// faire tomber en CI le jour du changement et non le jour de la démonstration.
//
// Le script tourne dans son PROPRE processus : il pose APP_DB_PATH, qui est une
// constante, et il faut pouvoir le lancer plusieurs fois.
//
// --sans-scan : sans lui, le générateur déposerait des images dans
// data/fichiers — un test ne laisse rien derrière lui.

declare(strict_types=1);

$racine = dirname(__DIR__);
$tmp    = sys_get_temp_dir() . '/lasso_demo_' . bin2hex(random_bytes(6)) . '.sqlite';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        return;
    }
    $fails++;
    echo "ÉCHEC : $label\n  attendu : " . var_export($attendu, true)
       . "\n  obtenu  : " . var_export($obtenu, true) . "\n";
}
function checkVrai(string $label, bool $ok): void
{
    check($label, true, $ok);
}

$lancer = function (array $arguments) use ($racine): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($racine . '/scripts/base_demo.php');
    foreach ($arguments as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd . ' 2>&1', $sortie, $code);
    return [$code, implode("\n", $sortie)];
};

// --- 1. Les garde-fous : on ne doit pas pouvoir toucher la vraie base --------
[$code, $sortie] = $lancer([sys_get_temp_dir() . '/database.sqlite']);
check('refuse le nom database.sqlite', 1, $code);
checkVrai('et dit pourquoi', str_contains($sortie, 'database.sqlite'));
checkVrai('sans rien créer', !file_exists(sys_get_temp_dir() . '/database.sqlite'));

// --- 2. Génération ----------------------------------------------------------
[$code, $sortie] = $lancer([$tmp, '--sans-scan']);
check('le générateur se termine sans erreur', 0, $code);
checkVrai('le fichier existe', is_file($tmp));
checkVrai('aucune alerte PHP dans la sortie',
    !preg_match('/\b(Warning|Notice|Fatal error|Uncaught)\b/', $sortie));

// --- 3. Refuse d'écraser sans qu'on le demande ------------------------------
[$code, $sortie] = $lancer([$tmp, '--sans-scan']);
check('refuse d\'écraser un fichier existant', 1, $code);
[$code] = $lancer([$tmp, '--sans-scan', '--ecraser']);
check('--ecraser lève le refus', 0, $code);

// --- 4. La base produite se tient debout ------------------------------------
$pdo = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
check('aucune clé étrangère cassée', 0, count($pdo->query('PRAGMA foreign_key_check')->fetchAll()));

$n = fn (string $t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
// Chaque module doit avoir de quoi s'afficher : une base de démonstration à
// moitié vide ne montre pas les écrans qu'on veut montrer.
foreach (['personnes', 'structures', 'personne_structures', 'employes', 'fiches', 'fiche_postes',
          'ecritures', 'pieces', 'factures', 'facture_lignes', 'evenements', 'evenement_feuille',
          'campagnes', 'campagne_structures', 'fonds_campagnes', 'fonds_demandes',
          'mailing_modeles', 'projets', 'axes_analytiques', 'utilisateurs',
          'utilisateur_permissions', 'poste_taux'] as $table) {
    checkVrai("la table $table est remplie", $n($table) > 0);
}

// --- 5. Ce que les écrans attendent -----------------------------------------
// Les trois états d'une pièce (SPEC_PIECES.md) : la démonstration les porte
// tous, sinon deux colonnes de l'écran des pièces restent vides à jamais.
require_once $racine . '/lib/pieces.php';
$etats = [];
foreach ($pdo->query('SELECT * FROM pieces') as $p) {
    $etats[piece_etat($p)] = true;
}
check('les trois états de pièce sont représentés', ['a_payer', 'a_rapprocher', 'classee'],
    array_values(array_filter(['a_payer', 'a_rapprocher', 'classee'], fn ($e) => isset($etats[$e]))));

// Les cinq types de pièce.
$types = $pdo->query('SELECT DISTINCT type FROM pieces ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);
$attendus = array_keys(PIECE_TYPES);
sort($attendus);
check('les cinq types de pièce sont représentés', $attendus, $types);

// Un administrateur, sans quoi la base est inutilisable.
$admins = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur_permissions
                             WHERE module = "coeur" AND niveau = "ecriture"')->fetchColumn();
checkVrai('au moins un administrateur', $admins >= 1);

// Les fiches figent leurs lignes : une fiche sans copie figée ne s'imprime pas.
$orphelines = (int) $pdo->query('SELECT COUNT(*) FROM fiches f
                                 WHERE NOT EXISTS (SELECT 1 FROM fiche_postes fp WHERE fp.fiche_id = f.id)')
    ->fetchColumn();
check('aucune fiche sans ses lignes figées', 0, $orphelines);

// Rien de réel ne doit traîner : toutes les adresses sont en .test (RFC 2606),
// qui ne peut pas recevoir de message.
$horsTest = (int) $pdo->query('SELECT COUNT(*) FROM personnes WHERE email <> "" AND email NOT LIKE "%.test"')
    ->fetchColumn();
check('toutes les adresses des personnes sont en .test', 0, $horsTest);
$horsTest2 = (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs WHERE email NOT LIKE "%.test"')->fetchColumn();
check('toutes les adresses des comptes sont en .test', 0, $horsTest2);

// --- 6. --sans-scan ne dépose rien ------------------------------------------
check('aucun justificatif enregistré avec --sans-scan', 0,
    (int) $pdo->query('SELECT COUNT(*) FROM pieces WHERE fichier IS NOT NULL AND fichier <> ""')->fetchColumn());

$pdo = null;
foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
    if (file_exists($f)) {
        unlink($f);
    }
}

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
