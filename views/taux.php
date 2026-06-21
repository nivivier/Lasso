<?php
/** @var bool $saved */ /** @var int $annee */ /** @var array $annees */
/** @var array $taux */ /** @var bool $configuree */
$tx = fn(string $k) => e(number_format((float) ($taux[$k] ?? 0) * 100, 4, '.', ''));
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Taux de l'année <?= $annee ?> enregistrés.</p><?php endif; ?>

<div class="card form">
    <div class="year-bar">
        <h2>Grille de taux</h2>
        <label class="inline">Année
            <select onchange="location.href='?p=taux&annee='+this.value">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="muted small">
        Les taux sont <strong>propres à chaque année</strong>. Modifier l'année <?= $annee ?> n'affecte pas
        les fiches des autres années. Une fiche déjà créée conserve de toute façon les taux figés à sa création.
        <?php if (!$configuree): ?>
            <br><span class="tag-warn">Année <?= $annee ?> non encore configurée</span> — valeurs reprises de l'année précédente (ou par défaut). Enregistrez pour les fixer.
        <?php endif; ?>
    </p>

    <form method="post" action="?p=taux">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="annee" value="<?= $annee ?>">

        <h3 class="sub">Part employé (en %)</h3>
        <div class="grid-taux">
            <?php
            $partEmp = [
                'taux_avs'         => '<abbr title="Assurance Vieillesse et Survivants">AVS</abbr> / <abbr title="Assurance Invalidité">AI</abbr> / <abbr title="Allocations pour Perte de Gain">APG</abbr>',
                'taux_ac'          => '<abbr title="Assurance Chômage">AC</abbr>',
                'taux_amat'        => '<abbr title="Assurance Maternité (Genève)">A.Mat</abbr>',
                'taux_laa_reduit'  => '<abbr title="Assurance contre les Accidents">LAA</abbr> réduit',
                'taux_laa_plein'   => '<abbr title="Assurance contre les Accidents">LAA</abbr> plein',
                'taux_lpp'         => '<abbr title="Prévoyance Professionnelle (2e pilier)">LPP</abbr>',
            ];
            foreach ($partEmp as $k => $lib): ?>
                <label><span class="lbl"><?= $lib // Pas de e() car déjà échappé dans le HTML de l'abbr ?></span>
                    <span class="pct-input">
                        <input name="<?= $k ?>" type="text" inputmode="decimal" value="<?= $tx($k) ?>">
                        <span class="pct-suffix">%</span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <h3 class="sub">Part employeur — charges patronales (en %)</h3>
        <p class="muted small">Cotisations à la charge de l'employeur. À confirmer avec votre affiliation <abbr title="Office Cantonal des Assurances Sociales">OCAS</abbr> et votre caisse de pension.</p>
        <div class="grid-taux">
            <?php
            $partPat = [
                'emp_taux_avs'        => '<abbr title="Assurance Vieillesse et Survivants">AVS</abbr> / <abbr title="Assurance Invalidité">AI</abbr> / <abbr title="Allocations pour Perte de Gain">APG</abbr>',
                'emp_taux_ac'         => '<abbr title="Assurance Chômage">AC</abbr>',
                'emp_taux_amat'       => '<abbr title="Assurance Maternité (Genève)">A.Mat</abbr>',
                'emp_taux_af'         => 'Allocations familiales',
                'emp_taux_laa_reduit' => '<abbr title="Assurance contre les Accidents">LAA</abbr> réduit',
                'emp_taux_laa_plein'  => '<abbr title="Assurance contre les Accidents">LAA</abbr> plein',
                'emp_taux_frais'      => 'Frais d\'administration',
                'emp_taux_cpe'        => '<abbr title="Contribution Petite Enfance">CPE</abbr>',
                'emp_taux_lfp'        => '<abbr title="Loi sur la Formation Professionnelle">LFP</abbr>',
                'emp_taux_lpp'        => '<abbr title="Prévoyance Professionnelle (2e pilier)">LPP</abbr>',
            ];
            foreach ($partPat as $k => $lib): ?>
                <label><span class="lbl"><?= $lib // Pas de e() car déjà échappé dans le HTML de l'abbr ?></span>
                    <span class="pct-input">
                        <input name="<?= $k ?>" type="text" inputmode="decimal" value="<?= $tx($k) ?>">
                        <span class="pct-suffix">%</span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted small">
            <strong><abbr title="Assurance contre les Accidents">LAA</abbr></strong> : deux taux selon le total d'heures du mois — <strong>réduit</strong> si ≤ (jours du mois ÷ 7 × 8), sinon <strong>plein</strong>. Le bon taux est choisi automatiquement à la création de chaque fiche. La <strong><abbr title="Prévoyance Professionnelle (2e pilier)">LPP</abbr></strong> a un taux unique.
            <strong><abbr title="Office Cantonal des Assurances Sociales">OCAS</abbr></strong> regroupe AVS/AI/APG, AC, allocations familiales, maternité, frais, CPE et LFP.
        </p>

        <div class="form-actions">
            <button type="submit">Enregistrer les taux <?= $annee ?></button>
        </div>
    </form>
</div>
