<?php
/** @var bool $saved */ /** @var ?string $err */ /** @var array $postes */ /** @var array $usages */
/** @var int $annee */ /** @var array $annees */ /** @var array $taux */
/** @var array $baremes */ /** @var string $ageRef */ /** @var bool $configuree */
// Les lignes d'un décompte de salaire ET ce que chacune prélève, au même
// endroit : le taux appartient à une année, d'où le sélecteur d'année en tête.
// Deux listes, parce que ce sont deux colonnes distinctes du décompte — ce que
// l'employé se voit déduire, et ce que l'employeur paie en plus ; un
// glisser-déposer ne franchit donc pas la frontière (groupAttr: 'sens').
//
// Lecture d'abord : le crayon ouvre le formulaire d'une ligne (paliers d'âge
// compris, ils n'ont de sens que pour la leur), l'ordre se change en glissant la
// poignée, et les interrupteurs s'appliquent au clic — mêmes gestes que
// ?p=compta_axes et ?p=parametres_pays.
$sens = ['deduction' => 'Déduction employé', 'charge' => 'Charge patronale'];
$modes = [
    'taux'         => "Taux de l'année",
    'laa_seuil'    => "Deux taux (seuil d'heures)",
    'taux_employe' => "Taux propre à l'employé",
    'bareme_age'   => 'Barème par âge',
];
$bases = ['brut' => 'Salaire brut', 'coordonne' => 'Salaire coordonné'];
$rubriques = ['' => '—', '9' => '9 — cotisations AVS/AC/A.mat/LAA', '10.1' => '10.1 — LPP', '12' => '12 — impôt à la source'];
$ecriture = peut_ecrire('salaires');
$pct = fn (float $v) => number_format($v * 100, 4, '.', '');
$pctCourt = fn (float $v) => rtrim(rtrim(number_format($v * 100, 4, '.', ''), '0'), '.');

$parSens = ['deduction' => [], 'charge' => []];
foreach ($postes as $p) {
    $parSens[(string) $p['sens']][] = $p;
}
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($err): ?><p class="err flash"><?= e($err) ?></p><?php endif; ?>

<?php if ($ecriture): ?>
<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=postes" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="annee" value="<?= $annee ?>">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="order" value="">
</form>
<?php endif; ?>

<div class="year-bar">
    <h2 class="mt-0">Lignes du décompte <?= info_tip(
        "Les lignes qui composent un décompte de salaire, dans l'ordre où elles y apparaissent,
        et le taux que chacune applique pour l'année choisie. Une fiche déjà enregistrée garde ses
        propres lignes et ses propres taux, figés à sa création : rien de ce qui se règle ici ne la
        réécrit. Glissez une ligne pour la déplacer à l'intérieur de sa partie."
    ) ?></h2>
    <label class="inline">
        <select data-go-on-change="?p=postes&annee=" aria-label="Année des taux">
            <?php foreach ($annees as $a): ?>
                <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>
<?php if (!$configuree): ?>
    <p class="muted small"><span class="badge warn-badge">Année <?= $annee ?> non encore configurée</span> — les taux affichés sont repris de l'année précédente (ou des valeurs par défaut). Modifiez-en un pour fixer l'année.</p>
<?php endif; ?>
<p class="muted small">
    Modifier un taux ne touche aucune fiche déjà enregistrée : pour les mettre à jour, passez par le
    <a href="?p=fiches_recalcul&amp;annee=<?= $annee ?>">recalcul des fiches</a>.
    Les taux par défaut sont indicatifs — confirmez-les avec votre affiliation OCAS et votre caisse LPP/LAA.
</p>

