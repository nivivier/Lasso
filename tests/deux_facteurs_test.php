<?php
// Tests de la validation en deux étapes (lib/helpers/deux_facteurs.php).
// Lancement : php tests/deux_facteurs_test.php
//
// Ce que ces tests protègent :
//  1. le calcul lui-même : base32 (RFC 4648) et TOTP contre les vecteurs
//     publiés de la RFC 6238 — un code faux d'un chiffre, et personne ne se
//     connecte plus ;
//  2. la fenêtre (±1 pas, pas davantage) et le refus de rejouer un pas ;
//  3. ce que la base garde : le secret chiffré, les codes de secours en
//     empreinte seulement, chacun valable une fois ;
//  4. le parcours réel, sur un serveur intégré : entre le mot de passe et le
//     code, AUCUNE page ne s'ouvre ; l'anti-force-brute tient sur l'étape
//     code, et un mot de passe juste ne le remet pas à zéro ; « mot de passe
//     oublié » ne retire pas la validation ; la réinitialisation par un
//     administrateur est journalisée, sans le code.

declare(strict_types=1);

$racine = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/lasso_2fa_' . bin2hex(random_bytes(6));
mkdir($dir);
$tmp = "$dir/base.sqlite";
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/journal.php';

require_once __DIR__ . '/_outils.php';
$serveur = null;
register_shutdown_function(function () use ($dir, &$serveur) {
    if (is_resource($serveur)) {
        proc_terminate($serveur);
        proc_close($serveur);
    }
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

echo "1) Base32 (RFC 4648 § 10)\n";
foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ',
          'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $clair => $b32) {
    check("encoder « $clair »", $b32, base32_encoder($clair));
    if ($clair !== '') {
        check("décoder « $b32 »", $clair, base32_decoder($b32));
    }
}
check('décoder tolère espaces, tirets, minuscules et remplissage', 'foobar', base32_decoder('mzxw 6ytb-oi======'));
check('un caractère hors alphabet est refusé', null, base32_decoder('MZXW1'));
$s = random_bytes(20);
check('aller-retour sur 20 octets', $s, base32_decoder(base32_encoder($s)));
check('un secret neuf fait 32 caractères base32', 1, preg_match('/^[A-Z2-7]{32}$/', totp_secret_generer()));

echo "\n2) TOTP — vecteurs de la RFC 6238, annexe B\n";
$secrets = [
    'sha1'   => '12345678901234567890',
    'sha256' => '12345678901234567890123456789012',
    'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
];
$vecteurs = [
    59          => ['sha1' => '94287082', 'sha256' => '46119246', 'sha512' => '90693936'],
    1111111109  => ['sha1' => '07081804', 'sha256' => '68084774', 'sha512' => '25091201'],
    1111111111  => ['sha1' => '14050471', 'sha256' => '67062674', 'sha512' => '99943326'],
    1234567890  => ['sha1' => '89005924', 'sha256' => '91819424', 'sha512' => '93441116'],
    2000000000  => ['sha1' => '69279037', 'sha256' => '90698825', 'sha512' => '38618901'],
    20000000000 => ['sha1' => '65353130', 'sha256' => '77737706', 'sha512' => '47863826'],
];
foreach ($vecteurs as $t => $parAlgo) {
    foreach ($parAlgo as $algo => $code) {
        check("T=$t $algo", $code, totp_code($secrets[$algo], totp_pas($t), 8, $algo));
    }
}
// Six chiffres = les six derniers des huit (troncature modulo 10^6).
check('six chiffres : la troncature de la RFC', '287082', totp_code($secrets['sha1'], totp_pas(59)));

echo "\n3) Fenêtre et rejeu\n";
$secret = $secrets['sha1'];
$t = 1700000000;
$p = totp_pas($t);
check('le pas courant passe', $p, totp_verifier($secret, totp_code($secret, $p), 0, $t));
check('le pas précédent passe (−30 s)', $p - 1, totp_verifier($secret, totp_code($secret, $p - 1), 0, $t));
check('le pas suivant passe (+30 s)', $p + 1, totp_verifier($secret, totp_code($secret, $p + 1), 0, $t));
check('deux pas avant : refusé', null, totp_verifier($secret, totp_code($secret, $p - 2), 0, $t));
check('deux pas après : refusé', null, totp_verifier($secret, totp_code($secret, $p + 2), 0, $t));
check('un pas déjà consommé ne repasse pas', null, totp_verifier($secret, totp_code($secret, $p), $p, $t));
check('un pas antérieur au dernier consommé non plus', null, totp_verifier($secret, totp_code($secret, $p - 1), $p, $t));
check('un pas postérieur au dernier consommé passe', $p + 1, totp_verifier($secret, totp_code($secret, $p + 1), $p, $t));
check('l\'espace au milieu est toléré', $p, totp_verifier($secret, substr(totp_code($secret, $p), 0, 3) . ' ' . substr(totp_code($secret, $p), 3), 0, $t));
check('autre chose que six chiffres : refusé', null, totp_verifier($secret, 'abcdef', 0, $t));
check('cinq chiffres : refusé', null, totp_verifier($secret, substr(totp_code($secret, $p), 1), 0, $t));
$uri = totp_uri('JBSWY3DPEHPK3PXP', 'alice@example.test', 'Asso: test');
check('URI otpauth lisible par les applications', 'otpauth://totp/Asso%20%20test:alice%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=Asso%20%20test&algorithm=SHA1&digits=6&period=30', $uri);

// Le QR code : la matrice vient de tcpdf, le dessin d'ici. Un côté de
// 21 + 4k modules (version k+1) plus deux marges de quatre, fond blanc.
$svg = totp_qr_svg($uri);
check('QR code : un SVG', 1, preg_match('#^<svg class="qr-totp"[^>]*viewBox="0 0 (\d+) \1"#', $svg, $mq));
check('… d\'un côté valide (21 + 4k modules, plus la marge)', 0, ((int) ($mq[1] ?? 0) - 8 - 21) % 4);
check('… sur fond blanc, modules noirs', true, str_contains($svg, 'fill="#fff"') && str_contains($svg, '<path fill="#000" d="M'));
check('… sans rien de l\'adresse en clair', false, str_contains($svg, 'otpauth') || str_contains($svg, 'JBSWY3DP'));

echo "\n4) Ce que la base garde\n";
db();
$ins = db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)');
$ins->execute(['alice@example.test', hacher_mot_de_passe('mot-de-passe-alice')]);
$alice = (int) db()->lastInsertId();
$ins->execute(['admin@example.test', hacher_mot_de_passe('mot-de-passe-admin')]);
$admin = (int) db()->lastInsertId();
enregistrer_permissions_utilisateur($admin, array_fill_keys(PERMISSION_MODULES, 'ecriture'));

