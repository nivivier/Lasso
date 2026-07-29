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
    return ['lat' => $p['lat'], 'lon' => $p['lon'], 'popup' => $html];
}, $cartePoints);
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="carte-lieux-wrap">
    <?php if ($carteVillesManquantes > 0): ?>
    <div class="carte-banner">
        <p><?= $carteVillesManquantes ?> événement(s) dont la ville n'est pas encore localisée sur la carte.</p>
        <form method="post" action="?p=evenements_geocoder" id="geocoder-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="q" value="<?= e($recherche) ?>">
            <input type="hidden" name="annee" value="<?= (int) $annee ?>">
            <input type="hidden" name="statut_suisa" value="<?= e($statutSuisa) ?>">
            <input type="hidden" name="spectacle_id" value="<?= (int) $spectacleId ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="hidden" name="visibilite" value="<?= e($visibilite) ?>">
            <input type="hidden" name="pays" value="<?= e($pays) ?>">
            <input type="hidden" name="salaries" value="<?= e($salaries) ?>">
            <button type="submit" id="geocoder-btn"><?= icon('map-pin') ?> Géocoder (par lots, ≈1 seconde par ville)</button>
        </form>
        <p class="muted small" id="geocoder-auto-msg" hidden>Géocodage en cours (service Nominatim/OpenStreetMap, 1 ville par seconde)… vous pouvez quitter la page à tout moment, il reprendra où il s'est arrêté au prochain clic.</p>
    </div>
    <?php elseif (isset($_GET['geocode'])): ?>
    <p class="carte-banner ok flash">Géocodage terminé — toutes les villes des événements affichés sont désormais localisées.</p>
    <?php endif; ?>

    <div id="carte-evenements" class="carte-lieux"></div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    lassoInitCarteLieux('carte-evenements', points, 'carte-evenements-vue');
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