<div id="postes-card">
<?php foreach ($sens as $cle => $titre): ?>
    <div class="section-head">
        <h3 class="mt-0"><?= e($titre) ?> <?= info_tip($cle === 'deduction'
            ? "Prélevé sur le salaire brut de l'employé : ces lignes réduisent le net à verser."
            : "À la charge de l'employeur, en plus du brut : ces lignes alimentent le coût total employeur et les charges à verser."
        ) ?></h3>
        <?php if ($ecriture): ?>
        <button type="button" class="btn ml-auto" data-show="add-<?= $cle ?>"><?= icon('plus') ?> Nouvelle ligne</button>
        <?php endif; ?>
    </div>
    <div class="card form table-scroll">
    <table class="list mb-16 plan-table postes-table">
        <thead>
            <tr>
                <th class="col-icon" title="Ligne prise en compte dans les nouvelles fiches">Actif</th>
                <th>Ligne</th>
                <th class="num">Taux <?= $annee ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$parSens[$cle]): ?>
            <tr><td colspan="4" class="muted small">Aucune ligne dans cette partie.</td></tr>
        <?php endif; ?>
        <?php foreach ($parSens[$cle] as $p): $id = (int) $p['id']; $usage = (int) ($usages[$id] ?? 0);
              $code = (string) $p['code']; $mode = (string) $p['mode']; ?>
            <tr class="plan-row <?= (int) $p['actif'] ? '' : 'plan-archive' ?>" data-id="<?= $id ?>" data-sens="<?= e($p['sens']) ?>">
                <td class="td-toggle">
                    <?php if ($ecriture): ?>
                    <form method="post" action="?p=postes" class="toggle-cell">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="toggle_actif">
                        <input type="hidden" name="annee" value="<?= $annee ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <label class="regle-toggle" title="<?= (int) $p['actif'] ? 'Désactiver cette ligne' : 'Activer cette ligne' ?>">
                            <input type="checkbox" name="actif" value="1" <?= (int) $p['actif'] ? 'checked' : '' ?> data-submit-on-change>
                            <span class="regle-toggle-pill"></span>
                        </label>
                    </form>
                    <?php else: ?>
                    <span class="badge <?= (int) $p['actif'] ? 'ok-badge' : 'muted-badge' ?>"><?= (int) $p['actif'] ? 'Active' : 'Inactive' ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="inline-edit">
                        <?php if ($ecriture): ?><span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span><?php endif; ?>
                        <span class="plan-nom">
                            <?= e($p['libelle']) ?>
                            <span class="muted small">
                                · <?= e($modes[$mode] ?? '') ?>
                                sur <?= e(mb_strtolower($bases[(string) $p['base']] ?? '', 'UTF-8')) ?>
                                <?php if ((string) $p['rubrique_certificat'] !== ''): ?> · certificat <?= e($p['rubrique_certificat']) ?><?php endif; ?>
                                <?php if ((string) $p['groupe_compta'] !== ''): ?> · compta <?= e($p['groupe_compta']) ?><?php endif; ?>
                                · <code><?= e($code) ?></code>
                            </span>
                        </span>
                        <?php if ($ecriture): ?>
                        <form method="post" action="?p=postes" class="inline-edit plan-edit" id="edit-<?= $id ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="section" value="edit">
                            <input type="hidden" name="annee" value="<?= $annee ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input name="libelle" value="<?= e($p['libelle']) ?>" class="grow" required aria-label="Libellé de la ligne">
                            <select name="sens" aria-label="Nature" title="Changer la nature déplace la ligne dans l'autre partie">
                                <?php foreach ($sens as $k => $lib): ?><option value="<?= $k ?>" <?= (string) $p['sens'] === $k ? 'selected' : '' ?>><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                            <select name="mode" aria-label="Mode de calcul">
                                <?php foreach ($modes as $k => $lib): ?><option value="<?= $k ?>" <?= $mode === $k ? 'selected' : '' ?>><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                            <select name="base" aria-label="Base de calcul">
                                <?php foreach ($bases as $k => $lib): ?><option value="<?= $k ?>" <?= (string) $p['base'] === $k ? 'selected' : '' ?>><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                            <select name="rubrique_certificat" aria-label="Case du certificat de salaire" title="Case du certificat de salaire alimentée par cette ligne">
                                <?php foreach ($rubriques as $k => $lib): ?><option value="<?= $k ?>" <?= (string) $p['rubrique_certificat'] === (string) $k ? 'selected' : '' ?>><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                            <input name="groupe_compta" value="<?= e($p['groupe_compta']) ?>" placeholder="groupe compta" size="8" aria-label="Regroupement comptable" title="Regroupement dans les récapitulatifs de charges (ex. ocas)">
                            <label class="regle-toggle edit-toggle" title="Ne pas faire figurer cette ligne sur un décompte quand son montant est nul">
                                <input type="checkbox" name="masquer_si_zero" value="1" <?= (int) $p['masquer_si_zero'] ? 'checked' : '' ?>>
                                <span class="regle-toggle-pill"></span>
                                <span>Masquer à 0</span>
                            </label>
                            <?php if ($mode === 'bareme_age'): ?>
                                <?php $paliers = $baremes[$id] ?? [[25, 34, 0], [35, 44, 0], [45, 54, 0], [55, 99, 0]]; ?>
                                <div class="bareme-bloc">
                                    <span class="muted small bareme-note">Paliers d'âge <?= $annee ?> — un âge hors de tout palier donne 0, une ligne laissée à 0-0 est ignorée.</span>
                                    <?php for ($i = 0; $i < max(5, count($paliers)); $i++): $l = $paliers[$i] ?? [0, 0, 0]; ?>
                                        <span class="bareme-palier">
                                            <input type="number" min="0" max="120" name="bareme[<?= $i ?>][min]" value="<?= (int) $l[0] ?>" aria-label="Âge minimum">
                                            <span class="muted">à</span>
                                            <input type="number" min="0" max="120" name="bareme[<?= $i ?>][max]" value="<?= (int) $l[1] ?>" aria-label="Âge maximum">
                                            <span class="pct-input">
                                                <input type="text" inputmode="decimal" name="bareme[<?= $i ?>][taux]" value="<?= e($pct((float) $l[2])) ?>" aria-label="Taux du palier">
                                                <span class="pct-suffix">%</span>
                                            </span>
                                        </span>
                                    <?php endfor; ?>
                                    <label class="inline">Âge de référence
                                        <select name="lpp_age_reference">
                                            <option value="annee" <?= $ageRef === 'annee' ? 'selected' : '' ?>>Âge atteint dans l'année</option>
                                            <option value="anniversaire" <?= $ageRef === 'anniversaire' ? 'selected' : '' ?>>Âge révolu au mois de la fiche</option>
                                        </select>
                                    </label>
                                </div>
                            <?php endif; ?>
                            <span class="muted small edit-usage"><?= $usage > 0
                                ? 'Utilisée par ' . $usage . ' fiche' . ($usage > 1 ? 's' : '')
                                : 'Aucune fiche ne l\'utilise' ?></span>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="num td-taux">
                    <?php
                    $sansTaux = $mode === 'taux_employe' || $mode === 'bareme_age';
                    $lecture  = $mode === 'taux_employe' ? 'par employé'
                        : ($mode === 'bareme_age' ? 'par âge'
                        : ($mode === 'laa_seuil'
                            ? $pctCourt((float) ($taux[$code . '_reduit'] ?? 0)) . ' % / ' . $pctCourt((float) ($taux[$code . '_plein'] ?? 0)) . ' %'
                            : $pctCourt((float) ($taux[$code] ?? 0)) . ' %'));
                    ?>
                    <span class="cell-lecture <?= $sansTaux ? 'muted' : '' ?>"><?= e($lecture) ?></span>
                    <?php if ($ecriture && !$sansTaux): ?>
                    <?php // Champs rattachés au formulaire de la ligne (attribut form=) :
                          // un seul enregistrement pour toute la ligne, taux compris. ?>
                    <span class="taux-form cell-edition">
                        <?php if ($mode === 'laa_seuil'): ?>
                            <span class="pct-input" title="Mois court : total d'heures ≤ jours ÷ 7 × 8">
                                <input form="edit-<?= $id ?>" name="valeur" type="text" inputmode="decimal" value="<?= e($pct((float) ($taux[$code . '_reduit'] ?? 0))) ?>" aria-label="Taux réduit">
                                <span class="pct-suffix">% réd.</span>
                            </span>
                            <span class="pct-input" title="Mois plein : au-delà du seuil d'heures">
                                <input form="edit-<?= $id ?>" name="valeur_alt" type="text" inputmode="decimal" value="<?= e($pct((float) ($taux[$code . '_plein'] ?? 0))) ?>" aria-label="Taux plein">
                                <span class="pct-suffix">% plein</span>
                            </span>
                        <?php else: ?>
                            <span class="pct-input">
                                <input form="edit-<?= $id ?>" name="valeur" type="text" inputmode="decimal" value="<?= e($pct((float) ($taux[$code] ?? 0))) ?>" aria-label="Taux <?= $annee ?>">
                                <span class="pct-suffix">%</span>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="actions nowrap">
                    <?php if ($ecriture): ?>
                    <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                    <?php // Boutons de l'édition rattachés par form= au formulaire de la
                          // ligne : ils se lisent à droite, avec la corbeille. ?>
                    <span class="cell-edition actions-edition">
                        <button type="submit" form="edit-<?= $id ?>" class="btn btn-sm" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                        <button type="button" class="btn ghost btn-sm icon-only plan-annuler-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    <form method="post" action="?p=postes" class="d-inline plan-fallback">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="reorder">
                        <input type="hidden" name="annee" value="<?= $annee ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="order" value="<?= e(postes_ordre_deplace($postes, $id, -1)) ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter"><?= icon('chevron-up') ?></button>
                    </form>
                    <form method="post" action="?p=postes" class="d-inline plan-fallback">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="reorder">
                        <input type="hidden" name="annee" value="<?= $annee ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="order" value="<?= e(postes_ordre_deplace($postes, $id, 1)) ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre"><?= icon('chevron-down') ?></button>
                    </form>
                        <form method="post" action="?p=postes" class="d-inline"
                              data-confirm="<?= e($usage > 0 ? 'Cette ligne est utilisée par ' . $usage . ' fiche(s) : elle sera désactivée, pas supprimée. Continuer ?' : 'Supprimer cette ligne ?') ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="section" value="del">
                            <input type="hidden" name="annee" value="<?= $annee ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                        </form>
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($ecriture): ?>
        <tfoot id="add-<?= $cle ?>" hidden>
            <tr>
                <td colspan="4">
                    <form method="post" action="?p=postes" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="add">
                        <input type="hidden" name="annee" value="<?= $annee ?>">
                        <input type="hidden" name="sens" value="<?= $cle ?>">
                        <input type="hidden" name="masquer_si_zero" value="1">
                        <input name="code" required placeholder="code (ex. cantonal)" size="12" aria-label="Code" title="Ne change plus ensuite : c'est lui qui relie une ligne figée à sa définition">
                        <input name="libelle" required placeholder="<?= $cle === 'deduction' ? 'ex. Cotisation cantonale' : 'ex. Fonds de formation' ?>" class="grow" aria-label="Libellé">
                        <select name="mode" aria-label="Mode de calcul"><?php foreach ($modes as $k => $lib): ?><option value="<?= $k ?>"><?= e($lib) ?></option><?php endforeach; ?></select>
                        <select name="base" aria-label="Base de calcul"><?php foreach ($bases as $k => $lib): ?><option value="<?= $k ?>"><?= e($lib) ?></option><?php endforeach; ?></select>
                        <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                        <button type="button" class="btn ghost btn-sm" data-hide="add-<?= $cle ?>"><?= icon('x') ?> Annuler</button>
                    </form>
                </td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
