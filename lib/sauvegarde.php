<?php
// Sauvegarde complète : la base ET les fichiers déposés, dans une seule
// archive.
//
// La base seule ne suffit pas. Elle ne mémorise que l'EMPLACEMENT des fichiers
// — logos de l'employeur, photos d'employés, icônes de projet, feuilles
// SUISA, pièces jointes des feuilles de route — dont le contenu vit sur le
// disque. Restaurer la base seule rendait donc une application aux images
// cassées et aux pièces jointes introuvables, et la page d'export en
// avertissait au lieu d'y remédier : « sauvegarde aussi le dossier uploads/ ».
// C'est le genre de consigne dont on se souvient le jour où il est trop tard.
//
// L'archive contient :
//   base.sqlite       instantané cohérent de la base (VACUUM INTO)
//   uploads/…         fichiers servis par le web (logos, photos, icônes, PDF)
//   data/fichiers/…   fichiers servis par une route authentifiée
//   SAUVEGARDE.txt    ce que contient l'archive et comment la restaurer
//
// Les chemins dans l'archive reproduisent ceux du dépôt : restaurer, c'est
// décompresser par-dessus, la base mise là où pointe APP_DB_PATH.

// horodatage_maintenant(), horodatage_ts() : ce fichier sert aussi sans
// helpers.php (scripts, tests, relevé d'erreur précoce).
require_once __DIR__ . '/horodatage.php';
require_once __DIR__ . '/secrets.php'; // la clé, glissée dans une archive chiffrée

// Dossiers de fichiers déposés : chemin réel => préfixe dans l'archive.
// Un dossier absent est simplement ignoré (data/fichiers/ n'existe pas tant
// qu'aucune pièce jointe n'a été déposée).
function sauvegarde_dossiers(): array
{
    return [
        realpath(__DIR__ . '/..') . '/uploads'       => 'uploads',
        realpath(__DIR__ . '/..') . '/data/fichiers' => 'data/fichiers',
    ];
}

// Fichiers à embarquer, [chemin absolu => chemin dans l'archive], triés par
// chemin d'archive pour que deux sauvegardes du même état se ressemblent.
//
// Exclusions : les fichiers cachés (.htaccess est recréé par le dépôt, .DS_Store
// n'a rien à faire là) et les liens symboliques, qu'une archive suivrait hors
// du dossier. Rien d'autre n'est filtré : tout ce qu'un utilisateur a déposé
// doit revenir.
function sauvegarde_fichiers(array $dossiers): array
{
    $out = [];
    foreach ($dossiers as $reel => $prefixe) {
        if (!is_dir($reel)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($reel, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile() || $f->isLink() || str_starts_with($f->getFilename(), '.')) {
                continue;
            }
            $relatif = str_replace('\\', '/', substr($f->getPathname(), strlen($reel) + 1));
            $out[$f->getPathname()] = $prefixe . '/' . $relatif;
        }
    }
    asort($out);
    return $out;
}

// Format d'archive que sait produire CE serveur. Les hébergements mutualisés
// n'ont pas tous l'extension zip ; phar, lui, est là par défaut (et PharData
// s'écrit même quand phar.readonly est actif, contrairement à Phar). Si aucun
// des deux n'est disponible, on retombe sur la base seule — une sauvegarde
// incomplète vaut mieux que pas de sauvegarde, à condition de le dire, ce dont
// se charge la page d'export.
function sauvegarde_format(): string
{
    if (class_exists('ZipArchive')) {
        return 'zip';
    }
    if (class_exists('PharData')) {
        return 'tar.gz';
    }
    return 'sqlite';
}

