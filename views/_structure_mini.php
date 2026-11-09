<?php
// UNE STRUCTURE EN MINI-FICHE — le <li> d'une liste `.mini-structures`.
//
// C'est la fiche de ?p=structures en mode cartes (celle que le téléphone
// montre à la place du tableau), reprise telle quelle partout où une CARTE
// liste des structures : l'organisation d'un événement (?p=evenement), les
// rattachements d'une personne (?p=personne). Statut porté par son icône, nom,
// ville avec drapeau et canton, catégorie et les deux dates à droite,
// étiquettes dessous. Une structure se reconnaît ainsi d'un écran à l'autre.
//
// ⚠️ Un seul rendu, et c'est tout l'intérêt : les deux écrans l'avaient écrit
// chacun de son côté, et le second oubliait déjà la moitié des champs.
//
// Variables attendues, posées par la vue juste avant le require :
//   $msS    (obligatoire) : la structure, sous ses noms de colonnes d'origine
//                           — nom, statut, adresse_localite, adresse_pays,
//                           departement_canton, categorie, sous_categorie,
//                           mise_a_jour_le, cree_le, dernier_contact_le,
//                           tags_noms (structure_tags_mini_sql()).
//   $msHref (obligatoire) : où mène la fiche.
//   $msAvantNom (facultatif) : HTML collé devant le nom (l'icône « à
//                           facturer » d'un événement).
//   $msSousNom  (facultatif) : une ligne de plus sous la ville — ce que la
//                           RELATION ajoute, et qui n'est pas dans la
//                           structure : le rôle d'une personne chez elle.
//   $msBadges   (facultatif) : pastilles ajoutées devant les étiquettes, même
//                           rangée (« Booking », « Administration »).
//   $msInactif  (facultatif) : grise la fiche. Par défaut le statut de la
//                           structure en décide ; un rattachement clos la
//                           grise aussi, même sur une structure active.
$msAvantNom = $msAvantNom ?? '';
$msSousNom  = $msSousNom  ?? '';
$msBadges   = $msBadges   ?? '';
$msStatut   = (string) ($msS['statut'] ?? '');
$msInactif  = $msInactif ?? ($msStatut === 'inactif');

$msVille = ville_departement_canton_html(
    (string) ($msS['adresse_localite'] ?? ''),
    pays_drapeau_nom((string) ($msS['adresse_pays'] ?? '')),
    (string) ($msS['adresse_pays'] ?? ''),
    (string) ($msS['departement_canton'] ?? '')
);
$msMaj  = trim((string) ($msS['mise_a_jour_le'] ?? ''));
$msCree = trim((string) ($msS['cree_le'] ?? ''));
$msVu   = trim((string) ($msS['dernier_contact_le'] ?? ''));
// Deux champs par étiquette (nom, couleur) : voir structure_tags_mini_sql().
$msTags = ($msS['tags_noms'] ?? '') !== '' ? array_map(
    fn ($paire) => explode("\x1f", $paire, 2) + ['', ''],
    explode("\x1e", (string) $msS['tags_noms'])
) : [];
?>
<li class="mini-structure<?= $msInactif ? ' inactif' : '' ?>" data-statut="<?= e($msStatut) ?>">
    <a href="<?= e($msHref) ?>" class="ms-lien">
        <?php // L'icône de statut elle-même, et non un carré : il n'y a pas de
              // case à cocher ici, et un carré bordé en tenait faussement lieu. ?>
        <span class="ms-puce <?= e(structure_statut_icone_classe($msStatut)) ?>" title="<?= e(structure_statut_libelle($msStatut)) ?>"><?= icon(structure_statut_icone($msStatut)) ?></span>
        <span class="ms-corps">
            <span class="ms-nom"><?= $msAvantNom ?><?= e((string) ($msS['nom'] ?? '')) ?></span>
            <span class="ms-lieu"><?= $msVille !== '' ? $msVille : '<span class="muted">—</span>' ?></span>
            <?php if ($msSousNom !== ''): ?><span class="ms-role"><?= $msSousNom ?></span><?php endif; ?>
        </span>
        <span class="ms-cote">
            <span class="ms-cat"><?= e(trim((string) (($msS['sous_categorie'] ?? '') ?: ($msS['categorie'] ?? '')))) ?></span>
            <span class="ms-dates">
                <span class="ms-maj<?= $msMaj === '' && $msCree !== '' ? ' est-creation' : '' ?>"><?php
                    if ($msMaj !== '') { echo e(date('d.m.Y', strtotime($msMaj))); }
                    elseif ($msCree !== '') { echo e(date('d.m.Y', strtotime($msCree))); }
                    else { echo '—'; }
                ?></span>
                <span class="ms-vu"><?= $msVu !== '' ? e(date('d.m.Y', strtotime($msVu))) : '—' ?></span>
            </span>
        </span>
        <?php // Pas de bouton « + » ici : on étiquette une structure depuis sa
              // fiche, pas depuis une carte qui la mentionne. ?>
        <?php if ($msBadges !== '' || $msTags): ?>
        <span class="ms-tags">
            <?= $msBadges ?>
            <?php foreach ($msTags as [$tn, $tc]): ?><span class="badge"<?= badge_style_html((string) $tc) ?>><?= e((string) $tn) ?></span><?php endforeach; ?>
        </span>
        <?php endif; ?>
    </a>
</li>
<?php
// Les variables facultatives ne doivent PAS fuir sur la fiche suivante : la
// boucle appelante ne les repose qu'au besoin, et sans ce ménage le rôle de la
// première structure s'affichait sous toutes les autres.
unset($msAvantNom, $msSousNom, $msBadges, $msInactif);
