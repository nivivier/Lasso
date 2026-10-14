<?php /** @var array $p */ /** @var array $structures */ /** @var array $employes */
/** @var array $utilisateurs */ /** @var array $pieces */ /** @var bool $ok */

$id = (int) $p['id'];
$vide = '<span class="muted">—</span>';
$champ = fn (string $c): string => e((string) ($p[$c] ?? ''));
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?= lien_retour_contextuel('?p=personnes', 'Personnes') ?>
<?php if ($ok): ?><p class="ok flash">Fiche enregistrée.</p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title">
        <h1><?= e(personne_nom($p)) ?></h1>
        <?php if ((int) $p['desinscrit'] === 1): ?><?= badge('Désinscrite', 'muted') ?><?php endif; ?>
    </div>
</div>

<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Identité <?= info_tip(
            'Ce qui est écrit ici vaut PARTOUT où cette personne apparaît : dans le carnet de chaque '
            . "structure où elle est contact, sur sa fiche d'employée, sur son compte. C'est tout l'objet "
            . "de l'annuaire — avant, la même personne portait trois noms qui pouvaient diverger."
        ) ?></h2>
        <?= carte_actions_html(['form' => 'personne-form', 'quoi' => 'cette personne']) ?>
    </div>

    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Prénom</th><td><?= $champ('prenom') !== '' ? $champ('prenom') : $vide ?></td></tr>
            <tr><th>Nom</th><td><?= $champ('nom') !== '' ? $champ('nom') : $vide ?></td></tr>
            <tr><th>Adresse électronique</th><td><?= $champ('email') !== '' ? $champ('email') : $vide ?></td></tr>
            <tr><th>Téléphone</th><td><?= $champ('telephone') !== '' ? $champ('telephone') : $vide ?></td></tr>
            <tr><th>Adresse</th><td><?= trim((string) $p['rue'] . ' ' . (string) $p['npa_localite']) !== ''
                ? e(trim((string) $p['rue'])) . ($p['npa_localite'] !== '' ? '<br>' . e((string) $p['npa_localite']) : '')
                : $vide ?></td></tr>
            <tr><th>Date de naissance</th><td><?= $p['date_naissance'] !== '' ? e(date('d.m.Y', strtotime((string) $p['date_naissance']))) : $vide ?></td></tr>
            <tr><th>Langue</th><td><?= $champ('langue') !== '' ? $champ('langue') : $vide ?></td></tr>
            <tr><th>Envois</th><td><?= (int) $p['desinscrit'] === 1 ? 'Désinscrite — ne reçoit plus aucun envoi' : 'Joignable' ?></td></tr>
            <?php if (trim((string) $p['notes']) !== ''): ?>
            <tr><th>Remarques</th><td><?= nl2br(e((string) $p['notes'])) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <form method="post" action="?p=personne_enregistrer" id="personne-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid2">
            <label>Prénom <input name="prenom" value="<?= $champ('prenom') ?>"></label>
            <label>Nom <input name="nom" value="<?= $champ('nom') ?>"></label>
        </div>
        <div class="grid2 mt-16">
            <label>Adresse électronique <input type="email" name="email" value="<?= $champ('email') ?>"></label>
            <label>Téléphone <input name="telephone" value="<?= $champ('telephone') ?>"></label>
        </div>
        <div class="grid2 mt-16">
            <label>Rue <input name="rue" value="<?= $champ('rue') ?>"></label>
            <label>NPA et localité <input name="npa_localite" value="<?= $champ('npa_localite') ?>"></label>
        </div>
        <div class="grid2 mt-16">
            <label>Date de naissance <input type="date" name="date_naissance" value="<?= $champ('date_naissance') ?>"></label>
            <label>Langue <input name="langue" value="<?= $champ('langue') ?>" placeholder="fr, de, en…"></label>
        </div>
        <?php // Le SEUL endroit d'où l'on peut rendre quelqu'un joignable à
              // nouveau. Décocher se fait à la main et en connaissance de
              // cause : personne ne se réinscrit tout seul. ?>
        <label class="check mt-16"><input type="checkbox" name="desinscrit" value="1" <?= (int) $p['desinscrit'] === 1 ? 'checked' : '' ?>> Désinscrite de tous les envois</label>
        <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= $champ('notes') ?></textarea></label>
    </form>
</div>

<?php // Ce qui retient cette personne. Les trois ensemble, sur un écran : ce
      // sont exactement les trois tables qui ne se parlaient pas. ?>
<div class="card mt-22">
    <h2 class="mt-0">Rattachements</h2>
    <?php if (!$structures && !$employes && !$utilisateurs && !$pieces): ?>
        <p class="muted">Cette personne n'est rattachée à rien : ni structure, ni emploi, ni compte.</p>
    <?php endif; ?>

    <?php if ($structures): ?>
    <h3 class="sub no-mt">Structures</h3>
    <table class="kv-table">
        <?php foreach ($structures as $st): ?>
        <tr>
            <th><a href="?p=structure&id=<?= (int) $st['structure_id'] ?>"><?= e((string) $st['structure_nom']) ?></a></th>
            <td>
                <?= $st['role'] !== '' ? e((string) $st['role']) : $vide ?>
                <?php if ((int) $st['est_booking'] === 1): ?><?= badge('Booking', 'ok') ?><?php endif; ?>
                <?php if ((int) $st['est_administration'] === 1): ?><?= badge('Administration', 'ok') ?><?php endif; ?>
                <?php if ((int) $st['actif'] !== 1): ?><?= badge('Inactif', 'muted') ?><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ($employes): ?>
    <h3 class="sub">Emploi</h3>
    <table class="kv-table">
        <?php foreach ($employes as $emp): ?>
        <tr>
            <th><a href="?p=employe&id=<?= (int) $emp['id'] ?>">Fiche d'employé</a></th>
            <td><?= $emp['numero_avs'] !== '' ? e((string) $emp['numero_avs']) : $vide ?>
                <?php if ((int) $emp['actif'] !== 1): ?><?= badge('Inactif', 'muted') ?><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ($utilisateurs): ?>
    <h3 class="sub">Compte</h3>
    <table class="kv-table">
        <?php foreach ($utilisateurs as $u): ?>
        <tr><th><a href="?p=utilisateurs">Compte de connexion</a></th><td><?= e((string) $u['email']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php // Ce qui s'est passé en argent entre elle et l'association : la
          // quittance qu'on lui a remise, la note de frais qu'on lui doit. Son
          // état est celui qu'on lit partout ailleurs (piece_badge_html()), et
          // le lien mène à la pièce elle-même. ?>
    <?php if ($pieces): ?>
    <h3 class="sub">Pièces comptables</h3>
    <table class="kv-table">
        <?php foreach ($pieces as $pc): $pcType = PIECE_TYPES[$pc['type']] ?? PIECE_TYPES['autre']; ?>
        <tr>
            <th><a href="<?= e(url_avec_retour('?p=piece&id=' . (int) $pc['id'], 'personne', $id)) ?>"><?= e((string) $pc['libelle']) ?></a></th>
            <td>
                <span class="muted small"><?= e($pcType['libelle']) ?><?= $pc['numero'] !== '' ? ' · ' . e((string) $pc['numero']) : '' ?></span>
                <span class="<?= (float) $pc['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $pc['montant']) ?></span>
                <?= piece_badge_html($pc) ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
