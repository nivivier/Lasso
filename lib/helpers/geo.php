<?php
// Pays, régions, cantons et départements — extrait de lib/helpers.php sans
// rien changer à son comportement.
//
// Une adresse se saisit dans six modules et doit s'y lire pareil : d'où une
// taxonomie de pays configurable, des drapeaux, et la déduction de la grande
// région depuis le canton (Suisse) ou le département (France).
//
// ⚠️ Chargé par lib/helpers.php, jamais requis directement.

// ------------------------------------------------------------------- PAYS
// Liste de pays configurable (Paramètres → Pays, voir migration_43), partagée
// par tous les champs pays de l'app (structures, lieux, employeur, événements,
// facturation) — un seul point de configuration au lieu d'une saisie libre par
// module. Chaque entrée porte un nom affiché et un code ISO 3166-1 alpha-2
// (pour le drapeau et, côté événements, la valeur stockée).
// Liste des PAYS (racines de la taxonomie pays_liste, voir migration_49) —
// hors régions. Utilisée partout où l'on choisit/affiche un pays (structures,
// lieux, employeur, événements, facturation, drapeaux).
function pays_liste(): array
{
    return db()->query('SELECT * FROM pays_liste WHERE parent_id IS NULL ORDER BY ordre, nom')->fetchAll();
}

// Arbre complet pays + régions (toutes les lignes de pays_liste), pour la page
// de gestion Paramètres → Pays (glisser-déposer à 2 niveaux).
function pays_liste_arbre(): array
{
    return db()->query('SELECT * FROM pays_liste ORDER BY ordre, nom')->fetchAll();
}

// Régions (grandes régions) groupées par NOM de pays parent, ordonnées.
// ['France' => ['Bretagne', 'Normandie', …], 'Suisse' => […], …]. Sert à
// construire les listes déroulantes dépendantes (formulaires) et les optgroups
// des filtres.
function pays_regions_map(): array
{
    $rows = db()->query(
        'SELECT p.nom AS pays, r.nom AS region
         FROM pays_liste r JOIN pays_liste p ON p.id = r.parent_id
         WHERE r.parent_id IS NOT NULL
         ORDER BY p.ordre, p.nom, r.ordre, r.nom'
    )->fetchAll();
    $map = [];
    foreach ($rows as $x) {
        $map[(string) $x['pays']][] = (string) $x['region'];
    }
    return $map;
}

// Garantit qu'une région existe dans la taxonomie sous son pays, la créant au
// besoin (utilisé par l'import : la liste est stricte dans les formulaires, mais
// l'import peut l'enrichir). Sans effet si le pays est inconnu de la liste ou si
// l'un des noms est vide. Cache statique pour éviter les requêtes répétées.
function pays_region_assurer(string $paysNom, string $regionNom): void
{
    $paysNom = trim($paysNom);
    $regionNom = trim($regionNom);
    if ($paysNom === '' || $regionNom === '') { return; }
    static $vues = [];
    $k = $paysNom . "\0" . $regionNom;
    if (isset($vues[$k])) { return; }
    $vues[$k] = true;
    $stmt = db()->prepare('SELECT id FROM pays_liste WHERE nom = ? AND parent_id IS NULL');
    $stmt->execute([$paysNom]);
    $paysId = $stmt->fetchColumn();
    if ($paysId === false) { return; } // pays hors taxonomie : on n'invente pas de pays
    $ex = db()->prepare('SELECT 1 FROM pays_liste WHERE parent_id = ? AND nom = ?');
    $ex->execute([$paysId, $regionNom]);
    if ($ex->fetchColumn()) { return; }
    $ord = db()->prepare('SELECT COALESCE(MAX(ordre),0)+1 FROM pays_liste WHERE parent_id = ?');
    $ord->execute([$paysId]);
    db()->prepare('INSERT OR IGNORE INTO pays_liste (parent_id, nom, code_iso2, ordre) VALUES (?, ?, NULL, ?)')
        ->execute([(int) $paysId, $regionNom, (int) $ord->fetchColumn()]);
}