$b32 = totp_secret_generer();
$brut = base32_decoder($b32);
$codes = deux_facteurs_activer($alice, $b32, totp_pas(time()) - 5);
$lire = function (int $id): array {
    $st = db()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$u = $lire($alice);
check('activée', true, deux_facteurs_active($u));
check('le secret est chiffré en base', true, secret_est_chiffre((string) $u['totp_secret']));
check('… et ne contient pas la clé en clair', false, str_contains((string) $u['totp_secret'], $b32));
check('… et se relit', $brut, deux_facteurs_secret($u));
check('dix codes de secours', DEUX_FACTEURS_NB_CODES_SECOURS, count($codes));
check('format XXXX-XXXX-XXXX', 1, preg_match('/^[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}$/', $codes[0]));
check('tous différents', count($codes), count(array_unique($codes)));
$enBase = db()->query('SELECT code_hash FROM utilisateur_codes_secours')->fetchAll(PDO::FETCH_COLUMN);
check('aucun code en clair en base', [], array_values(array_intersect($enBase, array_merge($codes, array_map('code_secours_normaliser', $codes)))));
check('seulement leur empreinte', true, in_array(hash('sha256', code_secours_normaliser($codes[0])), $enBase, true));

$code = totp_code($brut, totp_pas(time()));
check('un code de l\'application passe', 'totp', deux_facteurs_verifier($u, $code));
check('… et ne se rejoue pas', null, deux_facteurs_verifier($lire($alice), $code));
check('un code de secours passe, saisi en minuscules sans tirets', 'secours', deux_facteurs_verifier($u, strtolower(str_replace('-', '', $codes[0]))));
check('… une seule fois', null, deux_facteurs_verifier($u, $codes[0]));
check('il en reste neuf', 9, deux_facteurs_codes_restants($alice));
check('un code inventé ne passe pas', null, deux_facteurs_verifier($u, 'AAAA-BBBB-CCCC'));
$nouveaux = deux_facteurs_codes_generer($alice);
check('nouveaux codes : les anciens ne valent plus', null, deux_facteurs_verifier($u, $codes[1]));
check('… les nouveaux, si', 'secours', deux_facteurs_verifier($u, $nouveaux[0]));

// L'étape intermédiaire a une durée de vie.
$_SESSION = ['deux_facteurs' => ['uid' => $alice, 'depuis' => time() - DEUX_FACTEURS_ETAPE_DUREE - 1]];
check('étape expirée : plus de compte en attente', null, connexion_en_attente());
check('… et elle est effacée', false, isset($_SESSION['deux_facteurs']));
$_SESSION = ['deux_facteurs' => ['uid' => $alice, 'depuis' => time()]];
check('étape en cours : le compte attend', $alice, (int) (connexion_en_attente()['id'] ?? 0));
$_SESSION = [];

// Le journal ne garde jamais le code : seulement les clés de JOURNAL_CIBLES.
[$ct, $ci, $g] = journal_extraire(['csrf' => 'x', 'code' => '123456', 'confirmation' => 'secret', 'id' => '7']);
check('journal : la cible, pas le code', ['id', 7, ''], [$ct, $ci, $g]);

echo "\n5) Le parcours réel (serveur intégré)\n";
file_put_contents("$dir/prepend.php", '<?php define("APP_DB_PATH", ' . var_export($tmp, true) . ');');
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$serveur = proc_open(sprintf('exec php -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s',
    escapeshellarg("$dir/prepend.php"), $port, escapeshellarg($racine)),
    [0 => ['pipe', 'r'], 1 => ['file', "$dir/serveur.log", 'a'], 2 => ['file', "$dir/serveur.log", 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$url = "http://127.0.0.1:$port/index.php";

function requete(string $url, string &$cookies, ?array $post = null): array
{
    $entetes = $cookies !== '' ? "Cookie: $cookies\r\n" : '';
    $opts = ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30];
    if ($post !== null) {
        $opts['method'] = 'POST';
        $opts['content'] = http_build_query($post);
        $entetes .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $opts['header'] = $entetes;
    $corps = (string) @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    $reponse = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($reponse[0] ?? ''), $m) ? (int) $m[1] : 0;
    $lieu = '';
    foreach ($reponse as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+=[^;]*)/i', $h, $c)) {
            $cookies = $c[1];
        } elseif (preg_match('/^Location:\s*(\S+)/i', $h, $l)) {
            $lieu = $l[1];
        }
    }
    return [$code, $corps, $lieu];
}
function jeton(string $page): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $page, $m) ? $m[1] : '';
}
// Mot de passe → rend [lieu, cookies, jeton CSRF de la page suivante].
function mot_de_passe(string $url, string $email, string $mdp): array
{
    $cookies = '';
    [, $page] = requete("$url?p=connexion", $cookies);
    [, , $lieu] = requete("$url?p=connexion", $cookies, ['csrf' => jeton($page), 'email' => $email, 'mot_de_passe' => $mdp]);
    [, $page] = requete("$url?p=connexion_code", $cookies);
    return [$lieu, $cookies, jeton($page)];
}
$repartir = function () use ($alice) {
    db()->exec('DELETE FROM login_attempts');
    db()->prepare('UPDATE utilisateurs SET totp_dernier_pas = 0 WHERE id = ?')->execute([$alice]);
};
$repartir();

