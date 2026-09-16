<?php
// Sauvegarde complète : la base ET les fichiers déposés, dans une seule
// archive.
//
// La base seule ne suffit pas. Elle ne mémorise que l'EMPLACEMENT des fichiers
// — logos de l'employeur, photos d'employés, icônes de spectacle, feuilles
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
function sauvegarde_note(int $nbFichiers, string $version): string
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
    ]) . "\n";
}

// Construit l'archive et retourne [chemin temporaire, nom de téléchargement].
// À l'appelant de lire puis de supprimer le fichier.
//
// $format force le format au lieu de prendre celui que sait produire ce serveur
// — les trois chemins se vérifient ainsi sur une machine qui les a tous, alors
// qu'en production un seul est atteignable.
function sauvegarde_construire(string $slug, string $version, ?string $format = null): array
{
    $format = $format ?? sauvegarde_format();
    $horo   = date('Y-m-d_His');

    // Instantané cohérent de la base, indépendant du WAL.
    $base = tempnam(sys_get_temp_dir(), 'bk_') . '.sqlite';
    @unlink($base);
    db()->exec('VACUUM INTO ' . db()->quote($base));

    if ($format === 'sqlite') {
        return [$base, $slug . '_' . $horo . '.sqlite'];
    }

    $fichiers = sauvegarde_fichiers(sauvegarde_dossiers());
    $note     = sauvegarde_note(count($fichiers), $version);
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
