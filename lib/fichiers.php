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
// Ce qu'un navigateur sait AFFICHER lui-même, et que l'on accepte donc de
// servir en ligne. Volontairement plus court que FICHIERS_PRIVES_MIMES : le
// HEIC n'est lu que par Safari, et un .docx ne s'ouvre pas sans son logiciel —
// un aperçu qui tombe en panne une fois sur deux vaut moins que pas d'aperçu.
const FICHIERS_PRIVES_APERCU = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                                'text/plain', 'text/csv'];

function fichier_prive_apercu_possible(string $mime): bool
{
    return in_array($mime, FICHIERS_PRIVES_APERCU, true);
}

// Envoie un fichier privé. Deux dispositions, et la seconde est gardée :
//
//   $enLigne = false (défaut) — « attachment ». Le téléchargement, point.
//   $enLigne = true           — « inline », pour qu'un <img> ou un <iframe>
//                               l'affiche sur la page (aperçu d'un justificatif).
//
// Servir en ligne, c'est rendre un document DANS l'origine de l'application —
// ce que ce fichier refusait jusqu'ici, et pour une bonne raison : un PDF peut
// porter du script, et il aurait alors la session sous la main. Trois verrous
// lèvent l'objection, et il faut les trois :
//
//   1. le type est reconnu par le CONTENU à l'enregistrement (finfo) et redit
//      ici en clair, avec nosniff : un fichier HTML déguisé ne peut pas être
//      interprété comme du HTML ;
//   2. la liste ci-dessus n'admet que ce que le navigateur rend lui-même ;
//   3. « Content-Security-Policy: sandbox » met le document dans une origine
//      OPAQUE, sans script : il ne peut plus ni lire le cookie de session, ni
//      parler à la page qui l'encadre. C'est ce que fait un hébergeur sérieux
//      avec un sous-domaine séparé, en une en-tête plutôt qu'en un domaine.
//
// ⚠️ SAUF pour le PDF, et c'est mesuré, pas supposé : sous cette en-tête,
// Chrome affiche l'habillage de sa visionneuse mais ne peint JAMAIS la page —
// un cadre gris, sans message —, et en navigation directe il propose le
// téléchargement au lieu d'ouvrir. Un PDF part donc en ligne sans elle. Ce qui
// le protège alors : les deux verrous ci-dessus, plus le fait que le PDF n'est
// pas rendu par la page mais par la visionneuse du navigateur, dans son propre
// processus — le script éventuellement embarqué dans un PDF n'y atteint ni le
// DOM de l'application ni son cookie.
//
// Un type hors liste retombe en « attachment » même si l'appelant demande
// l'aperçu : la décision se prend ICI, pas dans la route.
function fichier_prive_envoyer(string $chemin, string $mime, string $nomOrigine, bool $enLigne = false): never
{
    $enLigne = $enLigne && fichier_prive_apercu_possible($mime);
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header(($enLigne ? 'Content-Disposition: inline; filename="' : 'Content-Disposition: attachment; filename="')
        . fichier_nom_origine_propre($nomOrigine) . '"');
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($enLigne && $mime !== 'application/pdf') {
        header('Content-Security-Policy: sandbox');
    }
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
