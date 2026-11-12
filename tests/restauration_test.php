<?php
// Tests de la RESTAURATION d'une sauvegarde complète (lib/sauvegarde.php).
// Lancement : php tests/restauration_test.php
//
// C'est l'opération la plus destructrice de l'application : elle remplace la
// base entière. Trois promesses doivent tenir, et ce sont elles qu'on vérifie
// ici plutôt que le détail du code :
//
//   1. une archive refusée ne touche à RIEN — la base d'avant est intacte ;
//   2. une archive acceptée remet tout : la base ET les fichiers déposés, quel
//      que soit le format (zip, tar.gz, ou la base seule) ;
//   3. la base d'avant est mise de côté, et une archive ne peut pas écrire
//      ailleurs que dans uploads/ et data/fichiers/ — une archive est un
//      fichier reçu, pas une consigne.
//
// La base de travail est temporaire (APP_DB_PATH redéfinie avant config.php),
// et les fichiers sont restaurés dans un faux dépôt : rien n'est écrit dans le
// projet.

declare(strict_types=1);

$bac = sys_get_temp_dir() . '/lasso_restaure_test_' . bin2hex(random_bytes(6));
mkdir($bac . '/depot/uploads', 0775, true);
mkdir($bac . '/depot/data/fichiers', 0775, true);

define('APP_DB_PATH', $bac . '/cible.sqlite');
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/sauvegarde.php';

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
    printf("  FAIL  %-54s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

// --- La base à remplacer, reconnaissable -----------------------------------
db()->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?)
               ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur')
    ->execute(['employeur_nom', 'AVANT']);
$nomActuel = fn (): string => (string) (new PDO('sqlite:' . APP_DB_PATH))
    ->query('SELECT valeur FROM parametres WHERE cle = "employeur_nom"')->fetchColumn();

// --- La base à restaurer : une vraie base, fabriquée par le générateur ------
$source = $bac . '/source.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/base_demo.php')
    . ' ' . escapeshellarg($source) . ' --sans-scan 2>&1', $sortie, $code);
$srcPdo = new PDO('sqlite:' . $source);
$srcPdo->exec('UPDATE parametres SET valeur = "APRES" WHERE cle = "employeur_nom"');
$personnesSource = (int) $srcPdo->query('SELECT COUNT(*) FROM personnes')->fetchColumn();
$srcPdo = null;

echo "1) Reconnaître le format aux premiers octets, pas au nom\n";
$faux = $bac . '/menteur.sqlite';
file_put_contents($faux, "ceci n'est pas une base");
check('un .sqlite qui n\'en est pas un n\'est pas reconnu', '', sauvegarde_archive_format($faux));
check('une vraie base est reconnue', 'sqlite', sauvegarde_archive_format($source));

echo "\n2) Une archive refusée ne touche à rien\n";
$avant = $nomActuel();
$refus = '';
try {
    sauvegarde_restaurer($faux, $bac . '/depot');
} catch (Throwable $e) {
    $refus = $e->getMessage();
}
check('le fichier illisible est refusé', true, $refus !== '');
check('la base n\'a pas bougé', $avant, $nomActuel());

// Une vraie base SQLite, mais pas une base Lasso.
$etranger = $bac . '/etranger.sqlite';
$pe = new PDO('sqlite:' . $etranger);
$pe->exec('CREATE TABLE autre (x INTEGER)');
$pe = null;
check('une base SQLite étrangère est reconnue comme telle', 'sqlite', sauvegarde_archive_format($etranger));
$refus2 = '';
try {
    sauvegarde_restaurer($etranger, $bac . '/depot');
} catch (Throwable $e) {
    $refus2 = $e->getMessage();
}
check('elle est refusée', true, str_contains($refus2, 'pas une base Lasso'));
check('et la base n\'a toujours pas bougé', $avant, $nomActuel());