[$lieu, $ck, $jt] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
check('mot de passe juste → étape code', true, str_contains($lieu, 'p=connexion_code'));
foreach (['tableau_bord', 'mon_compte', 'utilisateurs', 'deux_facteurs', 'fiches'] as $r) {
    [, , $l] = requete("$url?p=$r", $ck);
    check("pendant l'étape, ?p=$r renvoie à la connexion", true, str_contains($l, 'p=connexion'));
}
[, , $l] = requete("$url?p=deux_facteurs_desactiver", $ck, ['csrf' => $jt, 'confirmation' => 'mot-de-passe-alice']);
check('pendant l\'étape, désactiver est impossible', true, str_contains($l, 'p=connexion') && deux_facteurs_active($lire($alice)));
[$c, $page] = requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => '000000']);
check('code faux : refusé, on reste sur l\'étape', true, $c === 200 && str_contains($page, 'Code incorrect'));
$code = totp_code($brut, totp_pas(time()));
[, , $l] = requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => $code]);
check('code juste → tableau de bord', true, str_contains($l, 'p=tableau_bord'));
[$c, , $l] = requete("$url?p=mon_compte", $ck);
check('… et la session est ouverte', 200, $c);
check('les échecs sont purgés après le code', 0, (int) db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());

[, $ck, $jt] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
[, $page, $l] = requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => $code]);
check('le même code, rejoué : refusé', true, $l === '' && str_contains($page, 'Code incorrect'));