// Note de restauration glissée dans l'archive. Une sauvegarde qui explique
// comment s'y prendre reste lisible le jour où on l'ouvre — des mois plus tard,
// sur une autre machine, sans le README sous la main.
function sauvegarde_note(int $nbFichiers, string $version, bool $chiffree = false): string
{
    return implode("\n", [
        'Sauvegarde Lasso ' . $version,
        'Créée le ' . date('d.m.Y à H:i'),
        '',
        'Contenu',
        '  base.sqlite      toute la base de données',
        '  uploads/         ' . 'logos, photos, icônes, PDF (servis par le web)',
        '  data/fichiers/   pièces jointes (servies par une route authentifiée)',
        '  — ' . $nbFichiers . ' fichier(s) déposé(s) au total.',
        '',
        'Restaurer',
        '  1. Mettre l\'application hors ligne (personne ne doit écrire pendant l\'opération).',
        '  2. Copier base.sqlite à l\'emplacement défini par APP_DB_PATH',
        '     (lib/config.local.php ; par défaut data/database.sqlite), en',
        '     supprimant les fichiers -wal et -shm qui l\'accompagnent.',
        '  3. Décompresser uploads/ et data/fichiers/ à la racine du projet,',
        '     en écrasant ce qui s\'y trouve.',
        '  4. Rouvrir l\'application : les migrations éventuelles se jouent seules.',
        '',
        'La configuration du serveur (lib/config.local.php) n\'est PAS dans cette',
        'archive : elle contient des mots de passe et se conserve à part.',
        '',
        'Les mots de passe des boîtes d\'envoi et les n° AVS sont chiffrés dans la',
        'base, avec une clé propre au serveur (fichier « .cle.php » à côté de la',
        'base).',
    ]) . "\n" . ($chiffree ? implode("\n", [
        'Cette archive, protégée par une phrase secrète, contient aussi cette clé',
        '(CLE_SECRETS.txt) : restaurée par Lasso sur n\'importe quel serveur, tout',
        'reste lisible. À la main, ajouter le contenu de CLE_SECRETS.txt au fichier',
        '« .cle.php » de la base de destination.',
    ]) : implode("\n", [
        'Cette clé n\'est PAS dans l\'archive (elle n\'est pas protégée par une',
        'phrase secrète). Restaurée sur un autre serveur, la base rend les n° AVS',
        'et les mots de passe d\'envoi illisibles : à ressaisir.',
    ])) . ($chiffree ? implode("\n", [
        '',
        '',
        'Archive protégée par une phrase secrète (chiffrement AES-256) : base.sqlite',
        'et les fichiers déposés ne s\'ouvrent qu\'avec elle. Lasso la demande à la',
        'restauration ; pour ouvrir l\'archive à la main, il faut un outil qui lit',
        'le ZIP chiffré en AES (7-Zip, Keka, WinRAR…) — l\'utilitaire intégré de',
        'macOS ou de Windows n\'y parvient pas. Sans la phrase, rien n\'est récupérable.',
    ]) : '') . "\n";
}

// L'entrée qui porte la clé des secrets, dans une archive chiffrée seulement.
const SAUVEGARDE_CLES = 'CLE_SECRETS.txt';

// Longueur minimale d'une phrase secrète de sauvegarde.
const SAUVEGARDE_PHRASE_MIN = 10;

// Le serveur sait-il chiffrer une archive ? ZIP seulement, et seulement si
// libzip a été compilée avec le chiffrement AES : un .tar.gz ou une base seule
// n'ont pas d'équivalent qu'un outil courant sache rouvrir.
function sauvegarde_chiffrement_possible(?string $format = null): bool
{
    return ($format ?? sauvegarde_format()) === 'zip'
        && defined('ZipArchive::EM_AES_256')
        && method_exists('ZipArchive', 'isEncryptionMethodSupported')
        && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256);
}

