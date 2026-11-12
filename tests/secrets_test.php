<?php
// Secrets chiffrés en base (lib/secrets.php) : mots de passe des boîtes
// d'envoi et n° AVS (employés, copie figée des fiches).
// Lancement : php tests/secrets_test.php
//
// Trois promesses : la base ne contient plus aucun mot de passe en clair (y
// compris ceux d'avant, que migration_106 convertit) ; l'envoi les relit
// correctement ; et une base venue d'un autre serveur — une autre clé — ne
// casse rien : le mot de passe est déclaré illisible, à ressaisir.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_secrets_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/booking.php';
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
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

$pdo = db();

echo "1) Chiffrer, déchiffrer\n";
$c = secret_chiffrer('motdepasse-smtp');
check('la valeur stockée porte le préfixe', true, secret_est_chiffre($c));
check('elle ne contient pas le clair', false, str_contains($c, 'motdepasse-smtp'));
check('elle se relit', 'motdepasse-smtp', secret_dechiffrer($c));
check('deux chiffrements du même mot diffèrent (IV tiré au sort)', false, $c === secret_chiffrer('motdepasse-smtp'));
check('le vide reste vide', '', secret_chiffrer(''));
check('une valeur d\'avant le chiffrement se lit telle quelle', 'ancien', secret_dechiffrer('ancien'));
check('une valeur abîmée est illisible, pas une erreur', null, secret_dechiffrer(SECRET_PREFIXE . 'pas-du-base64!'));
$falsifie = substr($c, 0, -4) . (substr($c, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check('une valeur falsifiée est refusée (GCM)', null, secret_dechiffrer($falsifie));

echo "\n2) La clé\n";
$fichier = secret_cle_fichier();
check('créée à côté de la base', true, is_file($fichier));
check('… nommée comme elle', true, str_starts_with(basename($fichier), basename($tmp)));
check('… et ignorée par git, où que soit la base', true,
    str_contains((string) file_get_contents(__DIR__ . '/../.gitignore'), "\n*.sqlite.cle.php\n"));
ob_start();
$rendu = include $fichier;
check('servie par erreur, elle n\'affiche rien', '', ob_get_clean());
check('… et contient 32 octets', 32, strlen((string) base64_decode((string) $rendu, true)));
check('lisible par le seul propriétaire', '0600', substr(sprintf('%o', fileperms($fichier)), -4));

echo "\n3) migration_106 convertit l'existant\n";
$pdo->exec("INSERT OR REPLACE INTO parametres (cle, valeur) VALUES ('smtp_pass', 'clair-general'), ('smtp_booking_pass', 'clair-booking')");
$pdo->exec("INSERT INTO mailing_expediteurs (nom, email, smtp_pass) VALUES ('Diffusion', 'diffusion@example.test', 'clair-boite')");
migration_106($pdo);
$dump = implode('|', $pdo->query("SELECT valeur FROM parametres WHERE cle LIKE 'smtp%'")->fetchAll(PDO::FETCH_COLUMN))
    . '|' . implode('|', $pdo->query('SELECT smtp_pass FROM mailing_expediteurs')->fetchAll(PDO::FETCH_COLUMN));
check('plus aucun mot de passe en clair', false, (bool) preg_match('/clair-/', $dump));
$avant = $dump;
migration_106($pdo);
$apres = implode('|', $pdo->query("SELECT valeur FROM parametres WHERE cle LIKE 'smtp%'")->fetchAll(PDO::FETCH_COLUMN))
    . '|' . implode('|', $pdo->query('SELECT smtp_pass FROM mailing_expediteurs')->fetchAll(PDO::FETCH_COLUMN));
check('rejouée, elle ne rechiffre pas', $avant, $apres);

echo "\n4) L'envoi relit le clair\n";
$exp = $pdo->query('SELECT * FROM mailing_expediteurs LIMIT 1')->fetch();
check('boîte d\'un expéditeur', 'clair-boite', smtp_config_booking($exp)['pass']);
check('profil booking hérité', 'clair-booking', smtp_config_booking(['smtp_pass' => ''])['pass']);
check('profil général', 'clair-general', smtp_config()['pass']);

echo "\n5) Une base venue d'ailleurs (autre clé)\n";
// Une valeur chiffrée avec une autre clé : on la fabrique à la main.
$autreCle = random_bytes(32);
$iv = random_bytes(12);
$tag = '';
$ct = openssl_encrypt('secret-d-ailleurs', 'aes-256-gcm', $autreCle, OPENSSL_RAW_DATA, $iv, $tag);
$etrangere = SECRET_PREFIXE . base64_encode($iv . $tag . $ct);
check('illisible, sans erreur', null, secret_dechiffrer($etrangere));
check('signalée à ressaisir', true, secret_illisible($etrangere));
check('un champ vide n\'est pas « illisible »', false, secret_illisible(''));
check('la boîte illisible passe la main au niveau suivant', 'clair-booking',
    smtp_config_booking(['smtp_pass' => $etrangere])['pass']);

echo "\n6) Les écritures passent par le chiffrement\n";
$src = (string) file_get_contents(__DIR__ . '/../lib/routes.php');
check('enregistrement de la boîte interne', true, str_contains($src, "['smtp_pass', secret_chiffrer(\$smtpPass)]"));
check('enregistrement d\'une boîte d\'envoi', 2, substr_count($src, "secret_chiffrer(\$champ('smtp_pass'))"));

echo "\n7) migration_108 chiffre les n° AVS, employés et fiches\n";
// Une ligne minimale : chaque colonne NOT NULL sans défaut reçoit 0 ou ''.
$inserer = function (string $table, array $valeurs) use ($pdo): int {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['notnull'] && $col['dflt_value'] === null && !$col['pk'] && !array_key_exists($col['name'], $valeurs)) {
            $valeurs[$col['name']] = str_contains(strtoupper((string) $col['type']), 'TEXT') ? '' : 0;
        }
    }
    $pdo->prepare("INSERT INTO $table (" . implode(',', array_keys($valeurs)) . ') VALUES ('
        . implode(',', array_fill(0, count($valeurs), '?')) . ')')->execute(array_values($valeurs));
    return (int) $pdo->lastInsertId();
};
$empId = $inserer('employes', ['numero_avs' => '756.1234.5678.97']);
$inserer('employes', ['numero_avs' => '']);
$ficheId = $inserer('fiches', ['employe_id' => $empId, 'annee' => 2025, 'mois' => 3, 'employe_avs' => '756.1234.5678.97']);
$copiesAvant = count(glob(sauvegardes_auto_motif()) ?: []);
migration_108($pdo);
$stockeEmp = (string) $pdo->query("SELECT numero_avs FROM employes WHERE id = $empId")->fetchColumn();
$stockeFiche = (string) $pdo->query("SELECT employe_avs FROM fiches WHERE id = $ficheId")->fetchColumn();
check('employé : plus en clair', false, str_contains($stockeEmp, '5678'));
check('… et relu à l\'identique', '756.1234.5678.97', avs_lire($stockeEmp));
check('fiche (copie figée) : plus en clair', false, str_contains($stockeFiche, '5678'));
check('… et relue à l\'identique', '756.1234.5678.97', avs_lire($stockeFiche));
check('un n° vide reste vide', 0, (int) $pdo->query("SELECT COUNT(*) FROM employes WHERE numero_avs LIKE 'chiffre:%' AND id <> $empId")->fetchColumn());
check('une copie de la base posée avant', $copiesAvant + 1, count(glob(sauvegardes_auto_motif()) ?: []));
migration_108($pdo);
check('rejouée : rien ne bouge, aucune copie de plus', [$stockeEmp, $copiesAvant + 1],
    [(string) $pdo->query("SELECT numero_avs FROM employes WHERE id = $empId")->fetchColumn(), count(glob(sauvegardes_auto_motif()) ?: [])]);
