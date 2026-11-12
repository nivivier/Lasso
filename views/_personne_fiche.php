<?php
// La fiche d'une personne, sans le décor de page. Sortie de
// views/personne.php pour que la VUE DEUX COLONNES (?p=personnes&vue=duo)
// rende exactement la même fiche dans sa colonne de droite — une seule fiche
// à tenir, deux endroits où elle paraît. Même procédé que
// _structure_fiche.php. L'appelant (route_personne() ou route_personnes() en
// mode duo, toutes deux via personne_donnees_vue()) a déjà fourni les
// données ; il pose lui-même le bandeau et .module-content.
/** @var array $p */ /** @var array $structures */ /** @var array $employes */
/** @var array $utilisateurs */ /** @var array $pieces */ /** @var bool $ok */
$id = (int) $p['id'];
// Sans identifiant : la fiche en CRÉATION (route_personne()). L'écran EST alors
// un formulaire — Enregistrer et Annuler dans l'en-tête, comme une nouvelle
// structure —, et il n'y a encore rien à quoi la rattacher.
$creation = $id === 0;
$err = $err ?? null;
$vide = '<span class="muted">—</span>';
$champ = fn (string $c): string => e((string) ($p[$c] ?? ''));
?>
<?php // Pas de retour vers la liste dans le volet droit de la vue deux
      // colonnes : elle est juste à gauche (views/_duo.php, $duoVolet). ?>
<?php if (empty($duoVolet)): ?><?= lien_retour_contextuel('?p=personnes', 'Personnes') ?><?php endif; ?>
<?php if ($ok): ?><p class="ok flash">Fiche enregistrée.</p><?php endif; ?>

<div class="page-head page-head-bande">
    <div class="page-head-title">
        <h1><?= $creation ? 'Nouvelle personne' : e(personne_nom($p)) ?></h1>
        <?php if ((int) $p['desinscrit'] === 1): ?><?= badge('Désinscrite', 'muted') ?><?php endif; ?>
    </div>
    <?php if ($creation): ?>
    <?= entete_form_actions_html('personne-form', '?p=personnes') ?>
    <?php endif; ?>
</div>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php // Deux colonnes dès que la largeur le permet : l'identité prend la
      // place, les rattachements se rangent à côté, à la largeur d'une
      // mini-fiche. Faute de place, les deux s'empilent comme avant — c'est le
      // flex-wrap qui le fait, sans point de rupture : la fiche vit aussi dans
      // le volet droit de la vue deux colonnes, dont la largeur n'a rien à voir
      // avec celle de la fenêtre. ?>
<div class="fiche-colonnes">
<div class="card<?= $creation ? ' form' : ' card-editable' ?> fiche-col-large">
    <div class="card-head-row">
        <h2 class="mt-0">Identité</h2>
        <?php if (!$creation): ?><?= carte_actions_html(['form' => 'personne-form', 'quoi' => 'cette personne']) ?><?php endif; ?>
    </div>

    <?php if (!$creation): ?>
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
    <?php endif; ?>

    <form method="post" action="?p=personne_enregistrer" id="personne-form" class="<?= $creation ? 'form' : 'card-edit form' ?>"<?= $creation ? '' : ' hidden' ?>>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid2">
            <label>Prénom <input name="prenom" value="<?= $champ('prenom') ?>"<?= $creation ? ' autofocus' : '' ?>></label>
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
        <?php if (!$creation): ?>
        <label class="check mt-16"><input type="checkbox" name="desinscrit" value="1" <?= (int) $p['desinscrit'] === 1 ? 'checked' : '' ?>> Désinscrite de tous les envois</label>
        <?php endif; ?>
        <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= $champ('notes') ?></textarea></label>
    </form>
</div>

<?php // Ce qui retient cette personne. Les trois ensemble, sur un écran : ce
      // sont exactement les trois tables qui ne se parlaient pas. ?>