// Construit l'archive et retourne [chemin temporaire, nom de téléchargement].
// À l'appelant de lire puis de supprimer le fichier.
//
// $format force le format au lieu de prendre celui que sait produire ce serveur
// — les trois chemins se vérifient ainsi sur une machine qui les a tous, alors
// qu'en production un seul est atteignable.
// $phrase : si elle est donnée, base.sqlite et chaque fichier déposé sont
// chiffrés en AES-256 (ZIP seulement). La note SAUVEGARDE.txt reste lisible :
// c'est elle qui dit ce qu'est ce fichier et comment l'ouvrir. La phrase n'est
// conservée nulle part.
function sauvegarde_construire(string $slug, string $version, ?string $format = null, string $phrase = ''): array
{
    $format = $format ?? sauvegarde_format();
    if ($phrase !== '' && !sauvegarde_chiffrement_possible($format)) {
        throw new RuntimeException("Ce serveur ne sait pas chiffrer une archive de sauvegarde.");
    }
    $horo   = date('Y-m-d_His');

    // Instantané cohérent de la base, indépendant du WAL.
    $base = tempnam(sys_get_temp_dir(), 'bk_') . '.sqlite';
    @unlink($base);
    db()->exec('VACUUM INTO ' . db()->quote($base));

    if ($format === 'sqlite') {
        return [$base, $slug . '_' . $horo . '.sqlite'];
    }

    $fichiers = sauvegarde_fichiers(sauvegarde_dossiers());
    $note     = sauvegarde_note(count($fichiers), $version, $phrase !== '');
    $nom      = $slug . '_' . $horo . '.' . $format;
    $archive  = tempnam(sys_get_temp_dir(), 'bk_') . '.' . $format;
    @unlink($archive);

    try {
        if ($format === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Impossible de créer l'archive de sauvegarde.");
            }
            $zip->addFile($base, 'base.sqlite');
            $zip->addFromString('SAUVEGARDE.txt', $note);
            foreach ($fichiers as $reel => $dans) {
                $zip->addFile($reel, $dans);
            }
            if ($phrase !== '') {
                // La clé des secrets voyage avec la base, chiffrée comme elle
                // par la phrase : sans quoi une restauration sur un autre
                // serveur rendrait les n° AVS illisibles (lib/secrets.php).
                $zip->addFromString(SAUVEGARDE_CLES, implode("\n", secret_cles_export()) . "\n");
                foreach (array_merge(['base.sqlite', SAUVEGARDE_CLES], array_values($fichiers)) as $dans) {
                    if (!$zip->setEncryptionName($dans, ZipArchive::EM_AES_256, $phrase)) {
                        throw new RuntimeException("Le chiffrement de l'archive a échoué.");
                    }
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException("L'archive de sauvegarde n'a pas pu être finalisée.");
            }
        } else {
            // PharData écrit d'abord un .tar, puis le compresse en .tar.gz à
            // côté : les deux fichiers sont à nettoyer, pas seulement celui
            // qu'on renvoie.
            $tar = substr($archive, 0, -3); // …tar.gz → …tar
            @unlink($tar);
            $phar = new PharData($tar);
            $phar->addFile($base, 'base.sqlite');
            $phar->addFromString('SAUVEGARDE.txt', $note);
            foreach ($fichiers as $reel => $dans) {
                $phar->addFile($reel, $dans);
            }
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($tar);
        }
    } finally {
        @unlink($base);
    }

    return [$archive, $nom];
}

// ---------------------------------------------------------------------------
//  RESTAURER — l'opération inverse, et la plus destructrice de l'application.
//
//  La note glissée dans l'archive (sauvegarde_note()) décrit la manœuvre à la
//  main : poser base.sqlite là où pointe APP_DB_PATH, supprimer ses -wal/-shm,
//  décompresser uploads/ et data/fichiers/ par-dessus. C'est faisable par FTP ;
//  ça suppose un accès au serveur, et de ne pas se tromper d'étape le jour où
//  l'on restaure, c'est-à-dire le mauvais jour. Ces fonctions font la même
//  chose depuis l'application.
//
//  Trois garde-fous, dans cet ordre :
//    1. on ne REMPLACE rien avant d'avoir VALIDÉ l'archive (format reconnu au
//       contenu, base intègre, tables de l'application présentes) — une
//       archive douteuse échoue sans avoir touché à l'existant ;
//    2. la base actuelle est SAUVEGARDÉE juste avant d'être remplacée, et son
//       chemin est rendu à l'appelant : se tromper de fichier reste rattrapable ;
//    3. les fichiers déposés sont AJOUTÉS, jamais le dossier vidé d'abord. Une
//       archive sans uploads/ (le repli « base seule » des serveurs sans zip)
//       ne doit pas effacer les logos.
// ---------------------------------------------------------------------------

