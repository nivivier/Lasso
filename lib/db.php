<?php
// Connexion SQLite et paramètres applicatifs.
//
// Ce fichier faisait 2 449 lignes : connexion, schéma complet (58 tables),
// 68 migrations et valeurs par défaut y cohabitaient. Il ne garde désormais que
// le point d'entrée — db(), param(), sauvegarde — et délègue le reste :
//
//   lib/db/schema.php      init_schema() + les seed_*()
//   lib/db/migrations.php  run_migrations(), et les migration_N() par tranches
//                          dans lib/db/migrations/
//
// Les deux sont chargés ici et jamais requis directement ailleurs : db() reste
// l'unique porte d'entrée de la base.

require_once __DIR__ . '/config.php'; // APP_DB_PATH, APP_ENV…
require_once __DIR__ . '/calc.php';   // TAUX_DEFAUT
require_once __DIR__ . '/db/schema.php';
require_once __DIR__ . '/db/migrations.php';

// Garantit qu'un .htaccess « Require all denied » existe dans le dossier de la
// base. Troisième couche de protection, volontairement redondante avec le
// data/.htaccess versionné et la règle RedirectMatch du .htaccess racine : elle
// est la seule à fonctionner quand APP_DB_PATH pointe un dossier créé à la
// volée (mkdir ci-dessus) qui n'existait dans aucun déploiement. N'écrase jamais
// un fichier déjà présent (un hébergeur peut y avoir mis ses propres règles) et
// reste silencieuse si l'écriture échoue : un dossier non inscriptible ne doit
// pas empêcher l'application de démarrer, il est déjà signalé ailleurs.
// Sans effet sur Nginx, qui ignore les .htaccess — d'où la recommandation de
// placer la base hors racine web en production (README §2).
function proteger_dossier_donnees(string $dossier): void
{
    $f = $dossier . '/.htaccess';
    if (is_dir($dossier) && !file_exists($f)) {
        @file_put_contents($f, "Require all denied\n");
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path    = APP_DB_PATH;
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    proteger_dossier_donnees($dataDir);

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    // LOWER() intégré de SQLite ne repasse en minuscule que l'ASCII (ex.
    // « GENÈVE » -> « genÈve », le È reste inchangé) : sans extension ICU
    // (absente d'un hébergement mutualisé), une ville saisie avec une
    // majuscule accentuée ne matche jamais sa clé de cache géocodage
    // (lieux_geocodage.cle, construite en PHP via mb_strtolower) — voir
    // geocodage_non_localises_where()/geocodage_villes_manquantes(),
    // lib/geocodage.php, qui utilisent LOWER_UTF8() à la place.
    // @ : sqliteCreateFunction() est dépréciée depuis PHP 8.5 au profit de
    // Pdo\Sqlite::createFunction(), mais new PDO('sqlite:...') ne renvoie pas
    // cette sous-classe ici — l'ancienne méthode reste la seule disponible.
    @$pdo->sqliteCreateFunction('LOWER_UTF8', fn ($s) => mb_strtolower((string) $s, 'UTF-8'), 1);
    // SANS_ACCENTS() : minuscules + repli des accents, pour la recherche unifiée
    // (lib/recherche.php). Même raison que LOWER_UTF8 ci-dessus — le LIKE de
    // SQLite est insensible à la casse ASCII mais SENSIBLE aux accents, donc
    // « deborde » ne trouvait pas « déborde ». Appliquée des deux côtés de la
    // comparaison : sur la colonne, et sur le terme saisi (côté PHP).
    // texte_sans_accents() vit dans lib/helpers.php, chargée après ce fichier —
    // d'où la closure, évaluée seulement à l'appel de la fonction SQL.
    @$pdo->sqliteCreateFunction('SANS_ACCENTS', fn ($s) => texte_sans_accents((string) $s), 1);

    init_schema($pdo);
    return $pdo;
}

// Sauvegarde horodatée de la base dans le dossier data/, snapshot cohérent via
// VACUUM INTO (indépendant du WAL). À appeler AVANT une opération de masse
// risquée (import…) pour pouvoir revenir en arrière. Renvoie le chemin du
// fichier créé, ou null en cas d'échec (sans interrompre l'opération). Les
// fichiers .bak sont exclus du versionnement (.gitignore).
// $pdo : la connexion à copier, quand ce n'est pas db() — une migration
// jouée sur une base fraîchement restaurée (sauvegarde_restaurer()) tourne sur
// sa propre connexion, et db() désignerait encore l'ancien fichier.
function sauvegarder_base(string $etiquette, ?PDO $pdo = null): ?string
{
    $etiquette = preg_replace('/[^a-z0-9]+/i', '_', $etiquette);
    $etiquette = trim((string) $etiquette, '_') ?: 'sauvegarde';
    $chemin = dirname(APP_DB_PATH) . '/' . basename(APP_DB_PATH, '.sqlite')
        . '_' . $etiquette . '_' . date('Ymd_His') . '.sqlite.bak';
    try {
        $pdo = $pdo ?? db();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($chemin));
    } catch (\Throwable $e) {
        return null;
    }
    if (!is_file($chemin)) {
        return null;
    }
    sauvegardes_auto_purger($chemin);
    return $chemin;
}

// Le motif des copies de sauvegarder_base() : même dossier et même préfixe que
// la base. Partagé par la purge et par la liste de ?p=sauvegardes
// (sauvegardes_serveur()), pour qu'elles parlent des mêmes fichiers.
function sauvegardes_auto_motif(): string
{
    return dirname(APP_DB_PATH) . '/' . basename(APP_DB_PATH, '.sqlite') . '_*.sqlite.bak';
}

// Ne garde que les SAUVEGARDES_AUTO_MAX copies automatiques les plus récentes
// de la base (celles de sauvegarder_base(), même préfixe, même dossier). Sans
// cette purge elles s'accumulaient sans fin : chacune est une copie intégrale
// — salaires, n° AVS, empreintes de mots de passe — et toutes grignotent le
// quota disque d'un hébergement mutualisé. $garder (celle qu'on vient de
// créer) n'est jamais supprimée, quel que soit son rang : elle compte dans le
// plafond, et ce sont les autres qui lui cèdent la place.
function sauvegardes_auto_purger(string $garder = ''): int
{
    $max = max(1, (int) SAUVEGARDES_AUTO_MAX);
    $fichiers = glob(sauvegardes_auto_motif()) ?: [];
    $autres = array_values(array_filter($fichiers, fn ($f) => $f !== $garder));
    $place = count($autres) < count($fichiers) ? $max - 1 : $max;
    // Le nom porte la date (…_AAAAMMJJ_HHMMSS.sqlite.bak) mais après une
    // étiquette de longueur variable : on trie sur la date de modification.
    usort($autres, fn ($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    $supprimes = 0;
    foreach (array_slice($autres, $place) as $f) {
        if (@unlink($f)) {
            $supprimes++;
        }
    }
    return $supprimes;
}

// Paramètres applicatifs (table parametres).
//
// Mémoïsé en une seule requête groupée : param() est appelée des dizaines de
// fois par requête (nom et logos de l'employeur, couleurs, modules actifs,
// jetons d'export…). Sans ce cache, chaque appel serait un aller-retour SQL.
function param(string $cle, $defaut = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT cle, valeur FROM parametres') as $row) {
            $cache[$row['cle']] = $row['valeur'];
        }
    }
    return $cache[$cle] ?? $defaut;
}
