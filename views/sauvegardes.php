<?php
/** @var string $err */ /** @var string $ok */ /** @var array $copies */
/** @var string $derniereSauvegarde */ /** @var int $rappelJours */
/** @var string $errRestaure */ /** @var string $motifRestaure */ /** @var string $carteRestaure */

// Sauvegardes (administrateurs) : deux objets, une moitié de page chacun.
// D'abord la SAUVEGARDE — l'archive qu'on télécharge, son rappel, sa
// restauration —, puis les COPIES que le serveur garde, qu'on crée,
// restaure et supprime sur leur propre carte. Les deux mots — « sauvegarde »,
// « copie » — sont tenus partout, boutons compris. Rangées par objet et non par danger : depuis
// qu'une copie se restaure aussi, « Restaurer » n'est plus le seul geste
// qui remplace la base, et le mettre en dernier séparait l'archive de sa
// restauration. Le pourquoi de la page à part : route_sauvegardes() (lib/routes.php).
//
// Le format dépend de ce que sait faire le serveur (lib/sauvegarde.php) : une
// archive quand l'extension zip ou phar est là — le cas courant —, la base
// seule sinon, auquel cas la carte le dit au lieu de laisser croire que les
// fichiers déposés sont dedans.
$formatSauvegarde = sauvegarde_format();
$chiffrementPossible = sauvegarde_chiffrement_possible($formatSauvegarde);
$maxTeleversement = televersement_max();