<?php endforeach; ?>
</div>

<?php if ($ecriture): ?>
<div class="section-head">
    <h3 class="mt-0">Salaire coordonné <?= info_tip(
        "Base des lignes assises sur le salaire coordonné (LPP). Montants ANNUELS, ramenés au mois par le calcul.
        À 0 — le réglage de départ — le coordonné vaut le brut."
    ) ?></h3>
</div>
<div class="card form">
    <form method="post" action="?p=postes">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="reglages">
        <input type="hidden" name="annee" value="<?= $annee ?>">
        <div class="grid-taux">
            <label><span class="lbl">Déduction de coordination</span>
                <span class="pct-input">
                    <input name="coord_deduction" type="text" inputmode="decimal" value="<?= e(number_format((float) ($taux['coord_deduction'] ?? 0), 2, '.', '')) ?>">
                    <span class="pct-suffix">CHF/an</span>
                </span>
            </label>
            <label><span class="lbl">Plafond du salaire coordonné</span>
                <span class="pct-input">
                    <input name="coord_plafond" type="text" inputmode="decimal" value="<?= e(number_format((float) ($taux['coord_plafond'] ?? 0), 2, '.', '')) ?>">
                    <span class="pct-suffix">CHF/an</span>
                </span>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer pour <?= $annee ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php // Toujours exécuté, même sans droit d'écriture : c'est « dnd-on » qui bascule
      // la liste en mode lecture (sans lui, les lignes resteraient invisibles). ?>
<script nonce="<?= e(csp_nonce()) ?>">
lassoOrdreListe({
    containerSelector: '#postes-card',
    rowsSelector: '.plan-row',
    scrollKey: 'postesScroll',
    formAction: '?p=postes',
    groupAttr: 'sens',
});
</script>
