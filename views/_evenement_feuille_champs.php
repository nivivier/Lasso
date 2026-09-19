<?php
// Les champs d'un élément de feuille de route, selon son type. Partagé par le
// formulaire d'ajout et par celui de modification, pour que les deux proposent
// exactement la même chose — c'est la même ligne qu'ils écrivent.
//
// Les champs sont posés à plat, sans grille : c'est le formulaire qui les
// dispose en rangée et les laisse passer à la ligne quand la place manque (voir
// .feuille-form dans app.css). Chaque champ porte la classe qui dit sa largeur
// souhaitée — une heure n'a pas besoin de la même place qu'une remarque.
//
// Aucun texte de description au-dessus : le texte indicatif du champ dit déjà
// ce qu'on y met.
//
// Attendu de l'appelant :
//   $fChamps (array)  colonnes à proposer (FEUILLE_TYPES[…]['champs']).
//   $fEl     (array)  l'élément en cours de modification, vide à l'ajout.
//   $fAide   (string) exemple d'intitulé, propre au type.
//   $feuilleContacts (array) les contacts rattachables.
$fEl = $fEl ?? [];
$fChamps = $fChamps ?? [];
$fAide = $fAide ?? '';
$fv = fn (string $cle): string => e((string) ($fEl[$cle] ?? ''));
?>
<?php if (in_array('libelle', $fChamps, true)): ?>
<?php // Sur un horaire, les intitulés se répètent d'une date à l'autre : une
      // liste native les propose à la saisie, sans interdire d'en taper un
      // autre (FEUILLE_HORAIRES_TYPES, lib/feuille_route.php). ?>
<?php $fListe = in_array('debut', $fChamps, true) ? 'feuille-horaires-types' : ''; ?>
<label class="fr-champ fr-libelle">Intitulé <input name="libelle" value="<?= $fv('libelle') ?>"
    placeholder="<?= e($fAide) ?>"<?= $fListe !== '' ? ' list="' . $fListe . '"' : '' ?>></label>
<?php endif; ?>

<?php if (in_array('debut', $fChamps, true)): ?>
<label class="fr-champ fr-heure">Début <input type="time" name="debut" value="<?= $fv('debut') ?>"></label>
<label class="fr-champ fr-heure">Fin <input type="time" name="fin" value="<?= $fv('fin') ?>"></label>
<?php endif; ?>

<?php if (in_array('adresse', $fChamps, true)): ?>
<label class="fr-champ fr-adresse">Adresse <input name="adresse" value="<?= $fv('adresse') ?>" placeholder="12 rue des Lilas, 1200 Genève"></label>
<?php endif; ?>

<?php if (in_array('contact_id', $fChamps, true)): ?>
<?php // Reprendre un contact du carnet plutôt que le recopier : son numéro
      // reste à jour si la fiche change. La saisie libre reste là pour ce qui
      // n'y figure pas — un hébergement chez l'habitant n'est dans aucune
      // structure. Choisir dans la liste rend la saisie libre sans effet
      // (voir feuille_champs_postes(), lib/routes_evenements.php) : deux
      // sources pour un même numéro, c'est une chance sur deux de lire la
      // périmée. ?>
<?php if ($feuilleContacts ?? []): ?>
<label class="fr-champ fr-carnet">Carnet d'adresses
    <select name="contact_id">
        <option value="">— saisir le contact ci-dessous —</option>
        <?php foreach ($feuilleContacts as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) ($fEl['contact_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?><?= trim((string) $c['role']) !== '' ? ' — ' . e($c['role']) : '' ?> (<?= e($c['structure_nom']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</label>
<?php endif; ?>
<label class="fr-champ fr-nom">Prénom <input name="prenom" value="<?= $fv('prenom') ?>"></label>
<label class="fr-champ fr-nom">Nom <input name="nom" value="<?= $fv('nom') ?>"></label>
<label class="fr-champ fr-tel">Téléphone <input name="telephone" value="<?= $fv('telephone') ?>"></label>
<label class="fr-champ fr-email">E-mail <input type="email" name="email" value="<?= $fv('email') ?>"></label>
<?php endif; ?>

<?php if (in_array('remarque', $fChamps, true)): ?>
<?php // Sur une adresse, la remarque est l'endroit où va le code d'entrée : il
      // avait son champ, qui n'aurait pas suffi non plus — restaient l'étage, la
      // place de parking, l'interphone. Le texte indicatif le dit. ?>
<label class="fr-champ fr-remarque">Remarque <input name="remarque" value="<?= $fv('remarque') ?>"
    placeholder="<?= in_array('adresse', $fChamps, true) ? "code d'entrée, étage, parking…" : '' ?>"></label>
<?php endif; ?>
