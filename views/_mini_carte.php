<?php
/** @var string $miniCarteVille */ /** @var string $miniCarteDepartementCanton */ /** @var string $miniCartePays */
/** @var string $miniCarteRetourRoute */ /** @var int $miniCarteRetourId */
// Mini-carte de localisation (?p=lieu/?p=structure/?p=evenement) : un seul
// marqueur sur la ville déjà géolocalisée (cache lieux_geocodage, voir
// lib/geocodage.php — même source que la vue carte des lieux, ?p=lieux&vue=carte).
// Ville pas encore en cache : bouton pour la géocoder à la volée (une seule
// ville, pas de politique de lot ici — voir route_geocoder_ville_unique()).
// Rien n'est affiché si la ville est vide (fiche non localisable) : au
// caller de ne pas inclure ce partiel dans ce cas.
$geo = geocodage_lire($miniCarteVille, $miniCarteDepartementCanton, $miniCartePays);
?>
<?php if ($geo && $geo['statut'] === 'ok'): ?>
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <div id="mini-carte" class="mini-carte"></div>
    <script src="assets/vendor/leaflet/leaflet.js"></script>
    <script>
    (function () {
        var map = L.map('mini-carte', { zoomControl: false, scrollWheelZoom: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
        }).addTo(map);
        var pos = [<?= (float) $geo['latitude'] ?>, <?= (float) $geo['longitude'] ?>];
        L.marker(pos).addTo(map);
        // Zoom volontairement large : situer la ville dans le pays, pas la
        // localiser précisément dans la rue.
        map.setView(pos, 7);
    })();
    </script>
<?php else: ?>
    <p class="muted small">Ville non encore localisée sur la carte.</p>
    <form method="post" action="?p=geocoder_ville_unique">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="ville" value="<?= e($miniCarteVille) ?>">
        <input type="hidden" name="departement_canton" value="<?= e($miniCarteDepartementCanton) ?>">
        <input type="hidden" name="pays" value="<?= e($miniCartePays) ?>">
        <input type="hidden" name="retour_route" value="<?= e($miniCarteRetourRoute) ?>">
        <input type="hidden" name="retour_id" value="<?= (int) $miniCarteRetourId ?>">
        <button type="submit" class="btn-sm"><?= icon('map-pin') ?> Géocoder cette ville</button>
    </form>
<?php endif; ?>