// Format d'une archive reconnu à ses premiers octets, jamais à son nom : un
// fichier renommé ment, ses octets non. '' si ce n'est aucun des trois.
function sauvegarde_archive_format(string $chemin): string
{
    $tete = (string) @file_get_contents($chemin, false, null, 0, 16);
    if (str_starts_with($tete, "SQLite format 3\0")) {
        return 'sqlite';
    }
    if (str_starts_with($tete, "PK\x03\x04") || str_starts_with($tete, "PK\x05\x06")) {
        return 'zip';
    }
    if (str_starts_with($tete, "\x1f\x8b")) {
        return 'tar.gz';
    }
    return '';
}

// Une base restaurable est-elle bien une base de CETTE application ? On ne
// vérifie pas le schéma entier (une sauvegarde ancienne a le droit d'être en
// retard — les migrations la rattraperont), mais les deux tables sans
// lesquelles plus rien ne s'ouvre. Renvoie '' si tout va bien, sinon le motif
// du refus, affichable tel quel.
function sauvegarde_base_refus(string $chemin): string
{
    try {
        $pdo = new PDO('sqlite:' . $chemin, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((string) $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            return 'La base contenue dans cette sauvegarde est corrompue.';
        }
        $tables = $pdo->query('SELECT name FROM sqlite_master WHERE type = "table"')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['parametres', 'utilisateurs'] as $attendue) {
            if (!in_array($attendue, $tables, true)) {
                return 'Ce fichier est une base SQLite, mais pas une base Lasso (table « ' . $attendue . ' » absente).';
            }
        }
    } catch (\Throwable $e) {
        return "Ce fichier n'est pas une base SQLite lisible.";
    }
    return '';
}

// Chemin de destination d'une entrée d'archive, ou null si elle n'a rien à
// faire là. ⚠️ C'est LE contrôle qui empêche une archive fabriquée d'écrire
// où elle veut : on n'accepte que les deux préfixes qu'on a nous-mêmes écrits,
// et aucun segment « .. ». Une archive est un fichier reçu, pas une consigne.
function sauvegarde_cible_fichier(string $entree, string $racine): ?string
{
    $entree = str_replace('\\', '/', $entree);
    if ($entree === '' || str_starts_with($entree, '/') || str_ends_with($entree, '/')) {
        return null;
    }
    if (in_array('..', explode('/', $entree), true)) {
        return null;
    }
    if (!str_starts_with($entree, 'uploads/') && !str_starts_with($entree, 'data/fichiers/')) {
        return null;
    }
    return $racine . '/' . $entree;
}