// Options <option> de régions pour un pays donné (valeur = nom de région).
// Filet de sécurité : une valeur actuelle absente de la taxonomie est ajoutée
// en tête (« non reconnu ») pour ne jamais l'écraser silencieusement.
function region_options_nom(string $paysNom, string $selected): string
{
    $regions = pays_regions_map()[$paysNom] ?? [];
    $h = '';
    if ($selected !== '' && !in_array($selected, $regions, true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($regions as $r) {
        $h .= '<option value="' . e($r) . '"' . ($selected === $r ? ' selected' : '') . '>' . e($r) . '</option>';
    }
    return $h;
}

// Cantons suisses non ambigus (français d'un seul côté de la barrière
// linguistique) → grande région déduite avec certitude.
const CANTONS_SUISSES_REGIONS = [
    'GE' => 'Romandie',
    'VD' => 'Romandie', 'NE' => 'Romandie', 'JU' => 'Romandie',
    'TI' => 'Tessin',
    'ZH' => 'Alémanique', 'BS' => 'Alémanique', 'BL' => 'Alémanique',
    'AG' => 'Alémanique', 'SG' => 'Alémanique', 'TG' => 'Alémanique', 'GR' => 'Alémanique',
    'LU' => 'Alémanique', 'ZG' => 'Alémanique', 'SO' => 'Alémanique', 'SH' => 'Alémanique',
    'AR' => 'Alémanique', 'AI' => 'Alémanique', 'GL' => 'Alémanique', 'UR' => 'Alémanique',
    'SZ' => 'Alémanique', 'OW' => 'Alémanique', 'NW' => 'Alémanique',
];
// Cantons bilingues (Fribourg, Valais, Berne) : la région linguistique dépend
// de la commune, pas du canton entier — valeur ci-dessous = simple défaut
// (majorité linguistique), jamais imposée (voir grande_region_deduite() $strict).
const CANTONS_SUISSES_BILINGUES = ['FR' => 'Romandie', 'VS' => 'Romandie', 'BE' => 'Alémanique'];

// Grande région déduite du département (France, via le référentiel officiel
// departements_regions, lib/db.php) ou du canton (Suisse, CANTONS_SUISSES_REGIONS
// ci-dessus). $pays accepte indifféremment un NOM (structures/lieux :
// adresse_pays/pays) ou un CODE ISO2 (événements : pays). $strict (défaut
// true) : ne renvoie JAMAIS de valeur pour un canton bilingue — utilisé
// partout où la déduction est IMPOSÉE (sauvegarde serveur, import, script Dev
// de rattrapage), pour ne jamais écraser une saisie manuelle ambiguë.
// $strict=false ne sert qu'à la suggestion JS du formulaire (défaut
// pré-rempli mais toujours modifiable pour ces 3 cantons). Null si le pays
// n'a pas de règle de déduction, ou si le département/canton n'est pas
// reconnu (jamais de devinette : le champ reste alors saisi à la main).
// $departementsFranceCache : passer departements_regions_map() déjà chargée
// pour éviter une requête SQL par ligne dans une boucle sur plusieurs fiches
// (voir grande_regions_detecter(), lib/dev.php) ; null = requête normale
// (cas courant : un seul appel, ex. sauvegarde d'un formulaire).
function grande_region_deduite(string $pays, string $departementCanton, bool $strict = true, ?array $departementsFranceCache = null): ?string
{
    $pays = trim($pays);
    $departementCanton = trim($departementCanton);
    if ($departementCanton === '') {
        return null;
    }
    if ($pays === 'France' || $pays === 'FR') {
        if ($departementsFranceCache !== null) {
            return $departementsFranceCache[$departementCanton] ?? null;
        }
        $stmt = db()->prepare('SELECT region FROM departements_regions WHERE code = ?');
        $stmt->execute([$departementCanton]);
        $r = $stmt->fetchColumn();
        return $r !== false ? (string) $r : null;
    }
    if ($pays === 'Suisse' || $pays === 'CH') {
        $code = mb_strtoupper($departementCanton, 'UTF-8');
        if (isset(CANTONS_SUISSES_REGIONS[$code])) {
            return CANTONS_SUISSES_REGIONS[$code];
        }
        if (!$strict && isset(CANTONS_SUISSES_BILINGUES[$code])) {
            return CANTONS_SUISSES_BILINGUES[$code];
        }
        return null;
    }
    return null;
}

// Référentiel départements français → région (code => région), pour l'auto-
// remplissage JS de grande_region dans les formulaires (voir _region_select_js.php) —
// même source que grande_region_deduite().
function departements_regions_map(): array
{
    return db()->query('SELECT code, region FROM departements_regions')->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Nom de département français à partir de son code (ex. "74" → "Haute-Savoie")
// — même référentiel departements_regions que ci-dessus. Utilisé pour
// enrichir la requête de géocodage (lib/geocodage.php) : un code seul n'est
// pas reconnu par Nominatim, un nom de département lève l'ambiguïté des
// villes homonymes (ex. plusieurs « Bonneville »). '' si code inconnu/vide.
function departement_nom_depuis_code(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }
    $stmt = db()->prepare('SELECT departement FROM departements_regions WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetchColumn();
    return $r !== false ? (string) $r : '';
}

// Nom de pays à partir d'un code ISO2 — inverse de pays_drapeau_nom()/
// pays_options_code(). Nécessaire pour résoudre evenements.pays (stocké en
// code) vers le nom attendu par region_options_nom()/pays_regions_map()
// (clés indexées par nom, comme structures/lieux).
function pays_nom_depuis_code(string $code): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (pays_liste() as $p) {
            $map[(string) $p['code_iso2']] = (string) $p['nom'];
        }
    }
    return $map[strtoupper(trim($code))] ?? '';
}

// Émoji drapeau à partir d'un code pays ISO 3166-1 alpha-2 (ex. « CH » → 🇨🇭).
// Vide si le code n'a pas ce format (ex. valeur vide ou non reconnue).
function pays_drapeau(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $drapeau = '';
    foreach (str_split($code) as $lettre) {
        $drapeau .= mb_chr(127397 + ord($lettre), 'UTF-8');
    }
    return $drapeau;
}

// Drapeau à partir d'un NOM de pays stocké tel quel (structures.adresse_pays,
// lieux.pays, employeur_pays — texte libre historique) — vide si le nom ne
// correspond à aucun pays de la liste configurée (ex. donnée erronée d'un
// import), à l'appelant de retomber sur le texte brut dans ce cas.
function pays_drapeau_nom(string $nom): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (pays_liste() as $p) {
            $map[$p['nom']] = $p['code_iso2'];
        }
    }
    return isset($map[$nom]) ? pays_drapeau($map[$nom]) : '';
}

