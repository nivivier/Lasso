<?php
// Test de l'export SUISA (?p=evenements_export_suisa) : les coordonnées de
// l'organisateur doivent y figurer. Lancement : php tests/export_suisa_test.php
//
// Base TEMPORAIRE, comme tests/migrations_test.php.
//
// Ce que ce test protège, deuxième couche : les coordonnées de la personne à
// contacter vivent dans les CONTACTS de la structure (structure_contacts), pas
// dans les champs de la structure — l'export sortait donc e-mail, téléphone et
// personne de contact vides même quand ils étaient renseignés. On prend le
// contact coché « administration », sinon le premier contact actif.
//
// Ce que ce test protège : l'export lisait l'organisateur dans le miroir
// evenements.organisateur_structure_id, que evenement_resynchroniser_miroirs()
// ne remplit QUE depuis la structure marquée « à facturer ». Ce marquage étant
// facultatif, un événement dont l'organisateur était renseigné mais non coché
// sortait avec toutes ses colonnes d'organisateur vides — adresse, téléphone,
// e-mail et personne de contact comprises. C'est le cas nº 2 ci-dessous.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_suisa_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/recherche.php';
require_once __DIR__ . '/../lib/evenements.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/routes.php';
require_once __DIR__ . '/../lib/routes_evenements.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu !== $obtenu) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}
register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

db();
db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)')
    ->execute(['t@example.test', 'x']);
$_SESSION = ['uid' => 1, 'last_activity' => time(), 'login_time' => time()];

// Deux structures identiques dans leur contenu : seul leur rattachement diffère.
$insS = db()->prepare('INSERT INTO structures (nom, adresse_rue, adresse_npa, adresse_localite,
                       adresse_pays, email, telephone, personne_contact) VALUES (?,?,?,?,?,?,?,?)');
$insS->execute(['Salle des Fêtes', 'Rue du Test 3', '1200', 'Genève', 'Suisse',
                'contact@salle.test', '+41 22 000 00 00', 'Camille Dupuis']);
$avecFacturation = (int) db()->lastInsertId();
$insS->execute(['Association Sans Facture', 'Chemin Neuf 7', '1400', 'Yverdon', 'Suisse',
                'info@asso.test', '+41 24 111 11 11', 'Dominique Favre']);
$sansFacturation = (int) db()->lastInsertId();

// Contacts : la structure « à facturer » en a deux, dont un coché
// « administration » (le second) ; l'autre structure n'en a qu'un, non coché.
$insC = db()->prepare('INSERT INTO structure_contacts (structure_id, prenom, nom, email, telephone, est_administration, actif)
                       VALUES (?, ?, ?, ?, ?, ?, ?)');
$insC->execute([$avecFacturation, 'Alex', 'Premier', 'alex@salle.test', '+41 22 111 22 33', 0, 1]);
$insC->execute([$avecFacturation, 'Bruno', 'Administration', 'admin@salle.test', '+41 22 999 88 77', 1, 1]);
$insC->execute([$sansFacturation, 'Chris', 'Seul', 'chris@asso.test', '+41 24 555 44 33', 0, 1]);

$insE = db()->prepare("INSERT INTO evenements (date, ville, pays, statut) VALUES (?, ?, 'CH', 'confirme')");
$insE->execute(['2026-05-01', 'Genève']);
$ev1 = (int) db()->lastInsertId();
$insE->execute(['2026-05-02', 'Yverdon']);
$ev2 = (int) db()->lastInsertId();
$insE->execute(['2026-05-03', 'Nulle part']);
$ev3 = (int) db()->lastInsertId(); // aucune structure liée

$insL = db()->prepare('INSERT INTO evenement_structures (evenement_id, structure_id, est_facturation) VALUES (?, ?, ?)');
$insL->execute([$ev1, $avecFacturation, 1]);   // cas nº 1 : marquée « à facturer »
$insL->execute([$ev2, $sansFacturation, 0]);   // cas nº 2 : liée, mais pas marquée
evenement_resynchroniser_miroirs($ev1);
evenement_resynchroniser_miroirs($ev2);

$miroir2 = db()->query("SELECT organisateur_structure_id FROM evenements WHERE id = $ev2")->fetchColumn();

// La route termine par exit() (c'est un téléchargement) : les vérifications
// vivent donc dans une fonction d'arrêt, enregistrée avant l'appel. Rien n'est
// affiché avant, sinon header() se plaint que la sortie a déjà commencé.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'evenements_export_suisa'];
ob_start();
register_shutdown_function(function () use ($miroir2) {
    $csv = ob_get_clean();
    verifier($csv, $miroir2);
});
route_evenements_export_suisa();

function verifier(string $csv, $miroir2): void
{
    global $tests, $fails;
    // Le miroir reste vide pour le cas nº 2 : c'est bien lui qui manquait à l'export.
    check('le miroir reste vide sans « à facturer »', null, $miroir2 === false ? null : $miroir2);

    $lignes = [];
    foreach (array_filter(explode("\n", trim($csv))) as $l) {
        $lignes[] = str_getcsv(trim($l), ';', '"', '\\');
    }
    $entete = array_shift($lignes) ?: [];
    // Le BOM UTF-8 précède le premier en-tête.
    check('la colonne « Organisateur — Nom » est la 11e', 'Organisateur — Nom', $entete[10] ?? '');
    $parVille = [];
    foreach ($lignes as $c) {
        $parVille[$c[2] ?? ''] = $c;
    }
    // Cas nº 1 : inchangé, la structure de facturation reste prioritaire.
    check('structure « à facturer » : nom', 'Salle des Fêtes', $parVille['Genève'][10] ?? '');
    check('structure « à facturer » : rue', 'Rue du Test 3', $parVille['Genève'][11] ?? '');
    // Le contact coché « administration » l'emporte, même s'il n'est pas le premier.
    check('contact « administration » : e-mail', 'admin@salle.test', $parVille['Genève'][15] ?? '');
    check('contact « administration » : téléphone', '+41 22 999 88 77', $parVille['Genève'][16] ?? '');
    check('contact « administration » : nom complet', 'Bruno Administration', $parVille['Genève'][17] ?? '');
    // Cas nº 2 : c'est ce qui sortait vide avant le correctif.
    check('organisateur simplement lié : nom', 'Association Sans Facture', $parVille['Yverdon'][10] ?? '');
    check('organisateur simplement lié : rue', 'Chemin Neuf 7', $parVille['Yverdon'][11] ?? '');
    check('organisateur simplement lié : NPA', '1400', $parVille['Yverdon'][12] ?? '');
    check('organisateur simplement lié : localité', 'Yverdon', $parVille['Yverdon'][13] ?? '');
    // Aucun contact coché : on prend le premier, pas les champs de la structure.
    check('aucun « administration » : e-mail du 1er contact', 'chris@asso.test', $parVille['Yverdon'][15] ?? '');
    check('aucun « administration » : téléphone du 1er contact', '+41 24 555 44 33', $parVille['Yverdon'][16] ?? '');
    check('aucun « administration » : nom du 1er contact', 'Chris Seul', $parVille['Yverdon'][17] ?? '');
    // Cas nº 3 : sans structure liée, il n'y a rien à inventer.
    check('aucune structure liée : colonnes vides', '', $parVille['Nulle part'][10] ?? 'absent');

    echo "\n";
    if ($fails === 0) {
        echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
        exit(0);
    }
    echo "❌ $fails / $tests assertions en échec\n";
    exit(1);
}
