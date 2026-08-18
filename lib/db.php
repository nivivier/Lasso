<?php
// Connexion SQLite et paramètres applicatifs.
//
// Ce fichier faisait 2 449 lignes : connexion, schéma complet (58 tables),
// 68 migrations et valeurs par défaut y cohabitaient. Il ne garde désormais que
// le point d'entrée — db(), param(), sauvegarde — et délègue le reste :
//
//   lib/db/schema.php      init_schema() + les seed_*()
//   lib/db/migrations.php  run_migrations() + les migration_N()
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
function sauvegarder_base(string $etiquette): ?string
{
    $etiquette = preg_replace('/[^a-z0-9]+/i', '_', $etiquette);
    $etiquette = trim((string) $etiquette, '_') ?: 'sauvegarde';
    $chemin = dirname(APP_DB_PATH) . '/' . basename(APP_DB_PATH, '.sqlite')
        . '_' . $etiquette . '_' . date('Ymd_His') . '.sqlite.bak';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($chemin));
        return is_file($chemin) ? $chemin : null;
    } catch (\Throwable $e) {
        return null;
    }
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
