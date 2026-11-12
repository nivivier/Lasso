<?php
// Secrets chiffrés en base : les mots de passe des boîtes d'envoi, et les
// numéros AVS (employés, et la copie figée de chaque fiche de salaire).
//
// Un serveur SMTP réclame le mot de passe lui-même, pas son empreinte : on ne
// peut donc pas le hacher comme celui d'un compte. On le CHIFFRE, avec une clé
// qui ne vit pas dans la base. Ce que cela protège : une copie de la base ou
// une archive de sauvegarde qui fuit (téléchargée, oubliée, copiée sur un
// poste) ne livre plus les boîtes d'envoi ni les n° AVS. Ce que cela ne
// protège pas : un accès au disque du serveur, qui donne la clé avec la base.
//
// Les clés, dans l'ordre :
//   1. la constante SECRETS_CLE (lib/config.local.php), 32 octets en base64 ;
//   2. le fichier « <APP_DB_PATH>.cle.php » à côté de la base, créé au premier
//      besoin si SECRETS_CLE n'existe pas. Un .php qui ne fait que
//      « return '…'; » (une clé) ou « return ['…', '…']; » (plusieurs) :
//      servi par erreur, il n'affiche rien. .gitignore l'écarte
//      (*.sqlite.cle.php), où que soit la base.
// On chiffre avec la PREMIÈRE ; on déchiffre avec n'importe laquelle. Une clé
// s'ajoute (restauration d'une archive qui apporte la sienne), elle ne se
// retire jamais : les copies de la base faites avant restent lisibles.
//
// ⚠️ La clé n'est PAS dans une archive de sauvegarde ordinaire — c'est tout
// l'intérêt. Une archive protégée par une phrase secrète l'embarque
// (sauvegarde_construire()) et la restauration l'ajoute ici
// (secret_cles_ajouter()) : restaurée sur un autre serveur, elle reste
// entièrement lisible. Une archive sans phrase, restaurée ailleurs, rend les
// secrets illisibles : secret_dechiffrer() rend null, on les ressaisit.
//
// Format stocké : « chiffre:v1:<base64(iv · tag · texte)> », AES-256-GCM
// (openssl, déjà requis pour le SMTP chiffré). Une valeur sans ce préfixe est
// lue telle quelle : c'est une valeur d'avant le chiffrement, que
// migration_106 (SMTP) et migration_108 (AVS) convertissent.

const SECRET_PREFIXE = 'chiffre:v1:';

function secret_cle_fichier(): string
{
    return APP_DB_PATH . '.cle.php';
}

// Les clés du fichier, en base64, telles qu'écrites (liste éventuellement vide).
function secret_cles_fichier(): array
{
    $fichier = secret_cle_fichier();
    if (!is_file($fichier)) {
        return [];
    }
    $v = include $fichier;
    return array_values(array_filter(array_map('strval', is_array($v) ? $v : [$v]), fn ($c) => $c !== ''));
}

function secret_cle_brute(string $b64, string $origine): string
{
    $c = base64_decode($b64, true);
    if ($c === false || strlen($c) !== 32) {
        throw new RuntimeException('Clé des secrets illisible (' . $origine . ') : 32 octets attendus, encodés en base64.');
    }
    return $c;
}

function secret_cle_fichier_ecrire(array $cles): void
{
    $fichier = secret_cle_fichier();
    $code = "<?php\n// Clés des secrets de Lasso (lib/secrets.php). Ne pas versionner, ne pas\n"
        . "// perdre : sans elles, les mots de passe des boîtes d'envoi et les n° AVS\n"
        . "// sont illisibles. La première chiffre, toutes déchiffrent.\n"
        . 'return ' . (count($cles) === 1 ? var_export($cles[0], true) : var_export(array_values($cles), true)) . ";\n";
    $tmp = $fichier . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $code) === false || !@rename($tmp, $fichier)) {
        @unlink($tmp);
        throw new RuntimeException('Impossible d\'écrire la clé des secrets : ' . $fichier);
    }
    @chmod($fichier, 0600);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($fichier, true);
    }
}

// Toutes les clés connues, brutes (32 octets), la clé de chiffrement d'abord.
function secret_cles(bool $relire = false): array
{
    static $cles = null;
    if ($cles !== null && !$relire) {
        return $cles;
    }
    $b64 = secret_cles_fichier();
    if (!defined('SECRETS_CLE') && $b64 === []) {
        // Mode « x » : si deux requêtes créent la clé en même temps, une seule
        // écrit, l'autre relit la sienne — jamais deux clés différentes.
        $f = @fopen(secret_cle_fichier(), 'x');
        if ($f !== false) {
            fwrite($f, "<?php\n// Clés des secrets de Lasso (lib/secrets.php). Ne pas versionner, ne pas\n"
                . "// perdre : sans elles, les mots de passe des boîtes d'envoi et les n° AVS\n"
                . "// sont illisibles. La première chiffre, toutes déchiffrent.\n"
                . "return '" . base64_encode(random_bytes(32)) . "';\n");
            fclose($f);
            @chmod(secret_cle_fichier(), 0600);
        }
        $b64 = secret_cles_fichier();
        if ($b64 === []) {
            throw new RuntimeException('Clé des secrets illisible : ' . secret_cle_fichier());
        }
    }
    $liste = [];
    if (defined('SECRETS_CLE')) {
        $liste[] = secret_cle_brute((string) SECRETS_CLE, 'SECRETS_CLE');
    }
    foreach ($b64 as $c) {
        $liste[] = secret_cle_brute($c, secret_cle_fichier());
    }
    return $cles = array_values(array_unique($liste));
}

