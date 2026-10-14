<?php
/** @var string $type */ /** @var array $doublons */ /** @var ?string $doublonsErr */
/** @var ?string $ok */ /** @var int $ns */ /** @var int $nc */
/** @var string $datesEtape */ /** @var ?string $datesErr */ /** @var ?array $datesResultat */
/** @var ?int $datesAppliqueN */
/** @var int $seuil */ /** @var array $seuils */ /** @var array $potentiels */
/** @var array $potentielsIgnores */ /** @var ?string $potentielsErr */
/** @var array $personnesDoublons */ /** @var int $personnesFusionN */
/** @var ?int $potentielsIgnoresN */ /** @var ?int $potentielsRepriseN */
/** @var array $grandesRegions */ /** @var ?string $grandesRegionsErr */ /** @var ?int $grandesRegionsAppliqueN */
/** @var array $taxonomieManquantes */ /** @var ?string $taxonomieErr */ /** @var ?int $taxonomieCreesN */
/** @var array $evenementsLieuxUnivoques */ /** @var array $evenementsLieuxAmbigues */
/** @var array $evenementsLieuxAucuneGroupes */ /** @var ?string $evenementsLieuxErr */
/** @var ?int $evenementsLieuxLiesN */ /** @var ?int $evenementsLieuxCreesN */ /** @var ?int $evenementsLieuxCreesEvN */

$libellesTable = ['structures' => 'Structures', 'evenements' => 'Événements'];

$libellesType = ['structures' => 'Structures', 'contacts' => 'Contacts', 'tous' => 'Tous'];
$totalGroupes = count($doublons['structures']) + count($doublons['contacts']);
$totalSurnum  = 0;
foreach (['structures', 'contacts'] as $k) {
    foreach ($doublons[$k] as $g) { $totalSurnum += count($g['ids']) - 1; }
}

// Résumé en tête de page : un lien par section ci-dessous, uniquement les
// outils qui se détectent seuls (pas « Mise à jour des dates », qui exige un
// fichier téléversé — rien à résumer tant qu'aucun n'est fourni).
$nbEvenementsATraiter = count($evenementsLieuxUnivoques) + count($evenementsLieuxAmbigues)
    + array_sum(array_map(fn ($g) => count($g['evenements']), $evenementsLieuxAucuneGroupes));
// Le sommaire : une ligne par vérification, et chaque ligne MÈNE à son écran.
// La page faisait sept cartes bout à bout — quelques centaines de kilo-octets
// et un défilement sans fin pour traiter une seule anomalie. On ne regarde
// jamais les sept à la fois : on en traite une.
//
// « n » est le nombre trouvé ; null pour un outil qui ne se détecte pas tout
// seul (la mise à jour des dates attend un fichier — il n'y a rien à compter
// tant qu'on ne l'a pas fourni).
$resumeItems = [
    ['id' => 'doublons-exacts', 'libelle' => 'Doublons exacts', 'n' => $totalGroupes,
     'quoi' => 'Fiches strictement identiques : structures, lieux, contacts.'],
    ['id' => 'doublons-potentiels', 'libelle' => 'Doublons potentiels (' . $seuil . ' %)', 'n' => count($potentiels),
     'quoi' => 'Noms voisins dans la même région — à valider un par un.'],
    ['id' => 'doublons-personnes', 'libelle' => "Doublons de l'annuaire", 'n' => count($personnesDoublons),
     'quoi' => 'Deux personnes, même adresse électronique ou même nom.'],
    ['id' => 'grandes-regions', 'libelle' => 'Grandes régions déduites du département ou du canton', 'n' => count($grandesRegions),
     'quoi' => 'La grande région se recalcule depuis le département ou le canton.'],
    ['id' => 'taxonomie', 'libelle' => 'Sous-catégories non déclarées', 'n' => count($taxonomieManquantes),
     'quoi' => 'Des fiches portent une sous-catégorie absente du référentiel.'],
    ['id' => 'evenements-lieux', 'libelle' => 'Événements à rattacher à un lieu', 'n' => $nbEvenementsATraiter,
     'quoi' => 'Une salle écrite à la main, jamais reliée à une fiche de lieu.'],
    ['id' => 'dates', 'libelle' => 'Mise à jour des dates depuis un CSV', 'n' => null,
     'quoi' => "Un outil, pas une anomalie : il attend un fichier."],
];
// La section ouverte, ou '' pour le sommaire. Un identifiant inconnu ramène au
// sommaire plutôt qu'à une page vide.
$secIds = array_column($resumeItems, 'id');
$sec = in_array((string) ($_GET['section'] ?? ''), $secIds, true) ? (string) $_GET['section'] : '';
$secLib = '';
foreach ($resumeItems as $it) { if ($it['id'] === $sec) { $secLib = $it['libelle']; } }