// Restaure une sauvegarde. Lève une RuntimeException au message affichable si
// l'archive est refusée — et dans ce cas RIEN n'a été touché.
//
// Renvoie [nbFichiers, sauvegardePrecedente, versionBase] : ce qu'il faut pour
// dire à l'utilisateur ce qui s'est passé, et où retrouver l'état d'avant.
// $racine : où remettre les fichiers déposés. Le dépôt par défaut — le seul
// usage en production. Le paramètre n'existe que pour les tests, qui ne
// doivent pas écrire dans uploads/ ni data/fichiers/ du projet pour vérifier
// qu'une restauration remet bien les fichiers en place.
function sauvegarde_restaurer(string $chemin, ?string $racine = null, string $phrase = ''): array
{
    $racine = $racine ?? realpath(__DIR__ . '/..');
    $format = sauvegarde_archive_format($chemin);
    if ($format === '') {
        throw new RuntimeException("Fichier non reconnu : attendu une sauvegarde Lasso (.zip, .tar.gz ou .sqlite).");
    }

    // --- Étape 1 : extraire et valider, sans rien remplacer -----------------
    $travail = sys_get_temp_dir() . '/lasso_restaure_' . bin2hex(random_bytes(6));
    mkdir($travail, 0770, true);
    $menage = function () use ($travail): void {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($travail, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($travail);
    };

    try {
        if ($format === 'sqlite') {
            $base = $travail . '/base.sqlite';
            copy($chemin, $base);
            $fichiers = [];
            $cles = [];
        } else {
            [$base, $fichiers, $cles] = sauvegarde_extraire($chemin, $format, $travail, $racine, $phrase);
        }

        $refus = sauvegarde_base_refus($base);
        if ($refus !== '') {
            throw new RuntimeException($refus);
        }

        // --- Étape 2 : filet de sécurité, puis remplacement ------------------
        // La base ACTUELLE part dans un .bak avant tout : c'est ce qui rend
        // l'opération rattrapable, et c'est pour cela qu'elle vient ici et non
        // après. wal_checkpoint d'abord, sinon le journal de la base sortante
        // resterait à côté et SQLite le rejouerait par-dessus la nouvelle.
        // ⚠️ L'étiquette porte un suffixe tiré au sort : sauvegarder_base()
        // nomme le fichier avec la seconde courante, et son VACUUM INTO échoue
        // (en rendant null) si ce nom existe déjà. Deux restaurations dans la
        // même seconde laisseraient alors la seconde sans filet, en silence.
        $precedente = sauvegarder_base('avant_restauration_' . bin2hex(random_bytes(3)));
        if ($precedente === null) {
            throw new RuntimeException("La base actuelle n'a pas pu être mise de côté — "
                . "rien n'a été remplacé. Vérifiez l'espace disque et les droits du dossier de données.");
        }
        try {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $e) {
            // Une base verrouillée par une autre requête : le rename reste sûr,
            // ce sont les -wal/-shm supprimés juste après qui comptent.
        }

        // rename() plutôt que copy() : le remplacement est atomique, et la
        // connexion déjà ouverte garde l'ancien fichier sous la main au lieu de
        // lire une base à moitié écrite.
        $cible = APP_DB_PATH;
        if (!@rename($base, $cible)) {
            if (!@copy($base, $cible)) {
                throw new RuntimeException("La base n'a pas pu être écrite à l'emplacement de l'application.");
            }
        }
        foreach (['-wal', '-shm'] as $suffixe) {
            @unlink($cible . $suffixe);
        }

        // --- Étape 3 : les fichiers déposés, ajoutés et non substitués -------
        $nbFichiers = 0;
        foreach ($fichiers as $source => $destination) {
            $dossier = dirname($destination);
            if (!is_dir($dossier) && !@mkdir($dossier, 0770, true)) {
                continue;
            }
            if (@copy($source, $destination)) {
                $nbFichiers++;
            }
        }

        // --- Étape 3 bis : la clé des secrets qu'apporte l'archive ----------
        // AVANT les migrations : une base d'avant le chiffrement des n° AVS
        // les chiffre en rattrapant son schéma, avec la clé de ce serveur ;
        // une base d'après doit pouvoir être relue. La clé s'AJOUTE, jamais ne
        // remplace : la copie « avant restauration » reste lisible.
        if ($cles) {
            secret_cles_ajouter($cles);
        }

        // --- Étape 4 : rattraper le schéma -----------------------------------
        // Une sauvegarde peut venir d'une version plus ancienne. init_schema()
        // joue les migrations manquantes — ici, tout de suite, sur une
        // connexion neuve : si elles échouent, on le dit pendant qu'on peut
        // encore, plutôt que de le découvrir à la reconnexion.
        $neuve = new PDO('sqlite:' . $cible, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $neuve->exec('PRAGMA foreign_keys = ON');
        init_schema($neuve);
        $version = (int) $neuve->query('PRAGMA user_version')->fetchColumn();
        $neuve = null;

        return ['fichiers' => $nbFichiers, 'precedente' => $precedente, 'version' => $version];
    } finally {
        $menage();
    }
}

// Extrait une archive dans $travail. Renvoie [chemin de base.sqlite,
// [source extraite => destination dans le dépôt], clés des secrets (base64)].
function sauvegarde_extraire(string $chemin, string $format, string $travail, string $racine, string $phrase = ''): array
{
    $base = '';
    $fichiers = [];
    $cles = [];

    if ($format === 'zip') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("Ce serveur ne sait pas ouvrir une archive ZIP (extension « zip » absente).");
        }
        $zip = new ZipArchive();
        if ($zip->open($chemin) !== true) {
            throw new RuntimeException("L'archive ZIP est illisible.");
        }
        // Archive chiffrée (sauvegarde_construire() avec une phrase) : sans la
        // bonne phrase, getFromIndex() rend false — on le dit au lieu de
        // laisser croire que l'archive est abîmée.
        $chiffree = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $chiffree = $chiffree || (int) (($zip->statIndex($i)['encryption_method'] ?? 0)) !== 0;
        }
        if ($chiffree) {
            if ($phrase === '') {
                throw new RuntimeException("Cette sauvegarde est protégée par une phrase secrète : saisissez-la pour la restaurer.");
            }
            $zip->setPassword($phrase);
        }
        $lire = function (int $i) use ($zip, $chiffree): string {
            $contenu = $zip->getFromIndex($i);
            if ($contenu === false) {
                throw new RuntimeException($chiffree
                    ? "Phrase secrète incorrecte : l'archive ne s'ouvre pas."
                    : "L'archive ZIP est illisible.");
            }
            return $contenu;
        };
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = (string) $zip->getNameIndex($i);
            if ($nom === 'base.sqlite') {
                $base = $travail . '/base.sqlite';
                file_put_contents($base, $lire($i));
                continue;
            }
            if ($nom === SAUVEGARDE_CLES) {
                // N'est lue que dans une archive chiffrée : c'est la seule
                // que sauvegarde_construire() en dote.
                if ($chiffree) {
                    $cles = array_values(array_filter(preg_split('/\s+/', trim($lire($i))) ?: [],
                        fn ($c) => strlen((string) base64_decode($c, true)) === 32));
                }
                continue;
            }
            $cible = sauvegarde_cible_fichier($nom, $racine);
            if ($cible === null) {
                continue;
            }
            $extrait = $travail . '/f' . $i;
            file_put_contents($extrait, $lire($i));
            $fichiers[$extrait] = $cible;
        }
        $zip->close();
    } else {
        if (!class_exists('PharData')) {
            throw new RuntimeException("Ce serveur ne sait pas ouvrir une archive TAR (extension « phar » absente).");
        }
        // PharData exige une extension connue : on recopie sous .tar.gz.
        $copie = $travail . '/archive.tar.gz';
        copy($chemin, $copie);
        // extractTo() dans un dossier JETABLE, puis on parcourt le disque — et
        // non l'itérateur du Phar, dont les chemins « phar://… » ne se
        // retaillent pas de façon fiable (le chemin réel peut être /private/tmp
        // là où on a ouvert /tmp, et l'entrée se retrouve alors sans son
        // préfixe de dossier). Ce qui sort de l'archive reste de toute façon
        // confiné ici : c'est sauvegarde_cible_fichier() qui décide, ensuite,
        // de ce qui a le droit d'entrer dans le dépôt.
        $sorti = $travail . '/x';
        mkdir($sorti, 0770, true);
        $phar = new PharData($copie);
        $phar->extractTo($sorti, null, true);
        $phar = null;

        $racineSortie = realpath($sorti);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sorti, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $reel = realpath($f->getPathname());
            if ($reel === false || !str_starts_with($reel, $racineSortie . '/')) {
                continue; // ne peut pas arriver après extractTo, mais ne se suppose pas
            }
            $nom = substr($reel, strlen($racineSortie) + 1);
            if ($nom === 'base.sqlite') {
                $base = $reel;
                continue;
            }
            if ($nom === SAUVEGARDE_CLES) {
                continue; // jamais dans un .tar.gz, qui ne se chiffre pas
            }
            $cible = sauvegarde_cible_fichier($nom, $racine);
            if ($cible !== null) {
                $fichiers[$reel] = $cible;
            }
        }
    }

    if ($base === '') {
        throw new RuntimeException("Cette archive ne contient pas de base.sqlite : ce n'est pas une sauvegarde Lasso.");
    }
    return [$base, $fichiers, $cles];
}

