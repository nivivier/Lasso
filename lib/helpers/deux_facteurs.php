<?php
// Validation en deux étapes : un code à six chiffres tiré d'une application
// (TOTP, RFC 6238 — Google Authenticator, Aegis, 1Password…), demandé APRÈS le
// mot de passe. Le pourquoi des choix est dans docs/DECISIONS.md § Validation
// en deux étapes ; ici, les invariantes.
//
// ⚠️ Chargé par lib/helpers.php, jamais requis directement.
//
// Ce qui tient la porte fermée :
//  - entre le mot de passe et le code, la session ne porte PAS 'uid' mais
//    'deux_facteurs' : current_user() rend null, toutes les routes renvoient
//    donc à la connexion. Il n'y a aucune liste de routes « autorisées pendant
//    l'étape » à tenir à jour — c'est ce qui la rend non contournable ;
//  - le secret est chiffré en base (secret_chiffrer(), lib/secrets.php) ;
//  - un pas de temps déjà accepté ne l'est plus jamais (totp_dernier_pas) :
//    un code vu par-dessus l'épaule ne se rejoue pas dans la même minute ;
//  - les codes de secours ne sont en base qu'en empreinte, et ne servent
//    qu'une fois ;
//  - les échecs du code alimentent le même compteur que ceux du mot de passe
//    (login_record_failure()), et un mot de passe juste ne le remet PAS à zéro
//    quand un code est encore attendu : sinon, qui connaît le mot de passe
//    retaperait celui-ci tous les cinq essais et devinerait le code sans fin.

const TOTP_PERIODE   = 30;  // secondes par pas de temps
const TOTP_CHIFFRES  = 6;
const TOTP_TOLERANCE = 1;   // pas acceptés de part et d'autre : ±30 s d'horloge
const DEUX_FACTEURS_NB_CODES_SECOURS = 10;
// Temps laissé pour taper le code après le mot de passe. Au-delà, on
// recommence par le mot de passe : une étape à moitié franchie ne traîne pas
// dans une session oubliée sur un poste partagé.
const DEUX_FACTEURS_ETAPE_DUREE = 300;

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

// --- Base32 (RFC 4648), le format des clés que les applications attendent --

function base32_encoder(string $octets): string
{
    $bits = '';
    foreach (str_split($octets) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 5) as $groupe) {
        if ($groupe === '') {
            continue;
        }
        $sortie .= BASE32_ALPHABET[bindec(str_pad($groupe, 5, '0', STR_PAD_RIGHT))];
    }
    return $sortie; // sans « = » de remplissage : les applications s'en passent
}

// Rend les octets, ou null si le texte n'est pas du base32. Tolère les espaces,
// les tirets, le remplissage et les minuscules — c'est ainsi qu'une clé se
// recopie à la main.
function base32_decoder(string $texte): ?string
{
    $texte = strtoupper((string) preg_replace('/[\s\-=]/', '', $texte));
    if ($texte === '' || strspn($texte, BASE32_ALPHABET) !== strlen($texte)) {
        return null;
    }
    $bits = '';
    foreach (str_split($texte) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $octets = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) {
            $octets .= chr(bindec($octet));
        }
    }
    return $octets;
}

// --- TOTP (RFC 6238, sur HOTP de la RFC 4226) ------------------------------

function totp_pas(int $horodatage): int
{
    return intdiv($horodatage, TOTP_PERIODE);
}

// Le code d'un pas donné. $algo et $chiffres ne servent qu'aux vecteurs de la
// RFC (tests/deux_facteurs_test.php) : l'application emploie SHA-1 et six
// chiffres, les seuls réglages que toutes les applications comprennent.
function totp_code(string $secret, int $pas, int $chiffres = TOTP_CHIFFRES, string $algo = 'sha1'): string
{
    $compteur = pack('J', $pas); // 64 bits, gros-boutiste
    $hmac = hash_hmac($algo, $compteur, $secret, true);
    $decalage = ord($hmac[strlen($hmac) - 1]) & 0x0F;
    $valeur = ((ord($hmac[$decalage]) & 0x7F) << 24)
        | (ord($hmac[$decalage + 1]) << 16)
        | (ord($hmac[$decalage + 2]) << 8)
        | ord($hmac[$decalage + 3]);
    return str_pad((string) ($valeur % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
}

// Le pas que ce code désigne, dans la fenêtre de tolérance et strictement
// après $dernierPas, ou null. Pure : l'horloge est un paramètre.
function totp_verifier(string $secret, string $code, int $dernierPas, int $maintenant): ?int
{
    $code = (string) preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_CHIFFRES . '}$/', $code)) {
        return null;
    }
    $courant = totp_pas($maintenant);
    // Toute la fenêtre est parcourue, sans sortie anticipée : le temps de
    // réponse ne dit pas à quel pas le code a échoué.
    $trouve = null;
    for ($pas = $courant - TOTP_TOLERANCE; $pas <= $courant + TOTP_TOLERANCE; $pas++) {
        if ($pas > $dernierPas && hash_equals(totp_code($secret, $pas), $code) && $trouve === null) {
            $trouve = $pas;
        }
    }
    return $trouve;
}

