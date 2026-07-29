<?php
/** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var string $recherche */ /** @var int $categorieId */ /** @var string $pays */
/** @var string $region */ /** @var int $tagId */ /** @var string $statut */

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
    return ['lat' => $p['lat'], 'lon' => $p['lon'], 'popup' => $html];
}, $cartePoints);
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="carte-lieux-wrap">
    <?php if ($carteVillesManquantes > 0): ?>
    <div class="carte-banner">
        <p><?= $carteVillesManquantes ?> structure(s) dont la ville n'est pas encore localisée sur la carte.</p>
        <form method="post" action="?p=structures_geocoder" id="geocoder-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="q" value="<?= e($recherche) ?>">
            <input type="hidden" name="categorie_id" value="<?= (int) $categorieId ?>">
            <input type="hidden" name="pays" value="<?= e($pays) ?>">
            <input type="hidden" name="region" value="<?= e($region) ?>">
            <input type="hidden" name="tag_id" value="<?= (int) $tagId ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <button type="submit" id="geocoder-btn"><?= icon('map-pin') ?> Géocoder (par lots, ≈1 seconde par ville)</button>
        </form>
        <p class="muted small" id="geocoder-auto-msg" hidden>Géocodage en cours (service Nominatim/OpenStreetMap, 1 ville par seconde)… vous pouvez quitter la page à tout moment, il reprendra où il s'est arrêté au prochain clic.</p>
    </div>
    <?php elseif (isset($_GET['geocode'])): ?>
    <p class="carte-banner ok flash">Géocodage terminé — toutes les villes des structures affichées sont désormais localisées.</p>
    <?php endif; ?>

    <div id="carte-structures" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    const map = L.map('carte-structures');
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
