<?php
/** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var string $recherche */ /** @var array $categorieId */ /** @var array $pays */
/** @var array $departementCanton */ /** @var array $tagId */ /** @var array $statut */

// Popup construit côté serveur (échappement e() habituel) : le JS se contente
// de l'injecter tel quel, jamais de HTML assemblé côté client.
$points = array_map(function (array $p): array {
    $html = '<strong>' . e($p['ville']) . '</strong>' . ($p['pays'] !== '' ? ' <span class="muted">(' . e($p['pays']) . ')</span>' : '');
    $html .= '<ul class="carte-popup-liste">';
    foreach ($p['items'] as $s) {
        $html .= '<li><a href="?p=structure&id=' . (int) $s['id'] . '">' . e($s['nom']) . '</a>'
            . ($s['type'] !== '' ? ' <span class="muted small">(' . e($s['type']) . ')</span>' : '') . '</li>';
    }
    $html .= '</ul>';
    return ['lat' => $p['lat'], 'lon' => $p['lon'], 'popup' => $html, 'count' => count($p['items'])];
}, $cartePoints);

// Lien de secours pour les villes qu'on ne parviendra sans doute jamais à
// géocoder automatiquement (typo, ville introuvable pour Nominatim…) — voir
// views/_lieux_carte.php pour le même principe.
$lienNonLocalises = '?p=structures&' . http_build_query([
    'vue' => 'liste', 'categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton,
    'tag_id' => $tagId, 'statut' => $statut, 'non_localises' => 1,
]);
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="carte-lieux-wrap">
    <?= carte_banner_geocodage_html(
        $carteVillesManquantes,
        'structure(s)', 'structures', 'affichées',
        $lienNonLocalises,
        '?p=structures_geocoder',
        [
            'q' => $recherche, 'categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton,
            'tag_id' => $tagId, 'statut' => $statut,
        ],
        isset($_GET['geocode'])
    ) ?>

    <div id="carte-structures" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    lassoInitCarteLieux('carte-structures', points, 'carte-structures-vue');
})();
</script>
