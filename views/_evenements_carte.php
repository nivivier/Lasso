<?php
/** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var string $recherche */ /** @var int $annee */ /** @var string $statutSuisa */ /** @var int $spectacleId */
/** @var string $statut */ /** @var string $visibilite */ /** @var string $pays */ /** @var string $salaries */

// Popup construit côté serveur (échappement e() habituel) : le JS se contente
// de l'injecter tel quel, jamais de HTML assemblé côté client.
$points = array_map(function (array $p): array {
    $html = '<strong>' . e($p['ville']) . '</strong>' . ($p['pays'] !== '' ? ' <span class="muted">(' . e($p['pays']) . ')</span>' : '');
    $html .= '<ul class="carte-popup-liste">';
    foreach ($p['items'] as $ev) {
        $html .= '<li><a href="?p=evenement&id=' . (int) $ev['id'] . '">' . e($ev['nom']) . '</a></li>';
    }
    $html .= '</ul>';
    return ['lat' => $p['lat'], 'lon' => $p['lon'], 'popup' => $html, 'count' => count($p['items'])];
}, $cartePoints);

// Lien de secours pour les villes qu'on ne parviendra sans doute jamais à
// géocoder automatiquement (typo, ville introuvable pour Nominatim…) — voir
// views/_structures_carte.php pour le même principe.
$lienNonLocalises = '?p=evenements_liste&' . http_build_query([
    'vue' => 'liste', 'q' => $recherche, 'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
    'statut' => $statut, 'visibilite' => $visibilite, 'pays' => $pays, 'salaries' => $salaries, 'non_localises' => 1,
]);
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="carte-lieux-wrap">
    <?= carte_banner_geocodage_html(
        $carteVillesManquantes,
        'événement(s)', 'événements', 'affichés',
        $lienNonLocalises,
        '?p=evenements_geocoder',
        [
            'q' => $recherche, 'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
            'statut' => $statut, 'visibilite' => $visibilite, 'pays' => $pays, 'salaries' => $salaries,
        ],
        isset($_GET['geocode'])
    ) ?>

    <div id="carte-evenements" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    lassoInitCarteLieux('carte-evenements', points, 'carte-evenements-vue');
})();
</script>