// Un secret neuf, en base32 : 160 bits, la taille d'une empreinte SHA-1
// (RFC 4226 § 4 la recommande).
function totp_secret_generer(): string
{
    return base32_encoder(random_bytes(20));
}

// L'adresse que lit l'application (QR code ou lien). L'émetteur est le nom de
// l'employeur, la base n'a pas d'autre nom : aucune marque en dur.
function totp_uri(string $secretB32, string $compte, string $emetteur): string
{
    $emetteur = str_replace(':', ' ', trim($emetteur)) ?: 'Lasso';
    return 'otpauth://totp/' . rawurlencode($emetteur) . ':' . rawurlencode($compte)
        . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode($emetteur)
        . '&algorithm=SHA1&digits=' . TOTP_CHIFFRES . '&period=' . TOTP_PERIODE;
}

// Le QR code de cette adresse, en SVG à insérer dans la page. La matrice est
// calculée par tcpdf (vendor/, déjà là pour la QR-facture — dérogation
// étendue à ce seul usage, CLAUDE.md) ; le dessin est fait ici, en un seul
// chemin, pour rester un élément de la page plutôt qu'une image servie à part.
//
// Fond blanc et marge de quatre modules quel que soit le thème : un lecteur
// de QR exige un contraste sombre sur clair, et la marge fait partie de la
// norme. Rend '' si tcpdf manque : la clé en clair suffit à l'activation.
function totp_qr_svg(string $uri): string
{
    $fichier = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    if (!is_file($fichier)) {
        return '';
    }
    require_once $fichier;
    $matrice = (new TCPDF2DBarcode($uri, 'QRCODE,M'))->getBarcodeArray();
    if (!is_array($matrice) || empty($matrice['bcode'])) {
        return '';
    }
    $marge = 4;
    $cote = (int) $matrice['num_cols'] + 2 * $marge;
    $chemin = '';
    foreach ($matrice['bcode'] as $y => $ligne) {
        foreach ($ligne as $x => $module) {
            if ($module) {
                $chemin .= 'M' . ($x + $marge) . ' ' . ($y + $marge) . 'h1v1h-1z';
            }
        }
    }
    return '<svg class="qr-totp" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $cote . ' ' . $cote . '"'
        . ' shape-rendering="crispEdges" role="img" aria-label="QR code à scanner avec l\'application">'
        . '<rect width="' . $cote . '" height="' . $cote . '" fill="#fff"/>'
        . '<path fill="#000" d="' . $chemin . '"/></svg>';
}

// La clé lue par groupes de quatre, pour la recopier à la main.
function totp_secret_affiche(string $secretB32): string
{
    return implode(' ', str_split($secretB32, 4));
}

// --- Le compte ----------------------------------------------------------------

function deux_facteurs_active(array $u): bool
{
    return trim((string) ($u['totp_active_le'] ?? '')) !== '';
}

// Le secret brut d'un compte, ou null (désactivé, ou clé des secrets perdue —
// dans ce cas seul un administrateur peut rouvrir le compte, en réinitialisant).
function deux_facteurs_secret(array $u): ?string
{
    $b32 = secret_dechiffrer((string) ($u['totp_secret'] ?? ''));
    return $b32 === null || $b32 === '' ? null : base32_decoder($b32);
}

// Vérifie un code de l'application et CONSOMME son pas. L'écriture est
// conditionnelle (totp_dernier_pas < ?) : deux requêtes simultanées avec le
// même code n'en font passer qu'une.
function deux_facteurs_verifier_totp(array $u, string $code, ?int $maintenant = null): bool
{
    $secret = deux_facteurs_secret($u);
    if ($secret === null) {
        return false;
    }
    $pas = totp_verifier($secret, $code, (int) ($u['totp_dernier_pas'] ?? 0), $maintenant ?? time());
    if ($pas === null) {
        return false;
    }
    $maj = db()->prepare('UPDATE utilisateurs SET totp_dernier_pas = ? WHERE id = ? AND totp_dernier_pas < ?');
    $maj->execute([$pas, (int) $u['id'], $pas]);
    return $maj->rowCount() === 1;
}

// --- Codes de secours ---------------------------------------------------------
//
// Douze caractères de l'alphabet base32 (60 bits), lus par groupes de quatre.
// En base, l'empreinte SHA-256 seulement, comme le jeton de réinitialisation :
// à 60 bits, l'empreinte d'une copie de la base ne se retourne pas.

function code_secours_normaliser(string $code): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function code_secours_hash(string $code): string
{
    return hash('sha256', code_secours_normaliser($code));
}

