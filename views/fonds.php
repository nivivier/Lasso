<?php
/** @var array $groupes */ /** @var bool $vide */
// La liste des campagnes de recherche de fonds. Même charpente que
// ?p=campagnes, dont c'est le pendant : la zone du module, une barre d'outils,
// puis le tableau d'un bord à l'autre de cette zone. L'onglet actif nomme la
// page, elle n'a donc pas de titre à elle.
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <div class="head-actions">
            <?= info_tip(
                "Une campagne de recherche de fonds est une sélection de bailleurs à solliciter pour un projet,
                entre deux dates. Chaque bailleur y a son dossier : ce qu'on lui demande, ce qu'il exige, ce
                qu'il a répondu. La jauge se compte en francs — obtenu, en attente, reste à trouver."
            ) ?>
            <?php if (peut_ecrire('fonds')): ?>
            <a class="btn" href="?p=fonds_campagne_form"><?= icon('plus') ?> Nouvelle campagne</a>
            <?php endif; ?>
        </div>
    </div>

<?php if (isset($_GET['ok'])): ?><p class="ok flash">Campagne enregistrée.</p><?php endif; ?>

<?php if ($vide): ?>
    <p class="muted">Aucune campagne de recherche de fonds pour l'instant.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr>
            <th>Campagne</th>
            <th class="nowrap col-periode">Période</th>
            <th>Avancement</th>
            <th class="num nowrap">Dossiers</th>
        </tr>
    </thead>
    <tbody>
    <?php // Trois tranches, séparées comme les mois d'une liste de dates : ce
          // qui court, ce qui vient, ce qui est derrière. ?>
    <?php foreach ($groupes as $groupe): ?>
        <tr class="mois-sep"><td colspan="4"><?= e($groupe['titre']) ?></td></tr>
        <?php foreach ($groupe['campagnes'] as $c): $cid = (int) $c['id']; ?>
        <?php
            $enCours = periode_courante($c);
            $parts = [
                'obtenu'   => r2((float) $c['montant_obtenu']),
                'attente'  => r2((float) $c['montant_en_attente']),
                'aTrouver' => 0.0,
                'base'     => 0.0,
            ];
            $cible = (float) $c['montant_cible'];
            $base = $cible > 0 ? $cible : $parts['obtenu'] + $parts['attente'];
            $parts['aTrouver'] = r2(max(0, $base - $parts['obtenu'] - $parts['attente']));
            $parts['base'] = r2($base);
        ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fonds_campagne&id=<?= $cid ?>">
            <?php // La couleur d'accent est réservée à ce qui demande du travail :
                  // une campagne dont la saison court. Passée ou à venir, son nom
                  // s'écrit à l'encre — il reste un lien, il n'appelle plus. ?>
            <td><a class="strong<?= $enCours ? '' : ' lien-encre' ?>" href="?p=fonds_campagne&id=<?= $cid ?>"><?= e((string) $c['nom']) ?></a></td>
            <td class="muted small nowrap col-periode">
                <?php $d = $jour($c['date_debut']); $f = $jour($c['date_fin']); ?>
                <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?>
            </td>
            <td class="camp-avancement">
                <?= fonds_barre_html($parts, 'camp-barre-liste') ?>
                <span class="camp-avancement-txt"><b><?= chf($parts['obtenu']) ?></b><?= $cible > 0 ? ' / ' . chf($cible) : '' ?></span>
            </td>
            <td class="num small nowrap"><?= (int) $c['nb_deposees'] ?> / <?= (int) $c['nb_demandes'] ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div></div>
