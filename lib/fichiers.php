<?php
// Fichiers privés téléversés — le stockage, commun à tous les modules.
//
// Ces fichiers vivent sous data/, et NON dans uploads/ comme les logos. C'est
// toute la différence — uploads/ est délibérément servi par le serveur web,
// data/ est délibérément refusé (« Require all denied » dans data/.htaccess, et
// « RedirectMatch 404 ^/data/ » dans le .htaccess racine, qui ne dépend d'aucun
// fichier du dossier lui-même). Une réservation d'hôtel, un contrat, une
// facture reçue portent des noms, des numéros de chambre et des montants : ils
// ne sont servis que par une route qui vérifie qui demande.
//
// ⚠️ CE FICHIER NE DÉCIDE PAS QUI A LE DROIT DE LIRE. Le stockage et la
// validation sont communs ; l'autorisation ne l'est pas, parce qu'elle dépend
// du module — la feuille de route accepte le jeton du calendrier de l'équipe,
// les pièces comptables n'acceptent qu'une session. Chaque module garde donc sa
// route de service, et c'est elle qui tranche. Mettre ici une fonction
// « servir ce fichier » aurait fait croire l'inverse.
//
// ⚠️ En développement (php -S), .htaccess est ignoré : tout le dossier data/ est
// alors lisible, base comprise. C'est déjà le cas sans ces fichiers, et ce n'est
// vrai que là.

function fichiers_prives_dir(): string
{
    return dirname(__DIR__) . '/data/fichiers';
}

// Ce qu'on accepte : extension servie => type MIME réel attendu. La
// vérification porte sur le CONTENU (finfo), jamais sur le nom du fichier.
// Assez large pour couvrir ce qu'un organisateur envoie et ce qu'un fournisseur
// facture — un PDF le plus souvent, parfois un scan, parfois un document
// bureautique.
const FICHIERS_PRIVES_MIMES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'text/plain'      => 'txt',
    'text/csv'        => 'csv',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
    'application/vnd.oasis.opendocument.text'        => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
];

// 8 Mo : une fiche technique avec ses plans de scène dépasse largement les 2 Mo
// tolérés pour un logo.
const FICHIER_PRIVE_MAX = 8388608;

// Enregistre le fichier téléversé sous $champ et retourne [fichier, nom_origine,
// mime, taille], ou null si aucun fichier n'a été envoyé. Lève une
// RuntimeException avec un message affichable en cas de refus.
//
// Le type est déduit du CONTENU (finfo) et non de l'extension envoyée : c'est le
// nom du fichier qui ment, jamais ses premiers octets. Le nom stocké est tiré au
// sort — deux organisateurs envoient « fiche technique.pdf ».
//
// $prefixe nomme le module propriétaire (« feuille », « piece ») : il ne sert
// qu'à s'y retrouver dans le dossier, pas à désigner le fichier.
function fichier_prive_enregistrer(string $champ, string $prefixe): ?array
{
    $f = $_FILES[$champ] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > FICHIER_PRIVE_MAX) {
        throw new RuntimeException('Fichier trop lourd (8 Mo maximum).');
    }
    $mime = (string) @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
    if (!isset(FICHIERS_PRIVES_MIMES[$mime])) {
        throw new RuntimeException('Format non accepté : PDF, image, texte ou document bureautique.');
    }
    $dir = fichiers_prives_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier des pièces jointes.");
    }
    $stocke = $prefixe . '_' . bin2hex(random_bytes(8)) . '.' . FICHIERS_PRIVES_MIMES[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stocke)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $stocke, 0644);
    return [
        'fichier'     => $stocke,
        'nom_origine' => fichier_nom_origine_propre((string) $f['name']),
        'mime'        => $mime,
        'taille'      => (int) $f['size'],
    ];
}

// Nom d'origine réduit à ce qui s'affiche sans danger : pas de chemin, pas de
// caractère de contrôle, 120 caractères au plus. Il ne sert qu'à l'affichage et
// au téléchargement — le fichier sur le disque porte un nom tiré au sort.
function fichier_nom_origine_propre(string $nom): string
{
    $nom = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename($nom));
    $nom = trim(str_replace(['"', '\\'], '', $nom));
    return mb_substr($nom !== '' ? $nom : 'document', 0, 120, 'UTF-8');
}

// Efface un fichier stocké. basename() et rien d'autre : le nom vient de la
// base, mais un chemin qui s'y serait glissé ne doit pas sortir du dossier.
function fichier_prive_supprimer(string $fichier): void
{
    $fichier = basename(trim($fichier));
    if ($fichier !== '') {
        @unlink(fichiers_prives_dir() . '/' . $fichier);
    }
}

// Pose les en-têtes et envoie le fichier. L'APPELANT a déjà vérifié les droits —
// cette fonction ne fait que l'envoi, et c'est pour ça qu'elle ne s'appelle pas
// « servir ».
//
// Content-Disposition: attachment, toujours : un PDF ou une image ouverts dans
// l'onglet s'exécuteraient dans l'origine de l'application.
function fichier_prive_envoyer(string $chemin, string $mime, string $nomOrigine): never
{
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . fichier_nom_origine_propre($nomOrigine) . '"');
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($chemin);
    exit;
}

// Taille lisible — « 240 Ko », « 1,8 Mo ».
function fichier_taille_lisible(int $octets): string
{
    if ($octets >= 1048576) {
        return str_replace('.', ',', (string) round($octets / 1048576, 1)) . ' Mo';
    }
    return max(1, (int) round($octets / 1024)) . ' Ko';
}