// Options <option> pour un <select> de pays dont la valeur stockée est le NOM
// (structures/lieux/employeur/facturation) — si la valeur actuelle ne
// correspond à aucun pays configuré (donnée libre historique), elle est
// ajoutée en tête pour ne jamais l'écraser silencieusement à l'enregistrement.
function pays_options_nom(string $selected): string
{
    $liste = pays_liste();
    $h = '';
    if ($selected !== '' && !in_array($selected, array_column($liste, 'nom'), true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($liste as $p) {
        $h .= '<option value="' . e($p['nom']) . '"' . ($selected === $p['nom'] ? ' selected' : '') . '>' . e($p['nom']) . '</option>';
    }
    return $h;
}

// Options <option> pour un <select> de pays dont la valeur stockée est le CODE
// ISO2 (evenements.pays) — libellé "Nom", valeur "CODE". Même filet de
// sécurité que pays_options_nom() pour un code non reconnu.
function pays_options_code(string $selected): string
{
    $liste = pays_liste();
    $h = '';
    if ($selected !== '' && !in_array($selected, array_column($liste, 'code_iso2'), true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($liste as $p) {
        $h .= '<option value="' . e($p['code_iso2']) . '"' . ($selected === $p['code_iso2'] ? ' selected' : '') . '>' . e($p['nom']) . '</option>';
    }
    return $h;
}