// Le refus d'une restauration, sur la carte d'où elle est partie (un fichier
// téléversé ou une copie du serveur). Une restauration réussie ne revient
// jamais ici : elle déconnecte, et l'écran de connexion l'annonce.
$refusRestauration = function (string $carte) use ($errRestaure, $motifRestaure, $carteRestaure, $maxTeleversement): string {
    if ($errRestaure === '' || $carte !== $carteRestaure) {
        return '';
    }
    if ($errRestaure === 'taille') {
        return '<p class="err mb-8">Le fichier dépasse ce que ce serveur accepte de recevoir'
            . ($maxTeleversement ? ' (' . e(fichier_taille_lisible($maxTeleversement)) . ')' : '') . '. '
            . 'Rien n\'a été touché. Posez l\'archive sur le serveur par FTP et suivez la note <code>SAUVEGARDE.txt</code> qu\'elle contient.</p>';
    }
    if ($errRestaure === 'refus') {
        return '<p class="err mb-8">Sauvegarde refusée : ' . e($motifRestaure) . '<br>'
            . '<strong>Rien n\'a été remplacé</strong> — la sauvegarde est vérifiée avant que la base ne soit touchée.</p>';
    }
    return $errRestaure === 'fichier' ? '<p class="err mb-8">Aucun fichier reçu. Rien n\'a été touché.</p>' : '';
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($ok === 'rappel'): ?><p class="ok flash">Délai de rappel enregistré.</p><?php endif; ?>
<?php if ($err === 'rappel'): ?><p class="err flash">Indiquez un nombre de jours entre <?= SAUVEGARDE_RAPPEL_MIN ?> et <?= SAUVEGARDE_RAPPEL_MAX ?>.</p><?php endif; ?>
<?php if ($err === 'phrase'): ?><p class="err flash">Phrase secrète manquante, trop courte ou différente d'une saisie à l'autre — il faut au moins <?= SAUVEGARDE_PHRASE_MIN ?> caractères. Rien n'a été téléchargé.</p><?php endif; ?>

<div class="card form" id="telecharger">
    <div class="card-head-row">
        <h2 class="mt-0">Télécharger une sauvegarde</h2>
        <?php // Le bouton en tête, comme « Créer une copie » plus bas : la case
              // « Chiffrer » et la phrase, dans le corps de la carte, lui sont
              // rattachées par form=. ?>
        <div class="exp-tete-actions">
            <button type="submit" form="sauvegarde-form"><?= icon('download') ?> <span class="lbl">Télécharger (.<?= e($formatSauvegarde) ?>)</span></button>
        </div>
    </div>
    <form method="post" action="?p=sauvegarde" id="sauvegarde-form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    </form>
    <?php if ($err === 'sauvegarde'): ?>
    <p class="err mb-8">La sauvegarde n'a pas pu être créée — disque plein, droits insuffisants, ou archive impossible à finaliser.
        Le détail est dans le journal. Rien n'a été téléchargé.</p>
    <?php endif; ?>
    <?php // Ce qu'il y a dedans, ce qui manque, puis la seule option — juste
          // sous la description, près du bouton qu'elle modifie. Le détail
          // technique reste dans le README §6.
          //
          // « Tous les modules, même désactivés » : vrai, un module éteint
          // garde ses tables et VACUUM INTO copie la base entière sans en
          // énumérer aucune. Pas de liste des modules : écrite à la main,
          // elle avait vieilli de trois chantiers. ?>
    <p class="small mb-8">Toute la base (tous les modules, même désactivés, les paramètres et les comptes)<?php
        if ($formatSauvegarde !== 'sqlite'): ?>, les fichiers déposés (logos, photos, icônes, feuilles SUISA,
        pièces jointes) et une note expliquant comment restaurer. Seule la configuration du serveur,
        <code>lib/config.local.php</code>, n'y est pas : conservez-la à part.<?php else: ?>.<?php endif; ?></p>
    <?php if ($formatSauvegarde === 'sqlite'): ?>
    <p class="warn small mb-8"><?= icon('info') ?> <strong>Les fichiers déposés n'y sont pas</strong> (ce serveur
        ne sait pas créer d'archive) : copiez à part les dossiers <code>uploads/</code> et <code>data/fichiers/</code>.</p>
    <?php endif; ?>

    <?php if ($chiffrementPossible): ?>
    <?php // La phrase part en POST, jamais dans l'URL (route_sauvegarde()), et
          // n'est gardée nulle part : perdue, l'archive l'est aussi — d'où la
          // double saisie. Case décochée, les champs sont désactivés : rien
          // n'est envoyé. ?>
    <div class="sauvegarde-chiffrer">
        <label class="check"><input type="checkbox" name="chiffrer" value="1" form="sauvegarde-form" id="sauvegarde-chiffrer"> Chiffrer la sauvegarde</label>
        <div id="sauvegarde-phrase" hidden>
            <div class="grid2">
                <label>Phrase secrète
                    <input type="password" name="phrase" form="sauvegarde-form" minlength="<?= SAUVEGARDE_PHRASE_MIN ?>" autocomplete="new-password" disabled></label>
                <label>Phrase secrète, une seconde fois
                    <input type="password" name="phrase_confirmation" form="sauvegarde-form" autocomplete="new-password" disabled></label>
            </div>
            <p class="muted small mb-0">L'archive ne s'ouvrira qu'avec cette phrase, qui n'est conservée nulle part :
                notez-la hors de Lasso, sans elle la sauvegarde est perdue.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php // ⚠️ Vaut pour TOUS les formats, chiffrée ou non : il dit ce qui reste
          // lisible. Les mots de passe des boîtes d'envoi sont chiffrés avec une
          // clé qui reste sur le serveur (lib/secrets.php), ceux des comptes ne
          // sont qu'en empreinte. La conséquence d'une restauration ailleurs est
          // dite sur la carte « Restaurer », là où elle se produit. ?>
    <p class="warn small mt-16 mb-0"><?= icon('info') ?> <strong>À traiter comme un document confidentiel</strong> :
        les salaires et les adresses y sont lisibles, les mots de passe non.</p>
</div>

<?php // Sa propre carte, entre la sauvegarde et les copies : quand on en a
      // téléchargé une, et quand le tableau de bord le rappellera. Un seul
      // relevé, pas un historique : le titre dit ce que la carte règle. ?>
<div class="card form mt-22" id="rappel">
    <h2 class="mt-0">Rappel de sauvegarde</h2>
    <?php // La date est celle que le tableau de bord compare au délai
          // ci-dessous : notée quand l'archive part (route_sauvegarde()). ?>
    <?php $tsSauvegarde = horodatage_ts($derniereSauvegarde); ?>
    <p class="small mb-0" id="sauvegarde-derniere">
        <?php if ($tsSauvegarde !== null): ?>
            Dernière sauvegarde téléchargée le <strong><?= date('d.m.Y', $tsSauvegarde) ?></strong> à <?= date('H:i', $tsSauvegarde) ?>
            <?php // En jours, l'unité du délai de rappel juste en dessous. ?>
            <?php $joursSauvegarde = intdiv(max(0, time() - $tsSauvegarde), 86400); ?>
            <span class="muted">(<?= $joursSauvegarde === 0 ? 'aujourd\'hui' : ($joursSauvegarde === 1 ? 'hier' : 'il y a ' . $joursSauvegarde . ' jours') ?>)</span>.
        <?php else: ?>
            Aucune sauvegarde téléchargée pour l'instant.
        <?php endif; ?>
    </p>
    <form method="post" action="?p=sauvegarde_rappel_enregistrer" class="sauvegarde-rappel" id="sauvegarde-rappel-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Rappel sur le tableau de bord après
            <span class="champ-unite"><input type="number" name="jours" min="<?= SAUVEGARDE_RAPPEL_MIN ?>" max="<?= SAUVEGARDE_RAPPEL_MAX ?>" required
                value="<?= (int) $rappelJours ?>"> jours sans sauvegarde</span>
        </label>
        <button type="submit" class="btn ghost"><?= icon('check') ?> Enregistrer le délai</button>
    </form>
</div>

<?php // Restaurer : le geste qui REMPLACE la base entière, depuis une archive
      // téléchargée. Bouton rouge, et une confirmation — rien
      // à simuler, on ne prévisualise pas un remplacement intégral. Trois
      // points, dans l'ordre des inquiétudes : ce qui est remplacé, ce qui
      // n'est pas perdu, ce qui peut surprendre. Une restauration réussie ne
      // revient jamais ici : elle déconnecte, et l'écran de connexion l'annonce. ?>
<div class="card form mt-22" id="restaurer">
    <h2 class="mt-0">Restaurer une sauvegarde</h2>
    <?= $refusRestauration('restaurer') ?>
    <ul class="warn small sauvegarde-avertissements">
        <li><strong>Remplace toute la base</strong>, comptes de connexion compris : vous serez déconnecté,
            reconnectez-vous avec un compte de la sauvegarde.</li>
        <li><strong>Rien n'est perdu</strong> : l'archive est vérifiée d'abord, puis la base actuelle est mise de côté
            dans les copies du serveur. Logos, photos et pièces jointes de l'archive s'<strong>ajoutent</strong> aux
            fichiers existants, sans en remplacer aucun.</li>
        <li>Une sauvegarde plus ancienne est mise à jour automatiquement. Restaurée sur un autre serveur, elle
            demande de ressaisir les mots de passe des boîtes d'envoi.</li>
    </ul>
    <form method="post" action="?p=sauvegarde_restaurer" enctype="multipart/form-data"
          data-confirm="Remplacer TOUTE la base par cette sauvegarde ?&#10;&#10;La base actuelle sera d'abord mise de côté dans une nouvelle copie. Vous serez déconnecté.">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="grid2">
            <label>Archive à restaurer
                <input type="file" name="fichier" required accept=".zip,.gz,.tar.gz,.sqlite,application/zip,application/gzip">
                <span class="muted small"><code>.zip</code>, <code>.tar.gz</code> ou <code>.sqlite</code><?php
                    if ($maxTeleversement): ?>, jusqu'à <?= e(fichier_taille_lisible($maxTeleversement)) ?><?php endif; ?></span>
            </label>
            <?php // Seule une archive chiffrée la lit (sauvegarde_extraire()) ;
                  // laissée vide, elle est ignorée. ?>
            <label>Phrase secrète, si la sauvegarde en a une
                <input type="password" name="phrase" autocomplete="off"></label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn danger"><?= icon('import') ?> Restaurer la sauvegarde</button>
        </div>
    </form>
</div>

<?php // Les copies que l'application garde d'elle-même à côté de la base
      // (sauvegarder_base() : avant un import, un recalcul, une mise à jour,
      // une clôture…), plus celles qu'on force ici. On les liste, on en
      // restaure une (route_sauvegarde_copie_restaurer()), on les supprime.
      // La corbeille ne se montre qu'au crayon (docs/UI.md § 3),
      // la création reste à portée. ?>
<div class="card section-editable mt-22" id="copies-serveur">
    <div class="card-head-row">
        <h2 class="mt-0">Copies sur le serveur</h2>
        <div class="exp-tete-actions">
            <form method="post" action="?p=sauvegarde_copie_enregistrer" class="d-inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="btn ghost"><?= icon('save') ?> <span class="lbl">Créer une copie</span></button>
            </form>
            <?php if ($copies): ?>
            <button type="button" class="btn ghost icon-only edit-toggle-btn" title="Modifier" aria-label="Modifier les copies"><?= icon('pencil') ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($ok === 'copie'): ?><p class="ok flash">Copie de la base enregistrée sur le serveur.</p><?php endif; ?>
    <?php if ($ok === 'copie_supprimee'): ?><p class="ok flash">Copie supprimée.</p><?php endif; ?>
    <?php if ($err === 'copie'): ?><p class="err flash">La copie n'a pas pu être créée — espace disque ou droits du dossier de données.</p><?php endif; ?>
    <?php if ($err === 'copie_supprimee'): ?><p class="err flash">Cette copie n'existe plus ou n'a pas pu être supprimée.</p><?php endif; ?>
    <?php if ($err === 'copie_restauree'): ?><p class="err">Cette copie n'existe plus. Rien n'a été touché.</p><?php endif; ?>
    <?= $refusRestauration('copies-serveur') ?>
    <p class="muted small">La base seule, sans les fichiers déposés. Lasso en pose une avant chaque opération risquée
        — import, recalcul, clôture, restauration… — et garde les <?= (int) SAUVEGARDES_AUTO_MAX ?> plus récentes.</p>
    <?php if (!$copies): ?>
        <p class="muted mb-0">Aucune copie sur le serveur.</p>
    <?php else: ?>
    <?php // La copie posée par une restauration, quand rien n'est venu après
          // elle : la restaurer, c'est annuler cette restauration — le bouton
          // le dit. Une copie plus récente (une clôture, un import) annulerait
          // davantage : le libellé ordinaire reprend alors. ?>
    <?php $annulable = preg_match('/_avant_restauration_[0-9a-f]+_\d{8}_\d{6}\.sqlite\.bak$/', $copies[0]['nom']) ? $copies[0]['nom'] : ''; ?>
    <div class="table-scroll">
    <table class="list cartes-chiffres cartes-copies">
        <thead><tr><th>Date</th><th>Motif</th><th class="num">Taille</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($copies as $c): ?>
            <?php $quand = date('d.m.Y à H:i', $c['date']); $annule = $c['nom'] === $annulable; ?>
            <tr>
                <?php // Sur téléphone, la date passe sous le motif, avec la taille
                      // (.cartes-copies) : une ligne, pas trois colonnes de biais. ?>
                <td class="nowrap col-quand"><?= e(date('d.m.Y H:i', $c['date'])) ?><span class="copie-taille-carte"> · <?= e(fichier_taille_lisible($c['taille'])) ?></span></td>
                <?php // Le nom du fichier sert à qui cherche la copie par FTP :
                      // au survol, pas en clair dans la colonne. ?>
                <td class="col-motif" title="<?= e($c['nom']) ?>"><?= e($c['libelle']) ?></td>
                <td class="num nowrap col-taille"><?= e(fichier_taille_lisible($c['taille'])) ?></td>
                <td class="actions nowrap">
                    <?php // Restaurer remplace toute la base : rouge, comme le
                          // bouton de la carte « Restaurer » — le même geste, le
                          // même signal. Toujours à portée : c'est l'usage d'une
                          // copie. Supprimer, au crayon seulement (docs/UI.md § 3). ?>
                    <form method="post" action="?p=sauvegarde_copie_restaurer" class="d-inline"
                          data-confirm="<?= $annule
                              ? 'Annuler la dernière restauration ?&#10;&#10;La base revient à son état du ' . e($quand) . ', juste avant la restauration.'
                              : 'Remplacer TOUTE la base par la copie du ' . e($quand) . ' (' . e($c['libelle']) . ') ?' ?>&#10;&#10;La base actuelle sera d'abord mise de côté dans une nouvelle copie ; les fichiers déposés ne changent pas. Vous serez déconnecté.">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="nom" value="<?= e($c['nom']) ?>">
                        <button type="submit" class="btn danger btn-sm" title="<?= $annule ? 'Revenir à la base d\'avant la dernière restauration' : 'Restaurer cette copie' ?>"><?= icon($annule ? 'history' : 'import') ?> <span class="lbl"><?= $annule ? 'Annuler la restauration' : 'Restaurer' ?></span></button>
                    </form>
                    <form method="post" action="?p=sauvegarde_copie_supprimer" class="d-inline edit-only"
                          data-confirm="Supprimer la copie du <?= e($quand) ?> ? Le fichier sera effacé du serveur.">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="nom" value="<?= e($c['nom']) ?>">
                        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer cette copie"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// « Chiffrer la sauvegarde » : la case montre la phrase et l'exige.
(function () {
    var chiffrer = document.getElementById('sauvegarde-chiffrer');
    if (!chiffrer) return;
    var bloc = document.getElementById('sauvegarde-phrase');
    var champs = bloc.querySelectorAll('input');
    var bascule = function () {
        bloc.hidden = !chiffrer.checked;
        champs.forEach(function (c) { c.disabled = !chiffrer.checked; c.required = chiffrer.checked; });
        if (chiffrer.checked) champs[0].focus();
    };
    chiffrer.addEventListener('change', bascule);
    if (chiffrer.checked) bascule(); // case restée cochée au retour arrière
})();
</script>