check('recherche d\'un employé par n° AVS, en PHP (espaces, points)', $empId, avs_trouver_employe('756 1234 5678 97'));
check('… un n° inconnu ne trouve rien', null, avs_trouver_employe('756.0000.0000.00'));
check('affichage d\'un n° illisible', 'illisible (clé de chiffrement absente)', avs_affiche($etrangere));
check('avs_lire d\'un n° illisible → vide', '', avs_lire($etrangere));

echo "\n8) Plusieurs clés : une clé s'ajoute, ne remplace pas\n";
$cleChiffrement = secret_cle();
check('une clé inconnue s\'ajoute', 1, secret_cles_ajouter([base64_encode($autreCle)]));
check('… la valeur venue d\'ailleurs devient lisible', 'secret-d-ailleurs', secret_dechiffrer($etrangere));
check('… les valeurs d\'ici le restent', '756.1234.5678.97', avs_lire($stockeEmp));
check('… on chiffre toujours avec la même', $cleChiffrement, secret_cle());
check('… une clé connue ne s\'ajoute pas deux fois', 0, secret_cles_ajouter([base64_encode($autreCle)]));
check('… et le fichier relu donne bien deux clés', 2, count(secret_cles(true)));
$rendu = include $fichier;
check('… sous forme de liste', true, is_array($rendu));
$illisible = false;
try {
    secret_cles_ajouter(['pas-une-cle']);
} catch (RuntimeException $e) {
    $illisible = true;
}
check('une clé mal formée est refusée', true, $illisible);

