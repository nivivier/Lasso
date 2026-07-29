<?php
// Câblage des listes déroulantes Région dépendantes du Pays (taxonomie
// pays_liste, voir migration_49). À inclure une fois par formulaire contenant
// un <select class="region-select"> (grande région), un <select class="pays-select">
// et un <input name="departement_canton"> : quand le pays change,
// les options de région sont reconstruites depuis la carte régions-par-pays ;
// la valeur courante est conservée si elle appartient encore au pays choisi,
// sinon vidée. Le select pays a pour valeur soit le NOM du pays
// (structures/lieux, pays_options_nom()) soit son CODE ISO2 (événements,
// pays_options_code()) — PAYS_CODE_VERS_NOM normalise vers le nom dans les
// deux cas avant toute recherche dans MAP (indexée par nom).
//
// Auto-remplissage de la grande région à partir du département/canton saisi
// (voir grande_region_deduite(), lib/helpers.php — même référentiel FR/CH) :
// retour visuel immédiat, purement cosmétique — la valeur réellement
// enregistrée est toujours recalculée par le serveur à la sauvegarde. Un
// canton bilingue (Fribourg/Valais/Berne) préremplit un défaut mais ne
// verrouille jamais le select ; les autres départements/cantons reconnus le
// désactivent (grisé).
?>
<script>
(function () {
    var MAP = <?= json_encode(pays_regions_map(), JSON_UNESCAPED_UNICODE) ?>;
    var PAYS_CODE_VERS_NOM = <?= json_encode(array_column(pays_liste(), 'nom', 'code_iso2'), JSON_UNESCAPED_UNICODE) ?>;
    var DEPARTEMENTS = <?= json_encode(departements_regions_map(), JSON_UNESCAPED_UNICODE) ?>;
    var CANTONS = <?= json_encode(CANTONS_SUISSES_REGIONS, JSON_UNESCAPED_UNICODE) ?>;
    var CANTONS_BILINGUES = <?= json_encode(CANTONS_SUISSES_BILINGUES, JSON_UNESCAPED_UNICODE) ?>;
    var paysNom = function (v) { return PAYS_CODE_VERS_NOM[v] || v; };

    document.querySelectorAll('select.region-select').forEach(function (regionSel) {
        var form = regionSel.closest('form');
        var paysSel = form && form.querySelector('select.pays-select');
        var deptInput = form && form.querySelector('input[name="departement_canton"]');
        if (!paysSel) { return; }

        paysSel.addEventListener('change', function () {
            var regions = MAP[paysNom(paysSel.value)] || [];
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

        if (!deptInput) { return; }
        var appliquerDeduction = function () {
            var pays = paysNom(paysSel.value.trim());
            var dept = deptInput.value.trim();
            var deduite = null;
            var verrouille = false;
            if (pays === 'France') {
                deduite = DEPARTEMENTS[dept] || null;
                verrouille = deduite !== null;
            } else if (pays === 'Suisse') {
                var code = dept.toUpperCase();
                if (CANTONS[code]) {
                    deduite = CANTONS[code];
                    verrouille = true;
                } else if (CANTONS_BILINGUES[code]) {
                    deduite = CANTONS_BILINGUES[code];
                }
            }
            regionSel.disabled = verrouille;
            if (deduite !== null) {
                var opt = Array.from(regionSel.options).find(function (o) { return o.value === deduite; });
                if (!opt) {
                    opt = new Option(deduite, deduite);
                    regionSel.add(opt);
                }
                regionSel.value = deduite;
            }
        };
        deptInput.addEventListener('input', appliquerDeduction);
        paysSel.addEventListener('change', appliquerDeduction);
        appliquerDeduction();
    });
})();
</script>