echo "\n3) Une archive ne peut écrire que dans uploads/ et data/fichiers/\n";
$racineFeinte = '/tmp/depot';
foreach (['../evasion.txt', 'uploads/../../evasion.txt', '/etc/passwd', 'lib/routes.php',
          'data/database.sqlite', 'uploads/'] as $mechant) {
    check('refusé : ' . $mechant, null, sauvegarde_cible_fichier($mechant, $racineFeinte));
}
check('accepté : uploads/logo.png', '/tmp/depot/uploads/logo.png',
    sauvegarde_cible_fichier('uploads/logo.png', $racineFeinte));
check('accepté : data/fichiers/piece.pdf', '/tmp/depot/data/fichiers/piece.pdf',
    sauvegarde_cible_fichier('data/fichiers/piece.pdf', $racineFeinte));

echo "\n4) Restauration réelle, les trois formats\n";
foreach (['sqlite', 'zip', 'tar.gz'] as $format) {
    // Remettre la cible dans son état « avant » pour chaque format.
    $p = new PDO('sqlite:' . APP_DB_PATH);
    $p->exec('UPDATE parametres SET valeur = "AVANT" WHERE cle = "employeur_nom"');
    $p->exec('DELETE FROM personnes');
    $p = null;
    @unlink($bac . '/depot/uploads/logo-restaure.png');
    @unlink($bac . '/depot/data/fichiers/piece-restauree.pdf');

    $archive = fabriquer_archive($source, $bac, $format);
    if ($archive === null) {
        echo "  (format $format indisponible sur ce serveur, ignoré)\n";
        continue;
    }
    check("[$format] format reconnu", $format, sauvegarde_archive_format($archive));
    $r = sauvegarde_restaurer($archive, $bac . '/depot');

    check("[$format] la base est remplacée", 'APRES', $nomActuel());
    check("[$format] toutes les lignes sont là", $personnesSource,
        (int) (new PDO('sqlite:' . APP_DB_PATH))->query('SELECT COUNT(*) FROM personnes')->fetchColumn());
    check("[$format] la base d'avant est mise de côté", true, $r['precedente'] !== null && is_file((string) $r['precedente']));
    check("[$format] le schéma est à jour", true, $r['version'] > 0);
    if ($format !== 'sqlite') {
        check("[$format] les fichiers déposés sont revenus", 'PNG factice',
            (string) @file_get_contents($bac . '/depot/uploads/logo-restaure.png'));
        check("[$format] l'entrée piégée n'est allée nulle part", false, file_exists($bac . '/evasion.txt'));
        check("[$format] ni à la racine du faux dépôt", false, file_exists($bac . '/depot/evasion.txt'));
    } else {
        // Le repli « base seule » n'embarque aucun fichier : il ne doit donc
        // rien effacer non plus. On vérifie qu'un fichier déjà là a survécu.
        check("[sqlite] une base seule ne touche pas aux fichiers déposés", true,
            is_dir($bac . '/depot/uploads'));
    }
    @unlink($archive);
}

