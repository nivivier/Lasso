<?php
// Géocodage des lieux (vue carte, ?p=lieux&vue=carte) : convertit « ville +
// pays » en latitude/longitude via Nominatim (OpenStreetMap), avec un cache
// permanent en base (table lieux_geocodage, migration_53) — jamais réinterrogé
// à l'affichage, une seule fois par couple (ville, pays), pas par lieu (une
// même ville regroupe généralement plusieurs lieux).
//
// Politique d'usage Nominatim (https://operations.osmfoundation.org/policies/nominatim/) :
// User-Agent identifiant l'appli, 1 requête/seconde maximum — voir
// geocodage_traiter_lot(), qui espace les appels et ne traite qu'un petit lot
// à la fois (appelé depuis un bouton web, pas de tâche de fond disponible sur
// un hébergement mutualisé).

declare(strict_types=1);

// Nombre de villes traitées par clic sur « Géocoder » (voir route_lieux_geocoder()).
// Rester sous le délai d'exécution PHP par défaut, une requête espacée d'1s.
const GEOCODAGE_LOT_TAILLE = 15;

function geocodage_cle(string $ville, string $pays): string
{
    return mb_strtolower(trim($ville), 'UTF-8') . '|' . mb_strtolower(trim($pays), 'UTF-8');
}

// Résultat déjà en cache pour cette ville, ou null si jamais interrogée.
function geocodage_lire(string $ville, string $pays): ?array
{
    $stmt = db()->prepare('SELECT * FROM lieux_geocodage WHERE cle = ?');
    $stmt->execute([geocodage_cle($ville, $pays)]);
    $r = $stmt->fetch();
    return $r ?: null;
}

// Interroge Nominatim pour UNE ville et écrit le résultat en cache, succès ou
// échec (un échec n'est jamais réessayé automatiquement — DELETE FROM
// lieux_geocodage WHERE cle=… pour forcer une nouvelle tentative).
function geocodage_geocoder_ville(string $ville, string $pays): void
{
    $cle = geocodage_cle($ville, $pays);
    $q = trim($ville . ', ' . $pays);
    $lat = null;
    $lon = null;
    $statut = 'echec';

    if ($q !== '') {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $q, 'format' => 'json', 'limit' => 1,
        ]);
        $reponse = maj_http_get($url, ['User-Agent: Lasso (gestion associative) - contact via github.com/nivivier/Lasso']);
        $data = $reponse !== null ? json_decode($reponse, true) : null;
        if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
            $lat = (float) $data[0]['lat'];
            $lon = (float) $data[0]['lon'];
            $statut = 'ok';
        }
    }

    db()->prepare(
        'INSERT INTO lieux_geocodage (cle, ville, pays, latitude, longitude, statut, maj_le)
         VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))
         ON CONFLICT(cle) DO UPDATE SET
            latitude = excluded.latitude, longitude = excluded.longitude,
            statut = excluded.statut, maj_le = excluded.maj_le'
    )->execute([$cle, trim($ville), trim($pays), $lat, $lon, $statut]);
}

// Couples (ville, pays) utilisés par au moins une ligne de $table mais absents
// du cache. $villeCol/$paysCol doivent stocker le pays en NOM (structures,
// lieux) — pour une table qui le stocke en code ISO2 (événements), voir
// geocodage_villes_manquantes_evenements() à la place.
function geocodage_villes_manquantes(string $table = 'lieux', string $villeCol = 'ville', string $paysCol = 'pays'): array
{
    return db()->query(
        "SELECT DISTINCT TRIM($villeCol) AS ville, TRIM($paysCol) AS pays
         FROM $table
         WHERE TRIM($villeCol) <> ''
           AND LOWER(TRIM($villeCol)) || '|' || LOWER(TRIM($paysCol)) NOT IN (SELECT cle FROM lieux_geocodage)
         ORDER BY ville"
    )->fetchAll();
}