<?php if (!$creation): ?>
<?php $peutLier = peut_ecrire('carnet'); ?>
<div class="card fiche-col-etroite">
  <div class="card-block section-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Rattachements</h2>
        <?php // Seules les STRUCTURES se lient et se délient ici : l'emploi et
              // le compte se créent depuis leur propre écran, où ils ont tout
              // ce qui leur manque (n° AVS, droits). Même geste que les
              // « Structures liées » d'une fiche structure (§ 2b et § 5). ?>
        <?php if ($peutLier): ?>
        <button type="button" class="btn ghost icon-only edit-toggle-btn" title="Modifier" aria-label="Modifier les rattachements"><?= icon('pencil') ?></button>
        <?php endif; ?>
    </div>
    <?php if (!$structures && !$employes && !$utilisateurs && !$pieces): ?>
        <p class="muted read-only" id="personne-rien">Cette personne n'est rattachée à rien : ni structure, ni emploi, ni compte.</p>
    <?php endif; ?>

    <?php // Des mini-cartes, et non un tableau clé-valeur : ce sont des
          // ENREGISTREMENTS qu'on liste ici, pas les champs d'un seul. Même
          // idiome que la liste des structures en cartes — le nom en grand,
          // son rôle en dessous, les pastilles à droite — pour qu'une
          // structure se reconnaisse d'un écran à l'autre. Une seule classe
          // (.mini-cartes) pour les quatre listes : elles disent la même
          // chose, un lien et de quoi il retourne. ?>
    <?php if ($structures || $peutLier): ?>
    <h3 class="sub no-mt<?= $structures ? '' : ' edit-only' ?>">Structures</h3>
    <?php // La MÊME mini-fiche que la carte « Organisation » d'un événement et
          // que la liste des structures en cartes (views/_structure_mini.php) :
          // statut, ville, drapeau, catégorie, dates, étiquettes. Ce que la
          // relation ajoute — le rôle, « Booking », « Administration » — lui est
          // passé à part : la structure ne le connaît pas. Une ligne vit dans
          // _personne_structure_ligne.php, que la route de liaison rend aussi. ?>
    <ul class="mini-structures" id="personne-structures">
        <?php foreach ($structures as $st) {
            (function () use ($st, $id, $peutLier) { $pid = $id; require __DIR__ . '/_personne_structure_ligne.php'; })();
        } ?>
    </ul>
    <?php if ($peutLier): ?>
    <?php if (!$structures): ?><p class="muted small edit-only" id="personne-structures-vide">Aucune structure.</p><?php endif; ?>
    <form method="post" action="?p=personne_structure_lier" class="linked-add edit-only mt-10"
          data-ajout="#personne-structures" data-ajout-vide="#personne-structures-vide" data-ajout-message="Structure liée.">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="personne_id" value="<?= $id ?>">
        <div class="cat-search" data-cat-search data-texte-vide data-vider-en-saisie>
            <input type="text" class="cat-search-input" placeholder="Rechercher une structure…" autocomplete="off" aria-label="Structure à lier">
            <input type="hidden" name="structure_id" class="cat-search-val" value="">
            <ul class="cat-search-list" hidden role="listbox">
                <?php foreach ($structuresDispo ?? [] as $sd): ?>
                    <li data-val="<?= (int) $sd['id'] ?>"><?= e((string) $sd['nom']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <input type="text" name="role" placeholder="Rôle (facultatif)" aria-label="Rôle dans la structure">
        <button type="submit" class="btn ghost" title="Lier" aria-label="Lier cette structure"><?= icon('link') ?><span class="lbl"> Lier</span></button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($employes): ?>
    <h3 class="sub">Emploi</h3>
    <ul class="mini-cartes">
        <?php foreach ($employes as $emp): ?>
        <li<?= (int) $emp['actif'] !== 1 ? ' class="inactif"' : '' ?>>
            <a class="mini-carte-nom titre-lien" href="?p=employe&id=<?= (int) $emp['id'] ?>">Fiche d'employé</a>
            <?php if ($emp['numero_avs'] !== ''): ?><span class="mini-carte-detail">N° AVS <?= e(avs_affiche((string) $emp['numero_avs'])) ?></span><?php endif; ?>
            <span class="mini-carte-cote">
                <?php if ((int) $emp['actif'] !== 1): ?><?= badge('Inactif', 'muted') ?><?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($utilisateurs): ?>
    <h3 class="sub">Compte</h3>
    <ul class="mini-cartes">
        <?php foreach ($utilisateurs as $u): ?>
        <li>
            <a class="mini-carte-nom titre-lien" href="?p=utilisateurs">Compte de connexion</a>
            <span class="mini-carte-detail"><?= e((string) $u['email']) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php // Ce qui s'est passé en argent entre elle et l'association : la
          // quittance qu'on lui a remise, la note de frais qu'on lui doit. Son
          // état est celui qu'on lit partout ailleurs (piece_badge_html()), et
          // le lien mène à la pièce elle-même. ?>
    <?php if ($pieces): ?>
    <h3 class="sub">Pièces comptables</h3>
    <ul class="mini-cartes">
        <?php foreach ($pieces as $pc): $pcType = PIECE_TYPES[$pc['type']] ?? PIECE_TYPES['autre']; ?>
        <li>
            <a class="mini-carte-nom titre-lien" href="<?= e(url_avec_retour('?p=piece&id=' . (int) $pc['id'], 'personne', $id)) ?>"><?= e((string) $pc['libelle']) ?></a>
            <span class="mini-carte-detail"><?= e($pcType['libelle']) ?><?= $pc['numero'] !== '' ? ' · ' . e((string) $pc['numero']) : '' ?></span>
            <span class="mini-carte-cote">
                <span class="<?= (float) $pc['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $pc['montant']) ?></span>
                <?= piece_badge_html($pc) ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>
