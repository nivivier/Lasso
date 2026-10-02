<?php
/** @var ?array $campagne */ /** @var array $projets */ /** @var array $projetsDispo */
/** @var array $criteres */ /** @var array $apercu */ /** @var array $retenues */
/** @var array $ajouts */
/** @var bool $previsualise */ /** @var array $tags */ /** @var array $campagnesDispo */
/** @var array $regions */
/** @var array $grandesRegions */ /** @var array $villes */ /** @var array $categoriesPourSelect */
/** @var array $nbEvenements */ /** @var ?string $err */
// Création / modification d'une campagne. Le ciblage est celui du mailing
// groupé — même question, même code (mailing_criteres_depuis) — mais son
// résultat n'est qu'une PROPOSITION : chaque structure garde sa case, qu'on
// décoche pour la sortir de la campagne.
$id = (int) ($campagne['id'] ?? 0);
$val = fn (string $c, $d = '') => e((string) ($campagne[$c] ?? $d));
$projetLabels = [];
foreach ($projetsDispo as $sp) { $projetLabels[(int) $sp['id']] = $sp['nom']; }

// Étiquettes des entonnoirs, report des paramètres d'un panneau à l'autre,
// paramètres du champ d'ajout : tout vient du même endroit que pour la
// recherche de fonds (ciblage_filtres_vue(), lib/booking.php). $saisie dit ce
// qui, SUR CET ÉCRAN, doit survivre au rechargement qu'impose un entonnoir.
$cibF = ciblage_filtres_vue([
    'criteres' => $criteres, 'categoriesPourSelect' => $categoriesPourSelect,
    'tags' => $tags, 'campagnesDispo' => $campagnesDispo,
    'grandesRegions' => $grandesRegions, 'ajouts' => $ajouts,
], [
    'nom'           => (string) ($campagne['nom'] ?? ''),
    'date_debut'    => (string) ($campagne['date_debut'] ?? ''),
    'date_fin'      => (string) ($campagne['date_fin'] ?? ''),
    'projet_ids' => array_map('strval', $projets),
], $id);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Même charpente que les autres pages du module : la zone du module, un
      // en-tête de page, puis le tableau de sélection d'un bord à l'autre. ?>
<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="<?= $id ? '?p=booking_campagne&id=' . $id : '?p=booking_campagnes' ?>"><?= icon('arrow-left') ?> <?= $id ? 'Campagne' : 'Campagnes' ?></a>

<?php if ($err === 'nom'): ?><p class="err flash">Le nom de la campagne est obligatoire.</p><?php endif; ?>

<div class="page-head">
    <h1><?= $id ? 'Modifier la campagne' : 'Nouvelle campagne' ?></h1>
    <?php // Supprimer vit ici, sur l'écran qui modifie la campagne, et pas sur
          // celui qui la suit : c'est là qu'on vient décider de son sort. Rien à
          // la création — il n'y a encore rien à détruire.
          //
          // « Enregistrer la campagne » l'accompagne, comme sur les autres
          // écrans qui sont un formulaire : le bouton est en haut à droite, même
          // s'il valide aussi le ciblage plus bas (form="campagne-form"). Pas
          // d'« Annuler » : le lien de retour est juste au-dessus. ?>
    <?php if (peut_ecrire('booking')): ?>
    <?php ob_start(); ?>
        <?php if ($id): ?>
        <form method="post" action="?p=booking_campagne_supprimer" class="d-inline"
              data-confirm="Supprimer la campagne « <?= e((string) ($campagne['nom'] ?? '')) ?> » ? Les structures et l'historique ne sont pas touchés.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la campagne"><?= icon('trash') ?></button>
        </form>
        <?php endif; ?>
    <?= entete_form_actions_html('campagne-form', '', ['libelle' => 'Enregistrer la campagne', 'avant' => (string) ob_get_clean()]) ?>
    <?php endif; ?>
</div>

<?php // La campagne d'abord — ce qu'on crée —, le ciblage ensuite. Les deux ne
      // peuvent pas tenir dans le même <form> : les filtres sont des
      // formulaires GET (prévisualiser ne doit rien écrire), et un formulaire
      // ne s'imbrique pas. Les cases des structures se rattachent donc à
      // celui-ci par form="campagne-form", comme les droits de ?p=utilisateurs. ?>