// Lien vers la fiche d'un id de doublon (structures) — pas de fiche dédiée
// pour les contacts (gérés depuis la fiche structure), affichés en texte simple.
$lienFiche = function (string $type, int $id): string {
    $routes = ['structures' => 'structure'];
    if (!isset($routes[$type])) {
        return '#' . $id;
    }
    return '<a href="?p=' . $routes[$type] . '&id=' . $id . '">#' . $id . '</a>';
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?php if ($ok === 'personnes'): ?>
    <p class="ok flash"><?= $personnesFusionN ?> fiche(s) de l'annuaire fusionnée(s).</p>
<?php endif; ?>
<?php if ($ok === 'doublons'): ?>
    <p class="ok flash">Fusion effectuée : <?= $ns ?> structure(s) et <?= $nc ?> contact(s) en doublon supprimés.</p>
<?php endif; ?>

<?php if ($sec === ''): ?>
<?php // .card sans retrait supplémentaire : la liste EST tout le contenu de la
      // carte, et c'est le cas que les règles de .card > table.list traitent
      // déjà (docs/UI.md § 9). Pas de .card-flush — son padding à zéro fait
      // sortir le tableau de la carte, qui compense 26px qui n'existent plus. ?>
<div class="card mb-22">
    <?php // La ligne entière mène à l'écran, comme partout ailleurs dans
          // l'application (.row-link, docs/UI.md § 9) : un bouton « Voir » en
          // bout de ligne serait une cible de moins et un mot de plus. ?>
    <table class="list">
        <tbody>
        <?php foreach ($resumeItems as $it): ?>
            <tr class="row-link<?= (int) $it['n'] > 0 ? ' ligne-action' : '' ?>" tabindex="0" role="link"
                data-href="?p=incoherences&section=<?= e($it['id']) ?>">
                <td>
                    <strong><?= e($it['libelle']) ?></strong>
                    <div class="muted small"><?= e($it['quoi']) ?></div>
                </td>
                <td class="num nowrap">
                    <?php if ($it['n'] === null): ?>
                        <span class="muted small">—</span>
                    <?php elseif ($it['n'] === 0): ?>
                        <span class="muted small">rien à traiter</span>
                    <?php else: ?>
                        <strong><?= (int) $it['n'] ?></strong> <span class="muted small">à traiter</span>
                    <?php endif; ?>
                </td>
                <td class="actions"><?= icon('chevron-right') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<?= lien_retour('?p=incoherences', 'Incohérences') ?>
<div class="page-head">
    <div class="page-head-title"><h1><?= e($secLib) ?></h1></div>
</div>
<?php endif; ?>

<?php if ($sec === 'doublons-personnes'): ?>
<?php // Les doublons de l'ANNUAIRE (personnes). Ils étaient dans ?p=personnes,
      // en bouton de la barre d'actions : un geste de reprise de données posé
      // au milieu d'un écran qu'on ouvre tous les jours. Ils rejoignent leurs
      // pareils — doublons de structures et de contacts, lieux à rattacher. ?>
<?php if ($personnesDoublons !== [] || module_accessible('carnet')): ?>
<div class="card" id="doublons-personnes">
    <p class="muted small">
        Deux fiches de personne avec la même adresse électronique, ou le même nom. ⚠️ Rien n'est
        fusionné d'office, et rien ne se défait : une boîte partagée (info@salle.ch) et deux
        homonymes se ressemblent exactement autant qu'un vrai doublon. C'est pourquoi chaque groupe
        se valide à la main, en désignant la fiche à garder.
    </p>

    <?php if (!$personnesDoublons): ?>
        <p class="muted">Aucun doublon probable dans l'annuaire.</p>
    <?php else: ?>
    <?php // ⚠️ Un groupe plafonné à dix fiches, et le formulaire ne porte que
          // celles-là. L'annuaire compte des groupes de plus de mille — « L'équipe »,
          // « Info », des noms d'import qui ne désignent personne —, et les
          // dérouler en entier rendait la page illisible autant qu'énorme. Dix
          // suffisent à traiter un vrai doublon ; un groupe de mille n'est pas
          // un doublon, c'est une colonne mal remplie. ?>
    <?php foreach ($personnesDoublons as $g): $pdTotal = count($g['personnes']); $pdFiches = array_slice($g['personnes'], 0, 10); ?>
    <div class="card-block">
        <h3 class="sub"><?= e($g['cle']) ?> — <?= $pdTotal ?> fiches, même <?= $g['motif'] === 'email' ? 'adresse électronique' : 'nom' ?></h3>
        <?php if ($pdTotal > count($pdFiches)): ?>
        <p class="muted small">Les <?= count($pdFiches) ?> plus anciennes seulement. La fusion ne portera que sur celles-ci.</p>
        <?php endif; ?>
        <form method="post" action="?p=personnes_fusionner">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="table-scroll">
            <table class="list">
                <thead><tr>
                    <th class="col-check" title="La fiche à garder">Garder</th>
                    <th>Nom</th><th>Adresse électronique</th><th>Téléphone</th><th>Rattachements</th>
                </tr></thead>
                <tbody>
                <?php foreach ($pdFiches as $i => $pp): $pid = (int) $pp['id']; ?>
                    <tr>
                        <td class="col-check">
                            <?php // La plus ANCIENNE proposée d'office : c'est
                                  // elle qui porte l'historique le plus long. ?>
                            <input type="radio" name="cible" value="<?= $pid ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <input type="hidden" name="sources[]" value="<?= $pid ?>">
                        </td>
                        <td><a href="?p=personne&id=<?= $pid ?>" class="titre-lien"><?= e(personne_nom($pp)) ?></a></td>
                        <td class="muted small"><?= e((string) $pp['email']) ?></td>
                        <td class="muted small"><?= e((string) $pp['telephone']) ?></td>
                        <td class="muted small"><?= (int) ($pp['nb_structures'] ?? 0) ?> structure(s)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="submit" class="btn mt-16"
                    data-confirm="Fusionner ces fiches ? Les autres disparaîtront, et tout ce qui les désignait pointera celle que vous gardez. Cela ne se défait pas."><?= icon('merge') ?> Fusionner dans la fiche cochée</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($sec === 'doublons-exacts'): ?>
<div class="card" id="doublons-exacts">
    <p class="muted small">
        Détecte les fiches strictement identiques : structures (nom + localité), lieux (nom + ville + type),
        contacts (même structure + e-mail, ou à défaut nom + téléphone). Dans chaque groupe, la fiche la plus
        ancienne est conservée ; les autres lui cèdent leurs rattachements avant d'être supprimées.
    </p>

    <form method="get" class="mb-16">
        <input type="hidden" name="p" value="dev">
        <label class="d-inline">Type
            <select name="type" data-submit-on-change>
                <?php foreach ($libellesType as $v => $lib): ?>
                    <option value="<?= e($v) ?>" <?= $type === $v ? 'selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn-sm">Filtrer</button></noscript>
    </form>

    <?php if ($doublonsErr): ?><p class="err"><?= e($doublonsErr) ?></p><?php endif; ?>

    <?php if ($totalGroupes === 0): ?>
        <p class="muted">Aucun doublon exact trouvé.</p>
    <?php else: ?>
        <p><?= $totalGroupes ?> groupe(s) de doublons, <?= $totalSurnum ?> fiche(s) en trop.</p>
        <?php foreach (['structures' => 'Structures', 'contacts' => 'Contacts'] as $k => $lib): ?>
            <?php if ($doublons[$k]): ?>
                <h3><?= e($lib) ?> (<?= count($doublons[$k]) ?>)</h3>
                <table class="list">
                    <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Fiche</th><th>Conservée</th><th>Supprimée(s)</th></tr></thead>
                    <tbody>
                    <?php foreach ($doublons[$k] as $g): ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($k) ?>:<?= (int) $g['ids'][0] ?>" form="dev-doublons-form" class="row-check"></td>
                            <td><?= e($g['libelle']) ?></td>
                            <td><?= $lienFiche($k, (int) $g['ids'][0]) ?></td>
                            <td><?= implode(', ', array_map(fn ($id) => $lienFiche($k, (int) $id), array_slice($g['ids'], 1))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>

        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-doublons-form"
              data-confirm="Fusionner les groupes de doublons cochés ci-dessus ? Une sauvegarde de la base sera faite automatiquement avant.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="doublons_fusionner">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <button type="submit"><?= icon('merge') ?> Fusionner les doublons cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant la fusion.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sec === 'doublons-potentiels'): ?>
<div class="card mt-22" id="doublons-potentiels">
    <p class="muted small">
        Rapproche les structures dont le <strong>nom se ressemble</strong> à au moins le seuil choisi
        et dont le <strong>département / canton est identique</strong> — les saisies en double sous une
        variante, que la détection exacte ci-dessus laisse passer. La comparaison ignore la casse,
        les accents et la ponctuation. Les fiches <strong>sans canton renseigné</strong> ne sont pas
        comparées, et les doublons déjà exacts ne sont pas répétés ici.
        Un rapprochement légitime — une antenne et sa maison mère, deux salles d'un même lieu — se
        met de côté avec « Ignorer » : il ne reviendra plus.
    </p>

    <form method="get" class="mb-16">
        <input type="hidden" name="p" value="dev">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <label class="d-inline">Ressemblance minimale
            <select name="seuil" data-submit-on-change>
                <?php foreach ($seuils as $v): ?>
                    <option value="<?= (int) $v ?>" <?= $seuil === $v ? 'selected' : '' ?>><?= (int) $v ?> %</option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn-sm">Appliquer</button></noscript>
    </form>

    <?php if ($potentielsErr): ?><p class="err"><?= e($potentielsErr) ?></p><?php endif; ?>
    <?php if ($potentielsIgnoresN !== null): ?>
        <p class="ok flash"><?= $potentielsIgnoresN ?> rapprochement(s) mis de côté.</p>
    <?php endif; ?>
    <?php if ($potentielsRepriseN !== null): ?>
        <p class="ok flash"><?= $potentielsRepriseN ?> rapprochement(s) remis en jeu.</p>
    <?php endif; ?>

    <?php if (!$potentiels): ?>
        <p class="muted">Aucun doublon potentiel à <?= (int) $seuil ?> % de ressemblance.</p>
    <?php else: ?>
        <p><?= count($potentiels) ?> rapprochement(s) proposé(s), les plus ressemblants d'abord.</p>
        <table class="list">
            <thead><tr>
                <th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th>
                <th class="num">Ressemblance</th><th>Conservée</th><th>Absorbée</th><th>Canton</th>
            </tr></thead>
            <tbody>
            <?php foreach ($potentiels as $paire): ?>
                <?php // La fiche au plus petit id (la plus ancienne) est celle que
                      // conserverait la fusion : la colonne le dit avant qu'on coche. ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($paire['cle']) ?>" form="dev-potentiels-form" class="row-check"></td>
                    <td class="num"><?= (int) $paire['score'] ?> %</td>
                    <?php foreach (['a', 'b'] as $cote): ?>
                        <td>
                            <?= $lienFiche('structures', (int) $paire[$cote]['id']) ?>
                            <?= e($paire[$cote]['nom']) ?>
                            <?php if (trim((string) $paire[$cote]['localite']) !== ''): ?>
                                <span class="muted small">— <?= e($paire[$cote]['localite']) ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="muted small"><?= e($paire['a']['canton']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Deux boutons, un seul jeu de cases : l'action part du bouton cliqué
              // (formaction), pour ne pas demander de re-cocher entre « ignorer » et
              // « fusionner ». ?>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>&amp;seuil=<?= (int) $seuil ?>" class="mt-16" id="dev-potentiels-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <?php // Les deux boutons portent chacun leur action, plutôt qu'un champ
                  // caché qu'un name= sur le bouton viendrait recouvrir : ici l'action
                  // envoyée est celle du bouton cliqué, et rien d'autre. « Ignorer »
                  // vient en premier, c'est donc lui qu'une validation au clavier
                  // déclenche — le geste sans conséquence sur les fiches. ?>
            <div class="form-actions">
                <button type="submit" class="btn ghost" name="action" value="doublons_potentiels_ignorer"><?= icon('eye-off') ?> Ignorer les rapprochements cochés</button>
                <button type="submit" name="action" value="doublons_potentiels_fusionner"
                        data-confirm="Fusionner les paires cochées ci-dessus ? La fiche la plus ancienne est conservée, l'autre lui cède ses rattachements avant d'être supprimée. Une sauvegarde de la base sera faite automatiquement avant."><?= icon('merge') ?> Fusionner</button>
            </div>
        </form>
        <p class="muted small">« Ignorer » ne touche à aucune fiche : la paire est simplement écartée des propositions. Une sauvegarde de la base est faite automatiquement avant une fusion.</p>
    <?php endif; ?>

    <?php if ($potentielsIgnores): ?>
        <details class="mt-22">
            <summary><?= count($potentielsIgnores) ?> rapprochement(s) mis de côté</summary>
            <p class="muted small">Ces paires ne sont plus proposées. Les recocher les remet en jeu à la prochaine détection.</p>
            <table class="list">
                <thead><tr>
                    <th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th>
                    <th class="num">Ressemblance</th><th>Structure</th><th>Structure</th><th>Mise de côté le</th>
                </tr></thead>
                <tbody>
                <?php foreach ($potentielsIgnores as $paire): ?>
                    <tr>
                        <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($paire['cle']) ?>" form="dev-potentiels-reprise-form" class="row-check"></td>
                        <td class="num"><?= (int) $paire['score'] ?> %</td>
                        <?php foreach (['a', 'b'] as $cote): ?>
                            <td>
                                <?= $lienFiche('structures', (int) $paire[$cote]['id']) ?>
                                <?= e($paire[$cote]['nom']) ?>
                                <?php if (trim((string) $paire[$cote]['localite']) !== ''): ?>
                                    <span class="muted small">— <?= e($paire[$cote]['localite']) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="muted small"><?= e(date('d.m.Y', strtotime($paire['cree_le']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>&amp;seuil=<?= (int) $seuil ?>" class="mt-16" id="dev-potentiels-reprise-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="doublons_potentiels_reprendre">
                <button type="submit" class="btn ghost"><?= icon('eye') ?> Remettre en jeu les paires cochées</button>
            </form>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sec === 'dates'): ?>
<div class="card mt-22">
    <p class="muted small">
        Met à jour uniquement les dates « mise à jour », « dernier contact » et « dernier concert » d'après
        un export CSV, sans toucher au reste des fiches. Rapprochement par e-mail exact, sinon par nom + ville
        (jamais d'homonyme deviné).
    </p>

    <?php if ($datesErr): ?><p class="err"><?= e($datesErr) ?></p><?php endif; ?>

    <?php if ($datesAppliqueN !== null): ?>
        <p class="ok flash"><?= $datesAppliqueN ?> écriture(s) enregistrée(s).</p>
    <?php endif; ?>

    <?php if ($datesEtape === 'upload' || $datesAppliqueN !== null): ?>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="dates_analyser">
            <label>Fichier CSV <input type="file" name="fichier" accept=".csv,text/csv" required></label>
            <div class="form-actions">
                <button type="submit"><?= icon('search') ?> Analyser (simulation)</button>
            </div>
        </form>
    <?php elseif ($datesEtape === 'analyse' && $datesResultat): ?>
        <p class="muted small">Fichier : <strong><?= e($datesResultat['nom']) ?></strong> — colonnes repérées :
            <?php foreach ($datesResultat['index'] as $champ => $i): ?>
                <?= e($champ) ?>&nbsp;<?= $i === null ? '(absente)' : '« ' . e((string) $datesResultat['entete'][$i]) . ' »' ?><?= ' · ' ?>
            <?php endforeach; ?>
        </p>
        <p>
            <?= $datesResultat['stats']['lignes'] ?> ligne(s) lue(s) ·
            <?= $datesResultat['stats']['sans_correspondance'] ?> sans correspondance ·
            <?= $datesResultat['stats']['ambigues'] ?> ambiguë(s) ·
            <?= $datesResultat['stats']['maj'] ?> mise(s) à jour ·
            <?= $datesResultat['stats']['contact'] ?> dernier(s) contact ·
            <?= $datesResultat['stats']['concert'] ?> dernier(s) concert
        </p>

        <?php if ($datesResultat['aEcrire']): ?>
            <table class="list">
                <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Écriture prévue</th></tr></thead>
                <tbody>
                <?php foreach ($datesResultat['aEcrire'] as $op): ?>
                    <tr>
                        <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($op[0]) ?>:<?= (int) $op[1] ?>" form="dev-dates-form" class="row-check"></td>
                        <td><?= e($op[3]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-dates-form"
                  data-confirm="Enregistrer les écritures cochées ci-dessus ? Une sauvegarde de la base sera faite automatiquement avant.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="dates_appliquer">
                <input type="hidden" name="depuis_session" value="1">
                <button type="submit"><?= icon('save') ?> Enregistrer les écritures cochées</button>
            </form>
            <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'enregistrement.</p>
        <?php else: ?>
            <p class="muted">Rien à écrire — aucune date nouvelle ou différente détectée.</p>
        <?php endif; ?>

        <?php if ($datesResultat['ambigues']): ?>
            <p class="muted small">Homonymes ambigus (ville absente du fichier, non traités) : <?= e(implode(', ', $datesResultat['ambigues'])) ?></p>
        <?php endif; ?>
        <?php if ($datesResultat['nonTrouvees']): ?>
            <p class="muted small">Sans correspondance dans la base : <?= e(implode(', ', $datesResultat['nonTrouvees'])) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sec === 'grandes-regions'): ?>
<div class="card mt-22" id="grandes-regions">
    <p class="muted small">
        Recalcule la grande région (structures, lieux, événements) à partir du département français ou du canton
        suisse déjà renseigné, et compare avec la valeur actuellement enregistrée. Jamais pour les cantons
        bilingues (Fribourg, Valais, Berne — la déduction n'y est pas assez fiable, ces fiches restent inchangées)
        ni pour les autres pays.
    </p>

    <?php if ($grandesRegionsErr): ?><p class="err"><?= e($grandesRegionsErr) ?></p><?php endif; ?>
    <?php if ($grandesRegionsAppliqueN !== null): ?>
        <p class="ok flash"><?= $grandesRegionsAppliqueN ?> fiche(s) mise(s) à jour.</p>
    <?php endif; ?>

    <?php if (!$grandesRegions): ?>
        <p class="muted">Aucun écart trouvé — les grandes régions déjà enregistrées correspondent au référentiel.</p>
    <?php else: ?>
        <p><?= count($grandesRegions) ?> fiche(s) avec un écart.</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Table</th><th>Fiche</th><th>Pays</th><th>Dép./canton</th><th>Actuelle</th><th>Déduite</th></tr></thead>
            <tbody>
            <?php foreach ($grandesRegions as $l): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($l['table']) ?>:<?= (int) $l['id'] ?>" form="dev-gr-form" class="row-check"></td>
                    <td class="muted small"><?= e($libellesTable[$l['table']] ?? $l['table']) ?></td>
                    <td><?= e($l['nom']) ?></td>
                    <td class="muted small"><?= e($l['pays']) ?></td>
                    <td class="muted small"><?= e($l['departement_canton']) ?></td>
                    <td class="muted small"><?= $l['actuelle'] !== '' ? e($l['actuelle']) : '—' ?></td>
                    <td><?= e($l['deduite']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-gr-form"
              data-confirm="Mettre à jour les fiches cochées ci-dessus avec la grande région déduite ? Une sauvegarde de la base sera faite automatiquement avant.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="grandes_regions_appliquer">
            <button type="submit"><?= icon('map-pin') ?> Appliquer aux fiches cochées</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sec === 'taxonomie'): ?>
<div class="card mt-22" id="taxonomie">
    <p class="muted small">
        Sous-catégories portées par des structures (souvent venues d'un import) mais absentes de
        Paramètres → Catégories : elles s'affichent sur les fiches sans figurer dans les listes de
        choix ni dans les filtres. Les déclarer les ajoute sous leur catégorie racine, sans toucher
        aux structures elles-mêmes.
    </p>

    <?php if ($taxonomieErr): ?><p class="err"><?= e($taxonomieErr) ?></p><?php endif; ?>
    <?php if ($taxonomieCreesN !== null): ?>
        <p class="ok flash"><?= $taxonomieCreesN ?> sous-catégorie(s) ajoutée(s) à la taxonomie.</p>
    <?php endif; ?>

    <?php if (!$taxonomieManquantes): ?>
        <p class="muted">Aucun écart — toutes les sous-catégories utilisées sont déclarées.</p>
    <?php else: ?>
        <p><?= count($taxonomieManquantes) ?> sous-catégorie(s) non déclarée(s).</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Catégorie</th><th>Sous-catégorie</th><th>Structures</th></tr></thead>
            <tbody>
            <?php foreach ($taxonomieManquantes as $t): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= e($t['cle']) ?>" form="dev-taxo-form" class="row-check"></td>
                    <td class="muted small"><?= e($t['categorie']) ?></td>
                    <td><?= e($t['nom']) ?></td>
                    <td class="muted small"><?= (int) $t['nb'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-taxo-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="taxonomie_declarer">
            <button type="submit"><?= icon('calendar-sync') ?> Déclarer les sous-catégories cochées</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sec === 'evenements-lieux'): ?>
<div class="card mt-22" id="evenements-lieux">
    <p class="muted small">
        Un événement stocke le nom de la salle en texte libre, sans être automatiquement rattaché à une fiche
        lieu de la base. Rapproche par ville (+ département/canton + pays) puis par nom de salle — jamais deviné
        en cas de doute (plusieurs lieux candidats) : ces événements restent à traiter à la main, sur leur fiche.
    </p>

    <?php if ($evenementsLieuxErr): ?><p class="err"><?= e($evenementsLieuxErr) ?></p><?php endif; ?>
    <?php if ($evenementsLieuxLiesN !== null): ?>
        <p class="ok flash"><?= $evenementsLieuxLiesN ?> événement(s) rattaché(s) à un lieu existant.</p>
    <?php endif; ?>
    <?php if ($evenementsLieuxCreesN !== null): ?>
        <p class="ok flash"><?= $evenementsLieuxCreesN ?> structure(s)+lieu(x) créé(s), <?= $evenementsLieuxCreesEvN ?> événement(s) rattaché(s).</p>
    <?php endif; ?>

    <h3>Correspondances trouvées</h3>
    <?php if (!$evenementsLieuxUnivoques): ?>
        <p class="muted">Aucune correspondance univoque trouvée.</p>
    <?php else: ?>
        <p><?= count($evenementsLieuxUnivoques) ?> événement(s) avec un lieu candidat unique.</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Date</th><th>Salle (événement)</th><th>Ville</th><th>Organisateur proposé</th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxUnivoques as $d): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= (int) $d['evenement_id'] ?>" form="dev-evlier-form" class="row-check"></td>
                    <td class="muted small"><a href="?p=evenement&id=<?= (int) $d['evenement_id'] ?>"><?= e($d['date']) ?></a></td>
                    <td><?= e($d['salle']) ?></td>
                    <td class="muted small"><?= e($d['ville']) ?></td>
                    <td><a href="?p=structure&id=<?= (int) $d['candidats'][0]['id'] ?>"><?= e($d['candidats'][0]['nom']) ?></a> <span class="muted small">(<?= e($d['candidats'][0]['type']) ?>)</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-evlier-form"
              data-confirm="Rattacher les événements cochés ci-dessus au lieu proposé ? Une sauvegarde de la base sera faite automatiquement avant.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="evenements_lieux_lier">
            <button type="submit"><?= icon('merge') ?> Rattacher les événements cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>

    <h3 class="mt-22">Correspondances ambiguës</h3>
    <?php if (!$evenementsLieuxAmbigues): ?>
        <p class="muted">Aucune.</p>
    <?php else: ?>
        <p class="muted small">Plusieurs lieux candidats trouvés — jamais deviné : à choisir à la main sur la fiche événement (champ « Lieu »).</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th>Date</th><th>Salle (événement)</th><th>Ville</th><th>Lieux candidats</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxAmbigues as $d): ?>
                <tr>
                    <td class="muted small"><?= e($d['date']) ?></td>
                    <td><?= e($d['salle']) ?></td>
                    <td class="muted small"><?= e($d['ville']) ?></td>
                    <td class="muted small"><?= e(implode(', ', array_map(fn ($c) => $c['nom'], $d['candidats']))) ?></td>
                    <td><a href="?p=evenement&id=<?= (int) $d['evenement_id'] ?>" class="btn-sm">Ouvrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <h3 class="mt-22">Aucune correspondance</h3>
    <?php if (!$evenementsLieuxAucuneGroupes): ?>
        <p class="muted">Aucune.</p>
    <?php else: ?>
        <?php $nbEvAucune = array_sum(array_map(fn ($g) => count($g['evenements']), $evenementsLieuxAucuneGroupes)); ?>
        <p class="muted small">
            Aucun lieu trouvé dans la même ville pour ces salles — une nouvelle structure et un nouveau lieu
            (catégorie « organisateur » par défaut, à préciser ensuite) peuvent être créés, une seule paire par
            salle même si plusieurs événements la partagent.
        </p>
        <p><?= count($evenementsLieuxAucuneGroupes) ?> salle(s) sans correspondance, <?= $nbEvAucune ?> événement(s) concerné(s).</p>
        <div class="table-scroll">
        <table class="list">
            <thead><tr><th class="col-check"><input type="checkbox" class="check-all" aria-label="Tout cocher"></th><th>Salle</th><th>Ville</th><th>Dép./canton</th><th>Pays</th><th>Événement(s)</th></tr></thead>
            <tbody>
            <?php foreach ($evenementsLieuxAucuneGroupes as $g): ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="sel[]" value="<?= (int) $g['evenements'][0]['id'] ?>" form="dev-evcreer-form" class="row-check"></td>
                    <td><?= e($g['nom']) ?></td>
                    <td class="muted small"><?= e($g['ville']) ?></td>
                    <td class="muted small"><?= e($g['departement_canton']) ?></td>
                    <td class="muted small"><?= e($g['pays']) ?></td>
                    <td class="muted small">
                        <?php foreach ($g['evenements'] as $i => $ev): ?>
                            <?= $i > 0 ? ', ' : '' ?><a href="?p=evenement&id=<?= (int) $ev['id'] ?>"><?= e($ev['date']) ?></a>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" action="?p=incoherences&amp;section=<?= e($sec) ?>" class="mt-16" id="dev-evcreer-form"
              data-confirm="Créer une structure+lieu pour chaque salle cochée ci-dessus et y rattacher ses événements ? Une sauvegarde de la base sera faite automatiquement avant.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="evenements_lieux_creer">
            <button type="submit"><?= icon('building-2') ?> Créer les structures+lieux cochés</button>
        </form>
        <p class="muted small">Une sauvegarde de la base est faite automatiquement avant l'écriture.</p>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Case « tout cocher » de chaque tableau de l'onglet Dev : ne coche/décoche
// que les cases de SON PROPRE tableau (plusieurs tableaux indépendants sur
// cette page, contrairement aux listes lieux/structures/événements qui n'en
// ont qu'un seul) — un seul script générique plutôt qu'un bloc dupliqué par tableau.
document.querySelectorAll('.check-all').forEach(function (toutCocher) {
    var table = toutCocher.closest('table');
    toutCocher.addEventListener('change', function () {
        table.querySelectorAll('.row-check').forEach(function (c) { c.checked = toutCocher.checked; });
    });
});
</script>
<?php endif; ?>