// Fragment SQL (" AND ...") pour ne garder que les lignes dont la ville n'a
// jamais été géolocalisée avec succès (cache lieux_geocodage) — filtre
// d'appoint partagé entre lieux_filtres() et structures_filtres(), pour
// traiter les cas où Nominatim ne trouve pas la ville (typo, lieu-dit trop
// précis…), accessible depuis le lien « Voir la liste » de la vue carte (voir
// carte_banner_geocodage_html(), lib/helpers.php).
function geocodage_non_localises_where(string $villeCol, string $paysCol): string
{
    return " AND TRIM($villeCol) <> '' AND (LOWER(TRIM($villeCol)) || '|' || LOWER(TRIM($paysCol))) NOT IN "
        . "(SELECT cle FROM lieux_geocodage WHERE statut = 'ok')";
}

// Variante événements : pays stocké en code ISO2 (evenements.pays), converti
// en nom avant de construire la clé de cache — cohérente avec
// structures/lieux qui stockent directement le nom. Dédoublonné par couple
// (ville, pays nom) puisque plusieurs codes ne devraient jamais correspondre
// au même nom, mais deux lignes peuvent partager exactement la même ville+pays.
function geocodage_villes_manquantes_evenements(): array
{
    $rows = db()->query("SELECT DISTINCT TRIM(ville) AS ville, TRIM(pays) AS pays_code FROM evenements WHERE TRIM(ville) <> ''")->fetchAll();
    $vus = [];
    $manquantes = [];
    foreach ($rows as $r) {
        $paysNom = pays_nom_depuis_code((string) $r['pays_code']);
        if ($paysNom === '') {
            continue;
        }
        $cle = geocodage_cle((string) $r['ville'], $paysNom);
        if (isset($vus[$cle])) {
            continue;
        }
        $vus[$cle] = true;
        if (geocodage_lire((string) $r['ville'], $paysNom) === null) {
            $manquantes[] = ['ville' => (string) $r['ville'], 'pays' => $paysNom];
        }
    }
    usort($manquantes, fn ($a, $b) => strcmp($a['ville'], $b['ville']));
    return $manquantes;
}

// Géocode jusqu'à $max villes renvoyées par $villesManquantes (callable sans
// argument, voir geocodage_villes_manquantes()/_evenements() ci-dessus),
// espacées d'1 seconde (politique Nominatim). Renvoie le nombre de villes traitées.
function geocodage_traiter_lot(callable $villesManquantes, int $max = GEOCODAGE_LOT_TAILLE): int
{
    $manquantes = array_slice($villesManquantes(), 0, $max);
    foreach ($manquantes as $i => $v) {
        if ($i > 0) {
            usleep(1_100_000);
        }
        geocodage_geocoder_ville((string) $v['ville'], (string) $v['pays']);
    }
    return count($manquantes);
}

// Regroupe des fiches déjà filtrées (chaque ligne ayant 'ville' et 'pays' —
// NOM, pas code) par ville géolocalisée, pour une vue carte — factorisé entre
// lieux/structures/événements (mêmes principes que lieux_carte_points()).
// $itemBuilder(array $ligne): array construit l'entrée de popup pour cette
// fiche (ex. ['id'=>, 'nom'=>, 'type'=>]). Retourne [points, nbNonGeolocalises],
// où chaque point est ['lat'=>, 'lon'=>, 'ville'=>, 'pays'=>, 'items'=>[...]].
function carte_points_grouper(array $lignes, callable $itemBuilder): array
{
    $parCle = [];
    $nonGeolocalises = 0;
    foreach ($lignes as $r) {
        $geo = geocodage_lire((string) $r['ville'], (string) $r['pays']);
        if (!$geo || $geo['statut'] !== 'ok') {
            $nonGeolocalises++;
            continue;
        }
        $cle = geocodage_cle((string) $r['ville'], (string) $r['pays']);
        if (!isset($parCle[$cle])) {
            $parCle[$cle] = [
                'lat' => (float) $geo['latitude'], 'lon' => (float) $geo['longitude'],
                'ville' => (string) $r['ville'], 'pays' => (string) $r['pays'], 'items' => [],
            ];
        }
        $parCle[$cle]['items'][] = $itemBuilder($r);
    }
    return [array_values($parCle), $nonGeolocalises];
}
