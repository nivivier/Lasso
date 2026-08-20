<?php /** @var int $enAttente */ /** @var int $envoyes24h */ /** @var int $plafondJour */
/** @var string $traiterUrl */ /** @var array $campagnes */ /** @var mixed $ok */ ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<?php if ($ok !== null && $ok !== ''): ?><p class="ok flash"><?= (int) $ok ?> destinataire(s) ajouté(s) à la file d'attente.</p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">File d'attente</h2>
    <p><strong><?= $enAttente ?></strong> destinataire(s) en attente d'envoi. <strong><?= $envoyes24h ?></strong> / <?= $plafondJour ?> envois dans les dernières 24h.</p>
    <p class="muted small">
        <?php if ($traiterUrl !== ''): ?>
            Traitée automatiquement par le planificateur de tâches de l'hébergeur (URL ci-dessous, à
            configurer une fois pour toutes) — le délai entre e-mails et le plafond journalier se
            règlent dans <a href="?p=emails">Paramètres → E-mails</a>.
        <?php else: ?>
            <?php // Sans droit d'écriture : le renvoi à « l'URL ci-dessous » n'aurait
                  // plus d'objet, puisqu'elle n'est pas affichée. ?>
            Traitée automatiquement par le planificateur de tâches de l'hébergeur.
            Son déclenchement manuel demande le droit d'écriture sur le module Booking.
        <?php endif; ?>
    </p>
    <?php // Vide pour un compte sans droit d'écriture sur booking (voir
          // route_mailing()) : ni l'URL ni le bouton ne sont rendus, le jeton
          // n'existe donc nulle part dans la page. ?>
    <?php if ($traiterUrl !== ''): ?>
    <div class="linked-add">
        <code class="small"><?= e($traiterUrl) ?></code>
        <a class="btn ghost btn-sm" href="<?= e($traiterUrl) ?>" target="_blank" rel="noopener"><?= icon('send') ?> Traiter maintenant</a>
    </div>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Historique des campagnes</h2>
    <?php if (!$campagnes): ?>
        <p class="muted">Aucune campagne pour l'instant.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="list">
        <thead><tr><th>Date</th><th>Sujet</th><th class="num">Ciblés</th><th class="num">Envoyés</th><th class="num">Échecs</th><th class="num">En attente</th><th class="num">Taux d'échec</th></tr></thead>
        <tbody>
        <?php foreach ($campagnes as $c):
            $traites = (int) $c['envoyes'] + (int) $c['echecs'];
            $taux = $traites > 0 ? round((int) $c['echecs'] / $traites * 100) : null;
        ?>
            <tr>
                <td class="muted small nowrap"><?= e(date('d.m.Y H:i', strtotime($c['cree_le']))) ?></td>
                <td><?= e($c['sujet']) ?: '—' ?></td>
                <td class="num"><?= (int) $c['nb_destinataires'] ?></td>
                <td class="num"><?= (int) $c['envoyes'] ?></td>
                <td class="num"><?= (int) $c['echecs'] > 0 ? '<span class="montant-neg">' . (int) $c['echecs'] . '</span>' : '0' ?></td>
                <td class="num"><?= (int) $c['attente'] > 0 ? (int) $c['attente'] : '—' ?></td>
                <td class="num"><?= $taux === null ? '—' : ($taux > 0 ? '<span class="montant-neg">' . $taux . '&nbsp;%</span>' : '0&nbsp;%') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