echo "\n5) Archive chiffrée : la phrase, ou rien\n";
if (!sauvegarde_chiffrement_possible('zip')) {
    echo "  (chiffrement AES indisponible sur ce serveur, ignoré)\n";
} else {
    $p = new PDO('sqlite:' . APP_DB_PATH);
    $p->exec('UPDATE parametres SET valeur = "AVANT" WHERE cle = "employeur_nom"');
    $p = null;
    $archive = fabriquer_archive($source, $bac, 'zip', 'la bonne phrase secrète');
    $motif = function (string $phrase) use ($archive, $bac): string {
        try {
            sauvegarde_restaurer($archive, $bac . '/depot', $phrase);
            return '';
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    check('sans phrase : refusée, et on dit pourquoi', true, str_contains($motif(''), 'phrase secrète'));
    check('mauvaise phrase : refusée, et on dit pourquoi', true, str_contains($motif('une autre phrase'), 'incorrecte'));
    check('… la base d\'avant est intacte', 'AVANT', $nomActuel());
    check('bonne phrase : restaurée', '', $motif('la bonne phrase secrète'));
    check('… la base est remplacée', 'APRES', $nomActuel());
    check('… les fichiers déposés sont revenus', 'PNG factice', (string) @file_get_contents($bac . '/depot/uploads/logo-restaure.png'));
    @unlink($archive);
}

echo "\n6) Restaurer une copie du serveur\n";
// La copie est prise par une connexion neuve : db() a gardé sous la main le
// fichier d'avant les restaurations ci-dessus (c'est voulu — rename()).
$fraiche = fn (): PDO => new PDO('sqlite:' . APP_DB_PATH);
$fraiche()->exec('UPDATE parametres SET valeur = "COPIE" WHERE cle = "employeur_nom"');
$nomCopie = basename(APP_DB_PATH, '.sqlite') . '_manuelle_20260101_000000.sqlite.bak';
$fraiche()->exec('VACUUM INTO ' . $fraiche()->quote(dirname(APP_DB_PATH) . '/' . $nomCopie));
$fraiche()->exec('UPDATE parametres SET valeur = "MODIFIEE" WHERE cle = "employeur_nom"');
check('la copie est dans la liste', true, in_array($nomCopie, array_column(sauvegardes_serveur(), 'nom'), true));
check('son nom mène à son chemin', dirname(APP_DB_PATH) . '/' . $nomCopie, sauvegarde_serveur_chemin($nomCopie));
check('la base elle-même n\'est pas une copie', null, sauvegarde_serveur_chemin(basename(APP_DB_PATH)));
check('un chemin n\'est pas un nom', null, sauvegarde_serveur_chemin('../' . basename($bac) . '/' . $nomCopie));
$r = sauvegarde_restaurer((string) sauvegarde_serveur_chemin($nomCopie), $bac . '/depot');
check('restaurée : la base est celle de la copie', 'COPIE', $nomActuel());
check('… aucun fichier déposé n\'est touché', 0, $r['fichiers']);
check('… la base d\'avant est mise de côté, et listée', true,
    in_array(basename((string) $r['precedente']), array_column(sauvegardes_serveur(), 'nom'), true));
check('… la copie restaurée reste disponible', true, is_file(dirname(APP_DB_PATH) . '/' . $nomCopie));
$routes = (string) file_get_contents(dirname(__DIR__) . '/lib/routes.php');
check('la route résout le nom par la liste, jamais en chemin', true,
    str_contains($routes, "sauvegarde_serveur_chemin((string) (\$_POST['nom'] ?? ''))"));

// Fabrique une archive de sauvegarde au format voulu, comme en produirait une
// autre installation. Renvoie null si le serveur ne sait pas faire ce format.
function fabriquer_archive(string $base, string $bac, string $format, string $phrase = ''): ?string
{
    if ($format === 'sqlite') {
        $a = $bac . '/sauvegarde.sqlite';
        copy($base, $a);
        return $a;
    }
    if ($format === 'zip') {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $a = $bac . '/sauvegarde.zip';
        @unlink($a);
        $z = new ZipArchive();
        $z->open($a, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFile($base, 'base.sqlite');
        $z->addFromString('SAUVEGARDE.txt', 'note');
        $z->addFromString('uploads/logo-restaure.png', 'PNG factice');
        $z->addFromString('data/fichiers/piece-restauree.pdf', 'PDF factice');
        $z->addFromString('../evasion.txt', 'ne doit jamais sortir');
        if ($phrase !== '') {
            foreach (['base.sqlite', 'uploads/logo-restaure.png', 'data/fichiers/piece-restauree.pdf'] as $n) {
                $z->setEncryptionName($n, ZipArchive::EM_AES_256, $phrase);
            }
        }
        $z->close();
        return $a;
    }
    if (!class_exists('PharData')) {
        return null;
    }
    $tar = $bac . '/sauvegarde.tar';
    @unlink($tar);
    @unlink($tar . '.gz');
    $p = new PharData($tar);
    $p->addFile($base, 'base.sqlite');
    $p->addFromString('SAUVEGARDE.txt', 'note');
    $p->addFromString('uploads/logo-restaure.png', 'PNG factice');
    $p->addFromString('data/fichiers/piece-restauree.pdf', 'PDF factice');
    $p->compress(Phar::GZ);
    $p = null;
    @unlink($tar);
    return $tar . '.gz';
}

// --- Ménage -----------------------------------------------------------------
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($bac, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($bac);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