// La clé qui chiffre.
function secret_cle(): string
{
    return secret_cles()[0];
}

// Les clés à glisser dans une archive chiffrée, en base64.
function secret_cles_export(): array
{
    return array_map('base64_encode', secret_cles());
}

// Ajoute des clés (base64) au fichier — celles qu'apporte une archive
// restaurée. Une clé déjà connue n'est pas réécrite ; la clé de chiffrement
// reste la même. Rend le nombre de clés ajoutées.
function secret_cles_ajouter(array $b64): int
{
    $connues = secret_cles();
    $fichier = secret_cles_fichier();
    $ajout = 0;
    foreach ($b64 as $c) {
        $c = trim((string) $c);
        if ($c === '') {
            continue;
        }
        $brute = secret_cle_brute($c, 'archive');
        if (in_array($brute, $connues, true)) {
            continue;
        }
        $fichier[] = $c;
        $connues[] = $brute;
        $ajout++;
    }
    if ($ajout > 0) {
        secret_cle_fichier_ecrire($fichier);
        secret_cles(true);
    }
    return $ajout;
}

function secret_est_chiffre(string $valeur): bool
{
    return str_starts_with($valeur, SECRET_PREFIXE);
}

// '' reste '' : un champ vide veut dire « pas de mot de passe ».
function secret_chiffrer(string $clair): string
{
    if ($clair === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($clair, 'aes-256-gcm', secret_cle(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('Chiffrement impossible (openssl).');
    }
    return SECRET_PREFIXE . base64_encode($iv . $tag . $ct);
}

// Le clair, ou null si la valeur a été chiffrée avec une clé inconnue (base
// restaurée depuis un autre serveur) ou abîmée. Une valeur non chiffrée est
// rendue telle quelle.
function secret_dechiffrer(string $stocke): ?string
{
    if (!secret_est_chiffre($stocke)) {
        return $stocke;
    }
    $brut = base64_decode(substr($stocke, strlen(SECRET_PREFIXE)), true);
    if ($brut === false || strlen($brut) < 29) {
        return null;
    }
    foreach (secret_cles() as $cle) {
        $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA,
            substr($brut, 0, 12), substr($brut, 12, 16));
        if ($clair !== false) {
            return $clair;
        }
    }
    return null;
}

// Pour l'affichage : une valeur stockée qu'on ne sait plus lire.
function secret_illisible(string $stocke): bool
{
    return $stocke !== '' && secret_dechiffrer($stocke) === null;
}

// ---------------------------------------------------------------------------
//  N° AVS — chiffrés comme les mots de passe, dans employes.numero_avs et
//  dans la copie figée de chaque fiche (fiches.employe_avs).
//
//  ⚠️ Toute écriture passe par avs_stocker(), toute lecture par avs_lire().
//  Conséquence assumée : SQL ne voit plus le numéro. Chercher un employé par
//  son n° AVS se fait en PHP (avs_trouver_employe()), jamais par un WHERE.
//  tests/secrets_test.php vérifie qu'aucune écriture ne passe en clair.
// ---------------------------------------------------------------------------

function avs_stocker(string $clair): string
{
    return secret_chiffrer(trim($clair));
}

// Le numéro en clair ; '' s'il est vide ou illisible (avs_illisible() le dit).
function avs_lire(?string $stocke): string
{
    return (string) secret_dechiffrer((string) $stocke);
}

function avs_illisible(?string $stocke): bool
{
    return secret_illisible((string) $stocke);
}

// Pour l'écran et l'impression : le clair, ou une mention s'il est illisible.
function avs_affiche(?string $stocke): string
{
    return avs_illisible($stocke) ? 'illisible (clé de chiffrement absente)' : avs_lire($stocke);
}

// L'employé dont le n° AVS est celui-ci (espaces et points ignorés), ou null.
function avs_trouver_employe(string $avs): ?int
{
    $cherche = preg_replace('/[\s.]/', '', $avs);
    if ($cherche === '') {
        return null;
    }
    foreach (db()->query("SELECT id, numero_avs FROM employes WHERE numero_avs <> ''") as $r) {
        if (preg_replace('/[\s.]/', '', avs_lire($r['numero_avs'])) === $cherche) {
            return (int) $r['id'];
        }
    }
    return null;
}
