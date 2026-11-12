<?php
// Comportements de la liste des écritures : lettrage en ligne, barre groupée,
// panneau de ventilation analytique, en-tête collant — dans
// assets/compta_ecritures.js. Ce partiel ne pose plus que ce que le script
// doit savoir du serveur, puis le charge, à la place exacte de l'ancien
// <script> en ligne (il lit les éléments rendus juste au-dessus).
$donneesEcritures = [
    'modeClient' => (bool) $modeClient,
    'catFiltre'  => array_values((array) $categorieFilter),
    'csrf'       => csrf_token(),
    'axes'       => array_map(fn($a) => ['id' => (int) $a['id'], 'label' => ($a['code'] !== '' ? $a['code'] : $a['libelle'])], $axes),
    'icones'     => ['pencil' => icon('pencil'), 'plus' => icon('plus'), 'x' => icon('x')],
];
?>
<script type="application/json" id="compta-ecritures-donnees"><?= json_encode($donneesEcritures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_url('assets/compta_ecritures.js')) ?>"></script>
