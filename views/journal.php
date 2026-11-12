<?php
/** @var array $erreurs */ /** @var array $lignes */ /** @var array $auteurs */ /** @var int $uid */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

// La cible devient un lien quand on sait quel écran la montre : l'identifiant
// nomme son objet (fiche_id → ?p=fiche), ou, pour un simple « id », c'est
// l'objet de la route (fiche_supprimer → ?p=fiche). Une cible supprimée mène
// à un écran qui le dit — c'est encore une information.
$ecrans = ['fiche', 'employe', 'personne', 'structure', 'facture', 'evenement', 'piece', 'projet'];
$lienCible = function (array $l) use ($ecrans): ?string {
    if ($l['cible_id'] === null) {
        return null;
    }
    $objet = $l['cible_type'] === 'id' ? explode('_', (string) $l['route'])[0] : preg_replace('/_id$/', '', (string) $l['cible_type']);
    return in_array($objet, $ecrans, true) ? '?p=' . $objet . '&id=' . (int) $l['cible_id'] : null;
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?php // Les erreurs du serveur (lib/journal.php § Erreurs) — absentes tant
      // qu'il n'y en a pas : une carte vide « aucune erreur » ne dit rien
      // qu'on ait besoin de lire chaque fois. Une ligne par erreur distincte,
      // son compteur dit combien de fois elle est revenue. ?>
<?php if ($erreurs): $nouvelles = count(array_filter($erreurs, fn ($r) => !(int) $r['vue'])); ?>
<div class="card mb-22" id="erreurs">
    <div class="card-head-row">
        <h2 class="mt-0">Erreurs du serveur</h2>
        <?php if ($nouvelles): ?>
        <form method="post" action="?p=journal_erreurs_acquitter" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="btn ghost"><?= icon('check') ?> <span class="lbl">Marquer comme vues</span></button>
        </form>
        <?php endif; ?>
    </div>
    <p class="muted small">Une page qui a planté ou mal fonctionné. Le message s'adresse à qui maintient l'application :
        transmettez-le tel quel, avec la date et ce que vous faisiez.</p>
    <div class="table-scroll">
    <table class="list liste-cartes">
        <thead><tr><th>Dernière fois</th><th class="num">Fois</th><th>Erreur</th><th>Page</th><th>Compte</th></tr></thead>
        <tbody>
        <?php foreach ($erreurs as $r): ?>
            <tr>
                <td class="small nowrap"><?= e(horodatage($r['dernier_le'], 'd.m.Y H:i')) ?>
                    <?= (int) $r['vue'] ? '' : badge('Nouvelle', 'warn') ?></td>
                <td class="num"><?= (int) $r['occurrences'] ?></td>
                <td class="small"><strong><?= e((string) $r['niveau']) ?></strong> — <?= e((string) $r['message']) ?>
                    <span class="muted"><?= e((string) $r['fichier']) ?>:<?= (int) $r['ligne'] ?></span></td>
                <td class="small nowrap"><?= e((string) $r['route']) ?></td>
                <td class="small"><?= $r['utilisateur'] !== '' ? e((string) $r['utilisateur']) : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="toolbar">
    <form method="get" class="filters">
        <input type="hidden" name="p" value="journal">
        <label>Compte
            <select name="utilisateur" data-submit-on-change>
                <option value="0">Tous</option>
                <?php foreach ($auteurs as $a): ?>
                    <option value="<?= (int) $a['utilisateur_id'] ?>" <?= $uid === (int) $a['utilisateur_id'] ? 'selected' : '' ?>><?= e((string) $a['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <span class="muted small toolbar-compte"><?= $pgTotal ?> action<?= $pgTotal > 1 ? 's' : '' ?></span>
</div>

<?php // Chaque enregistrement, suppression ou envoi fait par un compte, relevé
      // par le dispatcher (lib/journal.php) — jamais le contenu saisi. ?>
<div class="table-scroll">
<table class="list liste-cartes">
    <thead><tr><th>Date</th><th>Compte</th><th>Action</th><th>Cible</th><th>Issue</th></tr></thead>
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td colspan="5" class="muted">Aucune action enregistrée pour l'instant.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $l): $lien = $lienCible($l); ?>
        <tr>
            <td class="muted small nowrap"><?= e(horodatage($l['cree_le'], 'd.m.Y H:i')) ?></td>
            <td><?= e((string) $l['utilisateur']) ?></td>
            <td><?= e(journal_libelle((string) $l['route'], (string) $l['geste'])) ?></td>
            <td class="small">
                <?php if ($l['cible_id'] === null): ?><span class="muted">—</span>
                <?php elseif ($lien): ?><a href="<?= e($lien) ?>">n° <?= (int) $l['cible_id'] ?></a>
                <?php else: ?>n° <?= (int) $l['cible_id'] ?><?php endif; ?>
            </td>
            <td><?= $l['issue'] === 'ok' ? badge('Fait', 'ok') : badge('Refusé', 'muted') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/_pagination.php'; ?>
