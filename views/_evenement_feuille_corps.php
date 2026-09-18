<?php
// Corps d'une feuille de route : ce que la date dit publiquement, puis ce que la
// feuille ajoute pour l'équipe. Partagé par la fenêtre de consultation
// (?p=evenement, bouton « Feuille de route ») et par la page imprimable
// (?p=evenement_feuille_imprimer) — c'est la même feuille, elle ne peut pas
// exister en deux versions qui divergeraient à la première retouche.
//
// Attendu de l'appelant :
//   $evenement     (array) la ligne d'événement, spectacle_nom compris.
//   $elements      (array) les éléments de la feuille (feuille_elements()).
//   $organisateurs (array) les structures liées et leurs contacts
//                  (feuille_organisateurs()).
$elements = $elements ?? [];
$organisateurs = $organisateurs ?? [];
$frJour = fn (string $d): string => $d !== '' ? date('d.m.Y', strtotime($d)) : '';
$frTitre = trim((string) ($evenement['spectacle_nom'] ?? '')) ?: 'Date';
$frHoraire = evenement_horaire_texte($evenement);
$frSitue = implode(', ', array_filter([
    trim((string) ($evenement['departement_canton'] ?? '')),
    pays_nom_depuis_code((string) ($evenement['pays'] ?? '')),
]));
// L'adresse postale complète, pour le tableau : le lieu et la ville sont déjà
// en tête de feuille ; ce qui reste ici est ce qui mène à la porte.
$frAdressePostale = trim(evenement_adresse_texte($evenement) . ($frSitue !== '' ? ' (' . $frSitue . ')' : ''));
// Ce qu'on cherche des yeux en ouvrant une feuille de route : QUAND, OÙ, dans
// quelle VILLE. Le reste — le spectacle, l'horaire de représentation — vient
// après, parce qu'on le sait déjà. Le festival passe devant la salle quand il y
// en a un : c'est sous son nom que la date est annoncée.
$frOu = trim((string) ($evenement['festival'] ?? '')) ?: trim((string) ($evenement['salle'] ?? ''));
$frSalleSousFestival = trim((string) ($evenement['festival'] ?? '')) !== ''
    ? trim((string) ($evenement['salle'] ?? '')) : '';
$frVille = trim((string) ($evenement['ville'] ?? ''));
?>
<div class="fr-print-head">
    <div class="fr-print-date"><?= e($frJour((string) $evenement['date'])) ?></div>
    <h1 class="fr-print-ou"><?= $frOu !== '' ? e($frOu) : '<span class="muted">Lieu à préciser</span>' ?><?php
        if ($frVille !== ''): ?> <span class="fr-print-ville"><?= e($frVille) ?></span><?php endif; ?></h1>
    <div class="fr-print-sub">
        <?= e($frTitre) ?><?= $frSalleSousFestival !== '' ? ' · ' . e($frSalleSousFestival) : '' ?><?= $frHoraire !== '' ? ' · ' . e($frHoraire) : '' ?>
    </div>
</div>

<?php // Les informations publiques de la date : celles qui partent dans l'export
      // du site. Elles ouvrent la feuille parce que ce sont les repères — où,
      // quand, pour qui — que les sections suivantes viennent détailler. ?>
<h2 class="fr-print-titre">Infos publiques</h2>
<table class="fr-print-table">
    <tr>
        <th>Statut</th>
        <td><?= e(evenement_statut_libelle((string) $evenement['statut'])) ?>
            <span class="muted">· <?= e(mb_strtolower(evenement_visibilite_libelle((string) $evenement['visibilite']), 'UTF-8')) ?></span></td>
    </tr>
    <?php if ($frAdressePostale !== ''): ?>
    <tr><th>Adresse</th><td><?= e($frAdressePostale) ?></td></tr>
    <?php endif; ?>
    <?php if (trim((string) ($evenement['lien_infos'] ?? '')) !== ''): ?>
    <tr><th>Lien</th><td><a href="<?= e($evenement['lien_infos']) ?>" target="_blank" rel="noopener"><?= e($evenement['lien_infos']) ?></a></td></tr>
    <?php endif; ?>
    <?php if (trim((string) ($evenement['remarques'] ?? '')) !== ''): ?>
    <tr><th>Remarques</th><td><?= e($evenement['remarques']) ?></td></tr>
    <?php endif; ?>
</table>

<?php // Une section par nature — déroulé, adresses, contacts, pièces jointes,
      // notes —, exactement celles du calendrier d'équipe (feuille_sections()) :
      // c'est la même feuille, on ne la range pas de deux façons. Le déroulé
      // dans un cadre : c'est le cœur, celui qu'on relit dix fois dans la
      // journée. Un filet suffit à le détacher ; un fond plein l'aurait alourdi
      // à l'impression. ?>
<?php $frSections = feuille_sections(['feuille' => $elements] + (array) $evenement); ?>
<?php if (!$frSections): ?>
<h2 class="fr-print-titre"><?= e(FEUILLE_SECTIONS['horaire']) ?></h2>
<div class="fr-print-cadre">
    <p class="muted mb-0">Aucun élément dans la feuille de route.</p>
</div>
<?php endif; ?>
<?php foreach ($frSections as $frSec): ?>
<h2 class="fr-print-titre"><?= e($frSec['titre']) ?></h2>
<?php $frCadre = $frSec['type'] === 'horaire'; ?>
<?php if ($frCadre): ?><div class="fr-print-cadre"><?php endif; ?>
<table class="fr-print-table">
    <?php foreach ($frSec['elements'] as $el): ?>
    <tr>
        <th><?= e(feuille_element_titre($el)) ?></th>
        <td>
            <?php $frLignes = feuille_element_lignes($el); ?>
            <?php if (!$frLignes): ?>—<?php endif; ?>
            <?php foreach ($frLignes as $l): ?><div><?= e($l) ?></div><?php endforeach; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php if ($frCadre): ?></div><?php endif; ?>
<?php endforeach; ?>

<?php // Qui reçoit, et qui décroche le jour même. Repris du carnet d'adresses
      // plutôt que recopié : c'est la fiche de la structure qui fait foi, et
      // elle reste à jour. Rien ne s'affiche si aucune structure n'est liée. ?>
<?php if ($organisateurs): ?>
<h2 class="fr-print-titre">Organisation</h2>
<table class="fr-print-table">
    <?php foreach ($organisateurs as $org): ?>
    <tr>
        <th><?= e($org['nom']) ?>
            <?php // Une structure mère se présente par ce qui la relie à la date :
                  // « organisateur de … ». Sans cette mention, elle apparaîtrait
                  // sans qu'on sache à quel titre. ?>
            <?php if ($org['organise']): ?><div class="muted fr-print-via">organisateur de <?= e(implode(', ', $org['organise'])) ?></div><?php endif; ?>
        </th>
        <td>
            <?php $frOrgAdresse = feuille_structure_adresse($org); ?>
            <?php if ($frOrgAdresse !== ''): ?><div><?= e($frOrgAdresse) ?></div><?php endif; ?>
            <?php if (trim((string) ($org['site_web'] ?? '')) !== ''): ?><div class="muted"><?= e($org['site_web']) ?></div><?php endif; ?>
            <?php foreach ($org['contacts'] as $frC): ?>
                <div><?= e(feuille_contact_ligne($frC)) ?></div>
            <?php endforeach; ?>
            <?php if ($frOrgAdresse === '' && !$org['contacts']): ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
