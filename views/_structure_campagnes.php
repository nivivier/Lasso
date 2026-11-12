<?php
// Carte « Booking » d'une fiche structure : les campagnes de démarchage où
// elle figure, celles qui visent ses structures liées, et de quoi la ranger
// dans une campagne de plus.
//
// Attendu de l'appelant (views/structure.php) : $campagnesStructure,
// $campagnesLiees, $campagnesDispo, $peutEcrireBooking, $structure, $depuisQs.
?>
<?php // Campagnes de démarchage où figure cette structure : ce qu'on lui a déjà
      // proposé, quand, et ce qu'elle a répondu. La réponse appartient à la
      // campagne et non à la fiche — la même salle peut décliner une tournée et
      // prendre la suivante —, mais elle se règle d'ici comme depuis la campagne :
      // c'est le même sélecteur, la même donnée.
      //
      // Le cadre s'affiche même sans campagne, dès qu'on peut écrire : c'est de
      // là qu'on range la structure dans une campagne. ?>
<?php if (module_accessible('booking') && ($campagnesStructure || $campagnesLiees || ($peutEcrireBooking && $campagnesDispo))): ?>
<div class="card">
    <div class="card-block">
        <div class="card-head-row">
            <?php // « Booking », du nom du module, et non « Campagnes » : la fiche
                  // porte aussi les campagnes de recherche de fonds, juste en
                  // dessous. Deux cartes nommées « Campagnes » sur le même écran
                  // ne diraient plus laquelle est laquelle. ?>
            <h2 class="mt-0">Booking<?= $campagnesStructure ? ' (' . count($campagnesStructure) . ')' : '' ?> <?= info_tip("Sous « Structures liées » : les campagnes qui visent son organisateur ou les salles qu'elle organise. En lecture seule — la réponse y est celle de l'autre structure.") ?></h2>
            <?php if ($peutEcrireBooking && $campagnesDispo): ?>
            <div class="head-actions">
                <?php // Une icône seule, comme les autres en-têtes de cadre de la
                      // fiche : le titre du cadre dit déjà de quoi il s'agit. ?>
                <button type="button" class="btn ghost icon-only" data-show="campagne-ajouter-fiche"
                        title="Ajouter à une campagne" aria-label="Ajouter à une campagne"><?= icon('message-circle-plus') ?></button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($peutEcrireBooking && $campagnesDispo): ?>
        <?php // Liste fermée : on range la structure dans une campagne existante,
              // on n'en crée pas d'ici. Celles où elle figure déjà n'y sont pas. ?>
        <?php $dejaDedans = array_map(fn ($c) => (int) $c['id'], $campagnesStructure); ?>
        <form method="post" action="?p=booking_campagne_structure<?= $depuisQs ?>" class="linked-add linked-add-ligne" id="campagne-ajouter-fiche" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" value="<?= $sid ?>">
            <?php // Champ cherchable plutôt que menu déroulant, comme pour les
                  // étiquettes : une association a vite trente campagnes. Liste
                  // fermée — on rattache à une campagne existante, on n'en crée
                  // pas d'ici —, d'où la valeur cachée que seule une sélection
                  // remplit. Celles où la structure figure déjà n'y sont pas. ?>
            <div class="cat-search campagne-search" data-cat-search data-vider-en-saisie>
                <input type="text" class="cat-search-input" placeholder="Campagne…" autocomplete="off" aria-label="Campagne">
                <input type="hidden" name="campagne_id" class="cat-search-val" value="" required>
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($campagnesDispo as $c): ?>
                        <?php if (in_array((int) $c['id'], $dejaDedans, true)) { continue; } ?>
                        <li data-val="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn btn-sm icon-only" title="Ajouter" aria-label="Ajouter à cette campagne"><?= icon('plus') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only" data-hide="campagne-ajouter-fiche" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
        <?php endif; ?>

        <?php if (!$campagnesStructure && !$campagnesLiees): ?>
        <p class="muted mb-0 mt-16">Cette structure ne fait partie d'aucune campagne.</p>
        <?php else: ?>
        <?php // Le tableau remplit la carte, bord à bord — c'est la règle de
              // tout tableau de carte (docs/UI.md § 9), plus rien à déclarer
              // ici. À cette largeur, deux retraits de 26px de plus coûtaient
              // une ligne de repli sur presque chaque nom de projet. ?>
        <?php
        // Période et réponse s'écrivent pareil pour les campagnes de la fiche et
        // pour celles des structures liées, plus bas : deux fermetures plutôt
        // que deux copies.
        $aujourdhuiCamp = date('Y-m-d');
        $campPeriode = function (array $c): string {
            $d = $c['date_debut'] ? date('d.m.Y', strtotime((string) $c['date_debut'])) : '';
            $f = $c['date_fin'] ? date('d.m.Y', strtotime((string) $c['date_fin'])) : '';
            // Espace insécable avant la flèche : sur téléphone la cellule
            // s'enroule, et sans lui la flèche se retrouvait seule sur sa ligne.
            return ($d !== '' ? e($d) : '—') . ($f !== '' ? '&nbsp;→ ' . e($f) : '');
        };
        // En lecture, la réponse tient en UNE icône : le sélecteur à trois
        // branches n'a d'intérêt qu'au moment de choisir. Les trois sont
        // rendues, une seule visible — c'est ce qui permet de suivre un
        // changement sans reconstruire de HTML.
        //
        // Sauf pour une campagne qui n'a pas commencé : elle n'a rien demandé à
        // personne, et l'icône « aucune réponse » s'y lirait comme une réponse
        // attendue qui manque. La cellule dit alors « À venir » — ce qui vaut
        // aussi pour les campagnes des structures liées, plus bas.
        $campReponseLecture = function (array $c) use ($aujourdhuiCamp): string {
            if (!campagne_ouverte((string) $c['date_debut'], $aujourdhuiCamp)) {
                $jour = $c['date_debut'] ? date('d.m.Y', strtotime((string) $c['date_debut'])) : '';
                return '<span class="muted small nowrap" title="La campagne commence le ' . e($jour)
                    . ' : aucune réponse ne peut être notée avant.">'
                    . '<span class="ico-tiny">' . icon('clock') . '</span> À venir</span>';
            }
            $reponse = (string) ($c['reponse'] ?? '');
            $h = '<span class="camp-reponse-lecture">';
            foreach (CAMPAGNE_REPONSES as $val => $lib) {
                $h .= '<span class="' . e(CAMPAGNE_REPONSES_CLASSES_ICONE[$val]) . '" data-reponse-vue="' . e((string) $val) . '"'
                    . ' title="' . e($lib) . '" aria-label="' . e($lib) . '"' . ((string) $val === $reponse ? '' : ' hidden') . '>'
                    . icon(CAMPAGNE_REPONSES_ICONES[$val]) . '</span>';
            }
            return $h . '</span>';
        };
        ?>
        <div class="table-scroll">
        <table class="list mb-0 campagnes-fiche cartes-chiffres cartes-campagnes-fiche">
            <?php // Projet et campagne tiennent une seule colonne, l'un sous
                  // l'autre : à cinq colonnes, la carte débordait de l'écran
                  // d'un téléphone. La période, elle, s'enroule sur deux lignes
                  // plutôt que d'imposer sa largeur. ?>
            <thead><tr><th>Campagne</th><th>Période</th><th class="nowrap">Réponse</th><th></th></tr></thead>
            <tbody>
            <?php if (!$campagnesStructure): ?>
                <tr><td colspan="4" class="muted small">Cette structure ne fait partie d'aucune campagne.</td></tr>
            <?php endif; ?>
            <?php foreach ($campagnesStructure as $c): $cid = (int) $c['id']; ?>
                <?php $reponse = (string) ($c['reponse'] ?? ''); ?>
                <?php // Une campagne qui n'a pas commencé n'a encore rien
                      // demandé à personne : sa réponse ne se note pas, pas
                      // plus qu'on ne peut y envoyer un message avant la date
                      // de début (campagne_ouverte(), même règle qu'à
                      // ?p=booking_campagne). Le crayon garde tout son sens pour
                      // autant : on prépare la liste, donc on doit pouvoir en
                      // retirer la structure.
                      // La route refuse elle aussi (route_booking_campagne_reponse_enregistrer()).
                      $campOuverte = campagne_ouverte((string) $c['date_debut'], $aujourdhuiCamp); ?>
                <tr class="camp-ligne">
                    <td class="col-camp">
                        <div class="small"><?= $c['projets'] ? e(implode(' · ', $c['projets'])) : '<span class="muted">Sans projet</span>' ?></div>
                        <a href="?p=booking_campagne&id=<?= $cid ?>"><?= e((string) $c['nom']) ?></a>
                    </td>
                    <td class="muted small col-periode"><?= $campPeriode($c) ?></td>
                    <td class="nowrap col-reponse">
                        <?php // « À venir » reste visible pendant l'édition de la
                              // ligne : c'est la cellule entière qui n'a rien à
                              // modifier, pas seulement sa partie lecture. ?>
                        <?= $campReponseLecture($c) ?>
                        <?php if ($peutEcrireBooking && $campOuverte): ?>
                        <span class="camp-ligne-edition" hidden><?= campagne_reponse_toggle_html($cid, $sid, $reponse) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="actions nowrap">
                        <?php if ($peutEcrireBooking): ?>
                        <?php // Lecture d'abord : le crayon ouvre ce qui modifie — le
                              // sélecteur de réponse et le retrait de la campagne. ?>
                        <button type="button" class="btn ghost btn-sm icon-only camp-ligne-crayon" title="Modifier" aria-label="Modifier cette campagne"><?= icon('pencil') ?></button>
                        <span class="camp-ligne-edition" hidden>
                            <form method="post" action="?p=booking_campagne_structure<?= $depuisQs ?>" class="d-inline"
                                  data-confirm="Retirer cette structure de la campagne « <?= e((string) $c['nom']) ?> » ? La réponse qui y est notée sera perdue.">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="structure_id" value="<?= $sid ?>">
                                <input type="hidden" name="campagne_id" value="<?= $cid ?>">
                                <input type="hidden" name="action" value="retirer">
                                <button type="submit" class="btn danger btn-sm icon-only" title="Retirer de la campagne" aria-label="Retirer de la campagne"><?= icon('trash') ?></button>
                            </form>
                            <button type="button" class="btn ghost btn-sm icon-only camp-ligne-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($campagnesLiees): ?>
            <?php // Les campagnes qui visent l'organisateur, ou les salles et
                  // festivals que cette structure organise. En lecture seule :
                  // la réponse qui s'y lit appartient à l'autre structure, et
                  // c'est depuis SA fiche qu'on la change. Même marqueur que la
                  // carte Événements pour dire de qui il s'agit (.ico-tiny +
                  // blocks/building). ?>
            <tr class="mois-sep"><td colspan="4">Structures liées</td></tr>
            <?php foreach ($campagnesLiees as $c): $cid = (int) $c['id']; ?>
                <tr>
                    <td class="col-camp">
                        <div class="small"><?= $c['projets'] ? e(implode(' · ', $c['projets'])) : '<span class="muted">Sans projet</span>' ?></div>
                        <a href="?p=booking_campagne&id=<?= $cid ?>"><?= e((string) $c['nom']) ?></a>
                        <div class="muted small">
                            <span class="ico-tiny"><?= icon($c['structure_sens'] === 'organise' ? 'blocks' : 'building') ?></span>
                            <a href="<?= url_avec_retour('?p=structure&id=' . (int) $c['structure_id'], 'structure', $sid) ?>"><?= e((string) $c['structure_nom']) ?></a>
                        </div>
                    </td>
                    <td class="muted small col-periode"><?= $campPeriode($c) ?></td>
                    <td class="nowrap col-reponse"><?= $campReponseLecture($c) ?></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($peutEcrireBooking): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Lecture ↔ édition, ligne par ligne : le crayon révèle ce qui modifie et
    // s'efface, la croix rend la ligne à son état de lecture. Rien à enregistrer
    // — le sélecteur de réponse écrit au clic — d'où l'absence de bouton
    // « Enregistrer », et « Annuler » qui ne défait rien : il referme.
    document.querySelectorAll('.campagnes-fiche .camp-ligne').forEach(function (tr) {
        var basculer = function (edition) {
            tr.querySelectorAll('.camp-ligne-edition').forEach(function (e) { e.hidden = !edition; });
            // Absente d'une ligne « À venir » : rien à cacher, la cellule
            // n'a pas de version modifiable.
            var lecture = tr.querySelector('.camp-reponse-lecture');
            if (lecture) { lecture.hidden = edition; }
            tr.querySelector('.camp-ligne-crayon').hidden = edition;
        };
        tr.querySelector('.camp-ligne-crayon').addEventListener('click', function () { basculer(true); });
        tr.querySelector('.camp-ligne-annuler').addEventListener('click', function () { basculer(false); });
        // La réponse change sous le sélecteur : l'icône de lecture doit dire la
        // même chose quand on referme (segchange, voir lassoInitSegToggleAjax()).
        tr.addEventListener('segchange', function (e) {
            tr.querySelectorAll('[data-reponse-vue]').forEach(function (icone) {
                icone.hidden = icone.getAttribute('data-reponse-vue') !== e.detail.apres;
            });
        });
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
