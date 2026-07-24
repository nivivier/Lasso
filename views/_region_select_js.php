<?php
// Câblage des listes déroulantes Région dépendantes du Pays (taxonomie
// pays_liste, voir migration_49). À inclure une fois par formulaire contenant
// un <select class="region-select"> et un <select class="pays-select"> : quand
// le pays change, les options de région sont reconstruites depuis la carte
// régions-par-pays ; la valeur courante est conservée si elle appartient encore
// au pays choisi, sinon vidée.
?>
<script>
(function () {
    var MAP = <?= json_encode(pays_regions_map(), JSON_UNESCAPED_UNICODE) ?>;
    document.querySelectorAll('select.region-select').forEach(function (regionSel) {
        var form = regionSel.closest('form');
        var paysSel = form && form.querySelector('select.pays-select');
        if (!paysSel) { return; }
        paysSel.addEventListener('change', function () {
            var regions = MAP[paysSel.value] || [];
            var cur = regionSel.value;
            regionSel.innerHTML = '<option value="">— Région —</option>';
            regions.forEach(function (r) {
                var o = document.createElement('option');
                o.value = r;
                o.textContent = r;
                regionSel.appendChild(o);
            });
            regionSel.value = regions.indexOf(cur) >= 0 ? cur : '';
        });
    });
})();
</script>
