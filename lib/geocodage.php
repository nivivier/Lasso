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

// Couples (ville, pays) utilisés par au moins un lieu mais absents du cache.
function geocodage_villes_manquantes(): array
{
    return db()->query(
        "SELECT DISTINCT TRIM(ville) AS ville, TRIM(pays) AS pays
         FROM lieux
         WHERE TRIM(ville) <> ''
           AND LOWER(TRIM(ville)) || '|' || LOWER(TRIM(pays)) NOT IN (SELECT cle FROM lieux_geocodage)
         ORDER BY ville"
    )->fetchAll();
}

// Géocode jusqu'à $max villes manquantes, espacées d'1 seconde (politique
// Nominatim). Renvoie le nombre de villes traitées.
function geocodage_traiter_lot(int $max = GEOCODAGE_LOT_TAILLE): int
{
    $manquantes = array_slice(geocodage_villes_manquantes(), 0, $max);
    foreach ($manquantes as $i => $v) {
        if ($i > 0) {
            usleep(1_100_000);
        }
        geocodage_geocoder_ville((string) $v['ville'], (string) $v['pays']);
    }
    return count($manquantes);
}