// Anti-force-brute : le mot de passe juste ne remet pas le compteur à zéro.
$repartir();
for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
    [, $ck, $jt] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
    requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => '000000']);
}
check('cinq codes faux, mot de passe retapé chaque fois : le compteur tient', LOGIN_MAX_ATTEMPTS,
    (int) db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
[$lieu] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
check('… et le mot de passe juste n\'ouvre plus l\'étape code', false, str_contains($lieu, 'p=connexion_code'));
$repartir();
[, $ck, $jt] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
    requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => '000000']);
}
[, $page, $l] = requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => totp_code($brut, totp_pas(time()))]);
check('cinq codes faux dans la même étape : même le bon est refusé', true, $l === '' && str_contains($page, 'Trop de tentatives'));
[, , $l] = requete("$url?p=connexion_code", $ck);
check('… et l\'étape est refermée', true, str_contains($l, 'p=connexion'));

// Un code de secours ouvre la session, et l'on dit combien il en reste.
$repartir();
[, $ck, $jt] = mot_de_passe($url, 'alice@example.test', 'mot-de-passe-alice');
[, , $l] = requete("$url?p=connexion_code", $ck, ['csrf' => $jt, 'code' => $nouveaux[1]]);
check('code de secours → mon compte, avec le reste', true, str_contains($l, 'p=mon_compte') && str_contains($l, 'secours=8'));

// « Mot de passe oublié » change le mot de passe, pas la validation.
$jeton = reinit_creer_jeton($alice, '127.0.0.1');
$ck = '';
[, $page] = requete("$url?p=motdepasse_reinitialiser&jeton=$jeton", $ck);
[, , $l] = requete("$url?p=motdepasse_reinitialiser", $ck, ['csrf' => jeton($page), 'jeton' => $jeton,
    'mot_de_passe' => 'nouveau-mot-de-passe', 'confirmer' => 'nouveau-mot-de-passe']);
check('réinitialisation du mot de passe : pas de session ouverte', true, str_contains($l, 'p=connexion'));
[$lieu] = mot_de_passe($url, 'alice@example.test', 'nouveau-mot-de-passe');
check('… et la connexion demande toujours le code', true, str_contains($lieu, 'p=connexion_code'));