// ---------------------------------------------------------------------------
//  COPIES SUR LE SERVEUR — celles de sauvegarder_base(), à côté de la base.
//
//  Posées d'elles-mêmes avant une opération risquée (import, recalcul, mise à
//  jour, clôture…), ou à la demande depuis ?p=sauvegardes. Plafonnées par
//  SAUVEGARDES_AUTO_MAX (sauvegardes_auto_purger()). On les liste, on les
//  supprime, et on en restaure une : c'est un .sqlite, que
//  sauvegarde_restaurer() sait reprendre comme un fichier téléversé — même
//  vérification, même mise de côté de la base actuelle. Les fichiers déposés
//  n'y sont pas : la restauration d'une copie ne remplace que la base.
// ---------------------------------------------------------------------------

// [['nom', 'taille', 'date' (timestamp), 'libelle'], …], la plus récente d'abord.
function sauvegardes_serveur(): array
{
    $prefixe = basename(APP_DB_PATH, '.sqlite') . '_';
    $liste = [];
    foreach (glob(sauvegardes_auto_motif()) ?: [] as $chemin) {
        $nom = basename($chemin);
        $etiquette = preg_match('/^' . preg_quote($prefixe, '/') . '(.+)_\d{8}_\d{6}\.sqlite\.bak$/', $nom, $m) ? $m[1] : '';
        $liste[] = ['nom' => $nom, 'taille' => (int) @filesize($chemin), 'date' => (int) @filemtime($chemin),
            'libelle' => sauvegarde_serveur_libelle($etiquette)];
    }
    usort($liste, fn ($a, $b) => $b['date'] <=> $a['date'] ?: strcmp($b['nom'], $a['nom']));
    return $liste;
}