// Remplace les codes du compte par une série neuve, et la rend EN CLAIR — la
// seule fois où elle existe ainsi.
function deux_facteurs_codes_generer(int $uid): array
{
    $codes = [];
    for ($i = 0; $i < DEUX_FACTEURS_NB_CODES_SECOURS; $i++) {
        $brut = '';
        for ($j = 0; $j < 12; $j++) {
            $brut .= BASE32_ALPHABET[random_int(0, 31)];
        }
        $codes[] = implode('-', str_split($brut, 4));
    }
    db()->prepare('DELETE FROM utilisateur_codes_secours WHERE utilisateur_id = ?')->execute([$uid]);
    $ins = db()->prepare('INSERT INTO utilisateur_codes_secours (utilisateur_id, code_hash, cree_le) VALUES (?, ?, ?)');
    foreach ($codes as $c) {
        $ins->execute([$uid, code_secours_hash($c), horodatage_maintenant()]);
    }
    return $codes;
}

// Consomme un code de secours. Même écriture conditionnelle que le TOTP.
function deux_facteurs_utiliser_code_secours(int $uid, string $code): bool
{
    if (strlen(code_secours_normaliser($code)) !== 12) {
        return false;
    }
    $maj = db()->prepare('UPDATE utilisateur_codes_secours SET utilise_le = ?
                           WHERE utilisateur_id = ? AND code_hash = ? AND utilise_le IS NULL');
    $maj->execute([horodatage_maintenant(), $uid, code_secours_hash($code)]);
    return $maj->rowCount() === 1;
}

function deux_facteurs_codes_restants(int $uid): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM utilisateur_codes_secours WHERE utilisateur_id = ? AND utilise_le IS NULL');
    $st->execute([$uid]);
    return (int) $st->fetchColumn();
}

// Ce que vaut une saisie : 'totp', 'secours', ou null. Six chiffres = code de
// l'application ; le reste est essayé comme code de secours.
function deux_facteurs_verifier(array $u, string $saisie): ?string
{
    if (!deux_facteurs_active($u)) {
        return null;
    }
    if (preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $saisie)) {
        return deux_facteurs_verifier_totp($u, $saisie) ? 'totp' : null;
    }
    return deux_facteurs_utiliser_code_secours((int) $u['id'], $saisie) ? 'secours' : null;
}

// Active la validation avec ce secret (base32) et rend les codes de secours.
// Le pas qui vient de servir à confirmer est retenu comme déjà consommé.
function deux_facteurs_activer(int $uid, string $secretB32, int $pasConsomme): array
{
    db()->beginTransaction();
    db()->prepare('UPDATE utilisateurs SET totp_secret = ?, totp_active_le = ?, totp_dernier_pas = ? WHERE id = ?')
        ->execute([secret_chiffrer($secretB32), horodatage_maintenant(), $pasConsomme, $uid]);
    $codes = deux_facteurs_codes_generer($uid);
    db()->commit();
    return $codes;
}

// Désactive (par le titulaire, ou réinitialisation par un administrateur) :
// le secret et les codes de secours disparaissent, rien n'en reste.
function deux_facteurs_desactiver(int $uid): void
{
    db()->prepare("UPDATE utilisateurs SET totp_secret = '', totp_active_le = '', totp_dernier_pas = 0 WHERE id = ?")
        ->execute([$uid]);
    db()->prepare('DELETE FROM utilisateur_codes_secours WHERE utilisateur_id = ?')->execute([$uid]);
}

// --- Ouvrir la session --------------------------------------------------------

// La session pleinement ouverte : le SEUL endroit, avec l'installation, qui
// pose 'uid'. Appelé après le mot de passe (compte sans validation en deux
// étapes) ou après le code.
function connexion_ouvrir(array $u): void
{
    // Horodaté par PHP, en UTC (lib/horodatage.php).
    db()->prepare('UPDATE utilisateurs SET derniere_connexion_le = ? WHERE id = ?')
        ->execute([horodatage_maintenant(), (int) $u['id']]);
    session_regenerate_id(true);
    unset($_SESSION['deux_facteurs']);
    $_SESSION['uid']           = (int) $u['id'];
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();
}

// Le mot de passe est juste, le code reste à venir : la session ne retient que
// le compte en attente, pas 'uid'.
function connexion_attendre_code(array $u): void
{
    session_regenerate_id(true);
    unset($_SESSION['uid']);
    $_SESSION['deux_facteurs'] = ['uid' => (int) $u['id'], 'depuis' => time()];
}

// Le compte qui attend son code, ou null (aucune étape en cours, ou délai
// dépassé — l'étape est alors effacée).
function connexion_en_attente(): ?array
{
    $attente = $_SESSION['deux_facteurs'] ?? null;
    if (!is_array($attente) || empty($attente['uid'])) {
        return null;
    }
    if (time() - (int) ($attente['depuis'] ?? 0) > DEUX_FACTEURS_ETAPE_DUREE) {
        unset($_SESSION['deux_facteurs']);
        return null;
    }
    $st = db()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $st->execute([(int) $attente['uid']]);
    $u = $st->fetch();
    // Désactivée entre-temps (réinitialisation par un administrateur) : on
    // recommence par le mot de passe plutôt que d'ouvrir sans code.
    if (!$u || !deux_facteurs_active($u)) {
        unset($_SESSION['deux_facteurs']);
        return null;
    }
    return $u;
}