// Un administrateur réinitialise la validation d'alice : journalisé, sans code.
$repartir();
[$lieu, $ckA] = mot_de_passe($url, 'admin@example.test', 'mot-de-passe-admin');
check('l\'administrateur, sans validation, entre directement', true, str_contains($lieu, 'p=tableau_bord'));
[, $page] = requete("$url?p=utilisateurs", $ckA);
check('la liste signale le compte protégé', true, str_contains($page, 'utilisateur_deux_facteurs_reinitialiser'));
[, , $l] = requete("$url?p=utilisateur_deux_facteurs_reinitialiser", $ckA, ['csrf' => jeton($page), 'id' => $alice]);
check('réinitialisation acceptée', true, str_contains($l, 'ok=deux_facteurs'));
check('… la validation est retirée', false, deux_facteurs_active($lire($alice)));
check('… les codes de secours aussi', 0, deux_facteurs_codes_restants($alice));
$ligne = db()->query("SELECT route, cible_type, cible_id, issue FROM journal_activite
                      WHERE route = 'utilisateur_deux_facteurs_reinitialiser'")->fetch(PDO::FETCH_ASSOC) ?: [];
check('… et journalisée', ['route' => 'utilisateur_deux_facteurs_reinitialiser', 'cible_type' => 'id', 'cible_id' => $alice, 'issue' => 'ok'], $ligne);
[$lieu] = mot_de_passe($url, 'alice@example.test', 'nouveau-mot-de-passe');
check('alice se reconnecte avec son seul mot de passe', true, str_contains($lieu, 'p=tableau_bord'));

// Un compte sans droit d'administration ne peut pas réinitialiser.
deux_facteurs_activer($admin, totp_secret_generer(), 0);
[, $ckAlice] = mot_de_passe($url, 'alice@example.test', 'nouveau-mot-de-passe');
[, $page] = requete("$url?p=mon_compte", $ckAlice);
requete("$url?p=utilisateur_deux_facteurs_reinitialiser", $ckAlice, ['csrf' => jeton($page), 'id' => $admin]);
check('un compte sans droits ne réinitialise pas celle d\'un autre', true, deux_facteurs_active($lire($admin)));

// L'activation par le titulaire : la clé de la page, puis un premier code.
[, $page] = requete("$url?p=deux_facteurs", $ckAlice);
preg_match('#<p class="cle-totp"><code>([A-Z2-7 ]+)</code>#', $page, $m);
$cle = str_replace(' ', '', $m[1] ?? '');
check('la page d\'activation montre la clé', 32, strlen($cle));
check('… et le lien otpauth://', true, str_contains($page, 'otpauth://totp/'));
check('… et le QR code', true, str_contains($page, '<svg class="qr-totp"'));
[, , $l] = requete("$url?p=deux_facteurs_activer", $ckAlice, ['csrf' => jeton($page), 'code' => '000000']);
check('premier code faux : rien n\'est activé', true, str_contains($l, 'err=1') && !deux_facteurs_active($lire($alice)));
[, , $l] = requete("$url?p=deux_facteurs_activer", $ckAlice, ['csrf' => jeton($page),
    'code' => totp_code((string) base32_decoder($cle), totp_pas(time()))]);
check('premier code juste : activée', true, deux_facteurs_active($lire($alice)));
[, $page] = requete("$url?p=deux_facteurs", $ckAlice);
check('les codes de secours s\'affichent une fois', DEUX_FACTEURS_NB_CODES_SECOURS, substr_count($page, '<li><code>'));
[, , $l] = requete("$url?p=deux_facteurs", $ckAlice);
check('… et plus jamais ensuite', true, str_contains($l, 'p=mon_compte'));
[, $page] = requete("$url?p=mon_compte", $ckAlice);
[, , $l] = requete("$url?p=deux_facteurs_desactiver", $ckAlice, ['csrf' => jeton($page), 'confirmation' => 'faux-mot-de-passe']);
check('désactiver avec un mauvais mot de passe : refusé', true, str_contains($l, 'err=deux_facteurs') && deux_facteurs_active($lire($alice)));
[, , $l] = requete("$url?p=deux_facteurs_desactiver", $ckAlice, ['csrf' => jeton($page), 'confirmation' => 'nouveau-mot-de-passe']);
check('désactiver avec le mot de passe : fait', false, deux_facteurs_active($lire($alice)));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