<form method="post" action="?p=booking_campagne_enregistrer" class="card form" id="campagne-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php // Les critères repartent avec l'enregistrement : ils sont gardés en
          // mémoire sur la campagne, pour savoir d'où venait la sélection. ?>
    <?= hidden_inputs_html($cibF['criteresActifs']) ?>

    <div class="grid4">
        <label>Nom <input name="nom" value="<?= $val('nom') ?>" required placeholder="ex. Tournée automne 2026"></label>
        <?php // <div> et non <label> : voir choix_coches_html(). ?>
        <div class="field-group"><span>Projet <?= info_tip("Les projets concernés. C'est par eux qu'une prise de contact est rattachée à la campagne : sans projet, la jauge reste à zéro.") ?></span>
            <?= choix_coches_html('projet_ids', $projetLabels, $projets, 'Aucun projet') ?>
        </div>
        <label><span>Début <?= info_tip("Avant cette date, la campagne se prépare : aucun message ne part.") ?></span>
            <input type="date" name="date_debut" value="<?= $val('date_debut') ?>">
        </label>
        <label><span>Fin <?= info_tip("Passée cette date sans avoir tout contacté, la campagne est signalée en retard.") ?></span>
            <input type="date" name="date_fin" value="<?= $val('date_fin') ?>">
        </label>
    </div>
</form>

<?php
// Le ciblage lui-même : filtres, ajout par le nom, tableau à cocher. Le même
// bloc que la composition d'une recherche de fonds.
$cibPage = 'booking_campagne_form';
$cibForm = 'campagne-form';
$cibPrefixe = 'campagne';
$cibTitre = 'Qui contacter';
$cibAide = "Les mêmes filtres que la liste des structures. Le résultat est une proposition : vous décochez ensuite
        celles que vous ne voulez pas dans la campagne.";
$cibDepuis = '&depuis=booking';
require __DIR__ . '/_ciblage_structures.php';
?>

</div></div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const tout = document.getElementById('campagne-tout');
    const cases = [...document.querySelectorAll('.campagne-case')];
    const compte = document.getElementById('campagne-compte');
    if (!cases.length) return;
    const majCompte = () => {
        const n = cases.filter(c => c.checked).length;
        compte.textContent = n + ' / ' + cases.length + (n > 1 ? ' retenues' : ' retenue');
        if (tout) tout.checked = n === cases.length;
    };
    tout?.addEventListener('change', () => { cases.forEach(c => { c.checked = tout.checked; }); majCompte(); });
    cases.forEach(c => c.addEventListener('change', majCompte));
    majCompte();
})();
</script>

<script nonce="<?= e(csp_nonce()) ?>">
// Un panneau de filtre est un formulaire GET : il recharge la page avec ce qu'il
// porte, et rien d'autre. Les champs de la campagne y sont bien reportés en
// champs cachés — mais écrits AU RENDU, donc avec les valeurs que le serveur
// connaissait. Ce qu'on vient de taper sans avoir encore rien enregistré ne s'y
// trouve pas : appliquer un filtre effaçait alors le nom, les dates et les
// projets. On recopie donc l'état réel du formulaire au moment de l'envoi.
//
// Le report côté serveur reste en place : c'est le repli quand JavaScript
// manque, où l'on perd au pire ce qui n'a pas été enregistré.
(function () {
    const form = document.getElementById('campagne-form');
    if (!form) { return; }
    const champs = ['nom', 'date_debut', 'date_fin'];
    document.querySelectorAll('.col-filter-menu').forEach(panneau => {
        panneau.addEventListener('submit', () => {
            // On retire ce que le rendu avait posé avant d'y remettre l'actuel :
            // sans cela, deux valeurs partiraient pour le même nom.
            panneau.querySelectorAll('[data-report-campagne]').forEach(e => e.remove());
            champs.forEach(nom => {
                panneau.querySelectorAll('input[type="hidden"][name="' + nom + '"]').forEach(e => e.remove());
                const valeur = (form.elements[nom]?.value || '').trim();
                if (valeur !== '') { poser(panneau, nom, valeur); }
            });
            panneau.querySelectorAll('input[type="hidden"][name="projet_ids[]"]').forEach(e => e.remove());
            form.querySelectorAll('input[name="projet_ids[]"]:checked')
                .forEach(c => poser(panneau, 'projet_ids[]', c.value));
        });
    });
    function poser(panneau, nom, valeur) {
        const i = document.createElement('input');
        i.type = 'hidden';
        i.name = nom;
        i.value = valeur;
        i.setAttribute('data-report-campagne', '');
        panneau.appendChild(i);
    }
})();
</script>
