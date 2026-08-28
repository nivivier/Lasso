<?php
// Formulaire d'un contact de structure (?p=structure) — MÊME balisage pour
// l'ajout et la modification, ce qui est précisément le point : les deux
// avaient divergé (ordre des champs différent, « Rôle » contre « Autre rôle »,
// des .grid2 imbriqués dans une .grid3 côté ajout qui écrasaient Prénom/Nom sur
// un tiers de largeur).
//
// La classe .form n'est pas décorative : sans elle, aucun <label> de la carte
// Contacts n'hérite du style de champ du site (« .form label », app.css) — ni
// colonne, ni libellé en gris, ni marge basse — et les rangées se collaient.
//
// Variables attendues (préfixe « cf », comme « pt »/« nt » ailleurs : render()
// fait extract($data) dans le même scope) :
//   $cfContact : ligne structure_contacts, ou null pour le formulaire d'ajout ;
//   $cfSid     : id de la structure.
$cfEdition = isset($cfContact) && $cfContact !== null;
$cfC = $cfEdition ? $cfContact : [];
$cfV = fn (string $k) => e((string) ($cfC[$k] ?? ''));
$cfTitre = $cfEdition ? trim(($cfC['prenom'] ?? '') . ' ' . ($cfC['nom'] ?? '')) : 'Nouveau contact';
if ($cfTitre === '') {
    $cfTitre = 'Contact';
}
?>
<form method="post" action="?p=structure_contact_ajouter"
      class="form cadre-edit fieldset-groupe<?= $cfEdition ? ' contact-edit-form' : '' ?>"
      <?= $cfEdition ? '' : 'id="nouveau-contact-form" ' ?>hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="<?= (int) $cfSid ?>">
    <?php if ($cfEdition): ?>
        <input type="hidden" name="contact_id" value="<?= (int) $cfC['id'] ?>">
    <?php endif; ?>

    <?php // Annuler et enregistrer en haut à droite du cadre : le formulaire
          // s'ouvre à la place d'une ligne de liste, ses commandes restent donc
          // là où était la ligne, sans faire chercher le bas du bloc. ?>
    <div class="cadre-edit-head">
        <span class="cadre-edit-titre"><?= e($cfTitre) ?></span>
        <div class="cadre-edit-actions">
            <?php if ($cfEdition): ?>
                <button type="button" class="btn ghost btn-sm icon-only contact-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                <button type="submit" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <?php else: ?>
                <button type="button" class="btn ghost btn-sm icon-only" data-hide="nouveau-contact-form" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                <button type="submit" class="btn btn-sm icon-only" title="Ajouter le contact" aria-label="Ajouter le contact"><?= icon('save') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid2">
        <label><span>Prénom</span><input name="prenom" value="<?= $cfV('prenom') ?>"></label>
        <label><span>Nom</span><input name="nom" value="<?= $cfV('nom') ?>"></label>
    </div>

    <?php // Les deux rôles fonctionnels — qui reçoit les mailings, qui reçoit
          // les factures — et le rôle en clair répondent à la même question :
          // une seule ligne sous un seul libellé, plutôt que deux cases perdues
          // au milieu des champs texte. ?>
    <div class="field-group role-groupe">
        <?php // Une seule infobulle pour les deux cases, portée par le libellé :
              // deux (i) côte à côte dans la même ligne pesaient plus que ce
              // qu'ils expliquaient. ?>
        <span>Rôle <?= info_tip("« Booking » : s'il y a plusieurs contacts, le mailing n'est envoyé qu'à ceux marqués booking. « Facturation » : contact utilisé par défaut pour l'envoi des factures — un seul à la fois par structure.") ?></span>
        <div class="role-ligne">
            <label class="check"><input type="checkbox" name="est_booking" value="1" <?= !empty($cfC['est_booking']) ? 'checked' : '' ?>> Booking</label>
            <label class="check"><input type="checkbox" name="est_administration" value="1" <?= !empty($cfC['est_administration']) ? 'checked' : '' ?>> Facturation</label>
            <input class="role-libre" name="role" value="<?= $cfV('role') ?>" placeholder="Autre" aria-label="Autre rôle">
        </div>
    </div>

    <div class="grid2">
        <label><span>E-mail</span><input name="email" type="email" value="<?= $cfV('email') ?>"></label>
        <label><span>Téléphone</span><input name="telephone" type="tel" value="<?= $cfV('telephone') ?>"></label>
    </div>

    <div class="contact-fin">
        <label class="contact-formulaire"><span>Formulaire de contact</span><input name="formulaire_url" type="url" value="<?= $cfV('formulaire_url') ?>" placeholder="https://…"></label>
        <label class="contact-langue"><span>Langue</span><input name="langue" value="<?= $cfV('langue') ?>" placeholder="FR"></label>
    </div>

    <?php // Supprimer vit en bas du cadre, loin des deux commandes courantes, et
          // pilote un <form> posé À CÔTÉ de celui-ci (attribut form=…) : deux
          // formulaires ne peuvent pas s'imbriquer. Voir views/structure_form.php. ?>
    <?php if ($cfEdition): ?>
    <div class="cadre-edit-pied">
        <button type="submit" form="contact-del-<?= (int) $cfC['id'] ?>" class="btn danger btn-sm"><?= icon('trash') ?> Supprimer le contact</button>
    </div>
    <?php endif; ?>
</form>
