<?php /** @var array $campagnes */
// Même rendu de date que la liste des campagnes de booking, dont cet écran est
// le pendant : une campagne de recherche de fonds se lit comme un démarchage.
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?php if (isset($_GET['ok'])): ?><p class="ok flash">Campagne enregistrée.</p><?php endif; ?>

<div class="page-head">
    <?php // « Campagnes » tout court : c'est le bandeau du module qui dit de
          // quelles campagnes il s'agit — exactement comme « Booking » coiffe
          // les siennes. Le nom entier, « campagne de recherche de fonds », ne
          // sert que là où le module n'est pas autour (recherche unifiée). ?>
    <h1>Campagnes</h1>
    <?php if (peut_ecrire('fonds')): ?>
    <div class="head-actions">
        <a class="btn" href="?p=fonds_campagne_form"><?= icon('plus') ?> <span class="lbl">Nouvelle campagne</span></a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$campagnes): ?>
    <p class="muted">Aucune campagne de recherche de fonds pour l'instant.</p>
<?php else: ?>
<div class="card">
    <table class="list">
        <thead>
            <tr><th>Campagne</th><th>Période</th><th class="num">Dossiers</th><th class="num">Obtenu</th><th class="num">Cible</th></tr>
        </thead>
        <tbody>
        <?php foreach ($campagnes as $c): ?>
            <?php $cid = (int) $c['id']; ?>
            <tr class="row-link" tabindex="0" role="link" data-href="?p=fonds_campagne&id=<?= $cid ?>">
                <td class="dash-nom"><a class="titre-lien" href="?p=fonds_campagne&id=<?= $cid ?>"><?= e((string) $c['nom']) ?></a></td>
                <td class="muted small nowrap">
                    <?php $d = $jour($c['date_debut']); $f = $jour($c['date_fin']); ?>
                    <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?>
                </td>
                <td class="num small"><?= (int) $c['nb_deposees'] ?> / <?= (int) $c['nb_demandes'] ?></td>
                <td class="num strong"><?= chf((float) $c['montant_obtenu']) ?></td>
                <td class="num"><?= (float) $c['montant_cible'] > 0 ? chf((float) $c['montant_cible']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div></div>
