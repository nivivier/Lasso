<?php
// Comportements de la fiche d'un événement — dans assets/evenement.js. Ce
// partiel pose ce que le script doit savoir du serveur, puis le charge à la
// place de l'ancien <script> en ligne : il lit les éléments rendus juste
// au-dessus.
$evenementDonnees = [
    'id'              => (int) $id,
    'aDesPrestations' => (bool) array_filter($prestations),
    'iconeFacture'    => icon('receipt-swiss-franc'),
];
?>
<script type="application/json" id="evenement-donnees"><?= json_encode($evenementDonnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_url('assets/evenement.js')) ?>"></script>