// « avant_recalcul » → « Avant le recalcul des fiches ». Les étiquettes
// viennent des appels à sauvegarder_base() ; une étiquette inconnue (script
// ponctuel) se lit telle quelle, soulignés en espaces.
function sauvegarde_serveur_libelle(string $etiquette): string
{
    $connues = [
        'manuelle'           => 'Sauvegarde manuelle',
        'maj'                => 'Avant une mise à jour',
        'avant_recalcul'     => 'Avant le recalcul des fiches',
        'avant_restauration' => 'Avant une restauration',
        'avant_import_structures' => 'Avant un import de structures',
        'avant_doublons'     => 'Avant une fusion de doublons',
        'avant_doublons_potentiels' => 'Avant une fusion de doublons',
        'avant_chiffrement_avs' => 'Avant le chiffrement des n° AVS',
    ];
    if (preg_match('/^avant_restauration_[0-9a-f]+$/', $etiquette)) {
        return $connues['avant_restauration'];
    }
    if (preg_match('/^(cloture|reouverture)_(\d{4})$/', $etiquette, $m)) {
        return ($m[1] === 'cloture' ? 'Clôture ' : 'Réouverture ') . $m[2];
    }
    return $connues[$etiquette] ?? ($etiquette !== '' ? ucfirst(str_replace('_', ' ', $etiquette)) : 'Copie');
}