echo "\n9) Une archive chiffrée emporte la clé, une archive en clair non\n";
if (sauvegarde_chiffrement_possible('zip')) {
    [$archive] = sauvegarde_construire('test', 'x', 'zip', 'phrase-secrete-longue');
    $travail = sys_get_temp_dir() . '/lasso_secrets_x_' . bin2hex(random_bytes(4));
    mkdir($travail);
    [, , $cles] = sauvegarde_extraire($archive, 'zip', $travail, $travail, 'phrase-secrete-longue');
    check('archive chiffrée : les clés y sont', secret_cles_export(), $cles);
    $zip = new ZipArchive();
    $zip->open($archive);
    check('… et l\'entrée est chiffrée par la phrase', true, (int) $zip->statName(SAUVEGARDE_CLES)['encryption_method'] !== 0);
    $zip->close();
    @unlink($archive);
    [$archive] = sauvegarde_construire('test', 'x', 'zip');
    $zip = new ZipArchive();
    $zip->open($archive);
    check('archive en clair : pas de clé', false, $zip->statName(SAUVEGARDE_CLES));
    $zip->close();
    @unlink($archive);
    array_map('unlink', glob($travail . '/*') ?: []);
    @rmdir($travail);
} else {
    echo "  ignoré  (ce PHP ne chiffre pas les ZIP)\n";
}

echo "\n10) Le code n'écrit ni ne lit un n° AVS en clair\n";
$racine = dirname(__DIR__);
$brut = [];
foreach (array_merge(glob($racine . '/lib/*.php') ?: [], glob($racine . '/lib/*/*.php') ?: [], glob($racine . '/views/*.php') ?: []) as $f) {
    if (str_contains($f, '/migrations/') || str_ends_with($f, '/secrets.php')) {
        continue;
    }
    foreach (file($f) ?: [] as $n => $ligne) {
        $ou = substr($f, strlen($racine) + 1) . ':' . ($n + 1);
        // Affichage : e($x['numero_avs']) ou e($f['employe_avs']) sans avs_*().
        if (preg_match('/\be\(\s*(\(string\)\s*)?\$\w+\[\'(numero_avs|employe_avs)\'\]/', $ligne)) {
            $brut[] = $ou . ' (affiché sans avs_affiche)';
        }
        // Requête : un WHERE sur la colonne ne peut plus rien trouver.
        if (preg_match('/WHERE[^;]*\\b(numero_avs|employe_avs)\\s*(=|LIKE)/i', $ligne)) {
            $brut[] = $ou . ' (cherché en SQL)';
        }
        // Écriture depuis un fichier importé : passe par avs_stocker().
        if (preg_match('/\'(numero_avs|employe_avs)\'\s*=>\s*\(string\) \(\$f\[/', $ligne)) {
            $brut[] = $ou . ' (écrit sans avs_stocker)';
        }
    }
}
check('aucun n° AVS affiché, cherché ou écrit en clair', [], $brut);

@unlink($tmp);
@unlink($fichier);
foreach (array_merge(glob($tmp . '-*') ?: [], glob(dirname($tmp) . '/' . basename($tmp, '.sqlite') . '_*.sqlite.bak') ?: []) as $f) {
    @unlink($f);
}
echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
