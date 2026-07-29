<?php
/** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var string $recherche */ /** @var string $type */ /** @var string $ville */ /** @var string $pays */
/** @var string $grandeRegion */ /** @var string $statut */ /** @var ?int $jaugeMin */ /** @var ?int $jaugeMax */
/** @var int $moisEvenement */ /** @var int $moisProg */

// Popup construit côté serveur (échappement e() habituel) : le JS se contente
// de l'injecter tel quel, jamais de HTML assemblé côté client.
$points = array_map(function (array $p): array {
    $html = '<strong>' . e($p['ville']) . '</strong>' . ($p['pays'] !== '' ? ' <span class="muted">(' . e($p['pays']) . ')</span>' : '');
    $html .= '<ul class="carte-popup-liste">';
    foreach ($p['lieux'] as $l) {
        $html .= '<li><a href="?p=lieu&id=' . (int) $l['id'] . '">' . e($l['nom']) . '</a>'
            . ($l['type'] !== '' ? ' <span class="muted small">(' . e($l['type']) . ')</span>' : '') . '</li>';
    }
    $html .= '</ul>';
    return ['lat' => $p['lat'], 'lon' => $p['lon'], 'popup' => $html];
}, $cartePoints);

// Lien de secours pour les villes qu'on ne parviendra sans doute jamais à
// géocoder automatiquement (typo, lieu-dit trop précis, ville introuvable
// pour Nominatim…) : liste les lieux concernés (mêmes filtres actifs) pour
// les corriger à la main plutôt que de recliquer indéfiniment sur « Géocoder ».
$lienNonLocalises = '?p=lieux&' . http_build_query([
    'vue' => 'liste', 'type' => $type, 'ville' => $ville, 'pays' => $pays, 'grande_region' => $grandeRegion,
    'statut' => $statut, 'jauge_min' => $jaugeMin ?? '', 'jauge_max' => $jaugeMax ?? '',
    'mois_evenement' => $moisEvenement ?: '', 'mois_prog' => $moisProg ?: '', 'non_localises' => 1,
]);
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="carte-lieux-wrap">
    <?= carte_banner_geocodage_html(
        $carteVillesManquantes,
        'lieu(x)', 'lieux', 'affichés',
        $lienNonLocalises,
        '?p=lieux_geocoder',
        [
            'q' => $recherche, 'type' => $type, 'ville' => $ville, 'pays' => $pays, 'grande_region' => $grandeRegion,
            'statut' => $statut, 'jauge_min' => $jaugeMin !== null ? (int) $jaugeMin : '', 'jauge_max' => $jaugeMax !== null ? (int) $jaugeMax : '',
            'mois_evenement' => $moisEvenement ?: '', 'mois_prog' => $moisProg ?: '',
        ],
        isset($_GET['geocode'])
    ) ?>

    <div id="carte-lieux" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    lassoInitCarteLieux('carte-lieux', points, 'carte-lieux-vue');
})();
</script>
