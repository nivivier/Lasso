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
    <?php if ($carteVillesManquantes > 0): ?>
    <div class="carte-banner">
        <p><?= $carteVillesManquantes ?> lieu(x) dont la ville n'est pas encore localisée sur la carte.
            <a href="<?= e($lienNonLocalises) ?>">Voir la liste</a></p>
        <form method="post" action="?p=lieux_geocoder" id="geocoder-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="q" value="<?= e($recherche) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="ville" value="<?= e($ville) ?>">
            <input type="hidden" name="pays" value="<?= e($pays) ?>">
            <input type="hidden" name="grande_region" value="<?= e($grandeRegion) ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="hidden" name="jauge_min" value="<?= $jaugeMin !== null ? (int) $jaugeMin : '' ?>">
            <input type="hidden" name="jauge_max" value="<?= $jaugeMax !== null ? (int) $jaugeMax : '' ?>">
            <input type="hidden" name="mois_evenement" value="<?= $moisEvenement ?: '' ?>">
            <input type="hidden" name="mois_prog" value="<?= $moisProg ?: '' ?>">
            <button type="submit" id="geocoder-btn"><?= icon('map-pin') ?> Géocoder (par lots, ≈1 seconde par ville)</button>
        </form>
        <p class="muted small" id="geocoder-auto-msg" hidden>Géocodage en cours (service Nominatim/OpenStreetMap, 1 ville par seconde)… vous pouvez quitter la page à tout moment, il reprendra où il s'est arrêté au prochain clic.</p>
    </div>
    <?php elseif (isset($_GET['geocode'])): ?>
    <p class="carte-banner ok flash">Géocodage terminé — toutes les villes des lieux affichés sont désormais localisées.</p>
    <?php endif; ?>

    <div id="carte-lieux" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    const map = L.map('carte-lieux');
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);

    if (points.length) {
        const bounds = [];
        points.forEach(p => {
            L.marker([p.lat, p.lon]).addTo(map).bindPopup(p.popup);
            bounds.push([p.lat, p.lon]);
        });
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    } else {
        map.setView([46.8, 2.5], 5);
    }
})();

<?php if (isset($_GET['geocode']) && $carteVillesManquantes > 0): ?>
(function () {
    const msg = document.getElementById('geocoder-auto-msg');
    if (msg) { msg.hidden = false; }
    setTimeout(() => {
        const f = document.getElementById('geocoder-form');
        if (f) { f.requestSubmit(); }
    }, 400);
})();
<?php endif; ?>
</script>