// Le chemin d'UNE copie, désignée par son nom, ou null. Le nom n'est jamais
// un chemin : il doit figurer tel quel dans la liste (même dossier, même
// motif) — un « ../database.sqlite » ou le nom de la base elle-même n'y est
// pas. Seule porte vers un fichier de copie pour supprimer ou restaurer.
function sauvegarde_serveur_chemin(string $nom): ?string
{
    foreach (sauvegardes_serveur() as $s) {
        if (hash_equals($s['nom'], $nom)) {
            return dirname(APP_DB_PATH) . '/' . $s['nom'];
        }
    }
    return null;
}

function sauvegarde_serveur_supprimer(string $nom): bool
{
    $chemin = sauvegarde_serveur_chemin($nom);
    return $chemin !== null && @unlink($chemin);
}

// Taille maximale réellement acceptée par ce serveur pour un téléversement, en
// octets : le plus petit des deux réglages PHP qui s'appliquent. Affichée dans
// la carte « Restaurer » de ?p=sauvegardes — une sauvegarde complète pèse souvent plus que la limite
// par défaut d'un hébergement mutualisé, et l'envoi échoue alors sans message.
function televersement_max(): int
{
    $lire = function (string $cle): int {
        $v = trim((string) ini_get($cle));
        if ($v === '') {
            return 0;
        }
        $unite = strtolower(substr($v, -1));
        $n = (int) $v;
        return match ($unite) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    $valeurs = array_filter([$lire('upload_max_filesize'), $lire('post_max_size')]);
    return $valeurs ? min($valeurs) : 0;
}

// Rappel de sauvegarde pour le tableau de bord. La sauvegarde complète est la
// seule copie qui quitte le serveur — les .bak automatiques dorment à côté de
// la base et disparaissent avec lui. Or rien ne la déclenche : elle n'existe
// que si quelqu'un pense à la télécharger. D'où ce rappel, quand le dernier
// téléchargement remonte à plus de $seuil jours (sauvegarde_rappel_jours()).
//
// $derniereLe : date « AAAA-MM-JJ HH:MM:SS » du dernier téléchargement, vide
// s'il n'y en a jamais eu (ou pas depuis que l'application le note).
// Renvoie null (rien à rappeler) ou ['jours' => N|null] — null = jamais.
function sauvegarde_rappel(string $derniereLe, ?int $maintenant = null, ?int $seuil = null): ?array
{
    $maintenant ??= time();
    // Le seuil vient de l'appelant (sauvegarde_rappel_jours(), qui lit la
    // base) : la fonction reste un calcul pur, testable sans base.
    $seuil ??= (int) SAUVEGARDE_RAPPEL_JOURS;
    $t = horodatage_ts($derniereLe);
    if ($t === null) {
        return ['jours' => null];
    }
    $jours = intdiv(max(0, $maintenant - $t), 86400);
    return $jours > $seuil ? ['jours' => $jours] : null;
}

// Bornes du délai de rappel, réglé sur ?p=sauvegardes. Au-delà d'un an, ce n'est
// plus un rappel.
const SAUVEGARDE_RAPPEL_MIN = 1;
const SAUVEGARDE_RAPPEL_MAX = 365;

// Délai de rappel en jours : le réglage de ?p=sauvegardes (paramètre
// `sauvegarde_rappel_jours`) s'il est posé et valide, sinon la constante
// SAUVEGARDE_RAPPEL_JOURS de la configuration — qui reste la valeur par défaut.
function sauvegarde_rappel_jours(): int
{
    $v = function_exists('param') ? trim((string) param('sauvegarde_rappel_jours', '')) : '';
    if ($v !== '' && ctype_digit($v)) {
        $n = (int) $v;
        if ($n >= SAUVEGARDE_RAPPEL_MIN && $n <= SAUVEGARDE_RAPPEL_MAX) {
            return $n;
        }
    }
    return (int) SAUVEGARDE_RAPPEL_JOURS;
}
