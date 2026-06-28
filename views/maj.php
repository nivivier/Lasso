<?php
/** @var string $canal */ /** @var string $locale */ /** @var ?string $distante */
/** @var ?string $shaLocal */ /** @var ?string $shaDist */ /** @var ?bool $aJour */
/** @var bool $execDispo */ /** @var bool $gitDispo */
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<div class="card">
    <h2 class="mt-0">Version installée</h2>
    <dl class="info-grid">
        <div><dt>Version</dt><dd><strong><?= e($locale) ?></strong><?= $shaLocal ? ' <span class="muted small">(' . e($shaLocal) . ')</span>' : '' ?></dd></div>
        <div><dt>Canal suivi</dt><dd><?= $canal === 'stable' ? 'Stable' : 'Test' ?></dd></div>
        <div><dt>Version disponible</dt><dd>
            <?php if ($distante === null): ?>
                <span class="muted">indéterminée (pas de réseau ?)</span>
            <?php else: ?>
                <?= e($distante) ?><?= $shaDist ? ' <span class="muted small">(' . e($shaDist) . ')</span>' : '' ?>
            <?php endif; ?>
        </dd></div>
        <div><dt>État</dt><dd>
            <?php if ($aJour === null): ?>
                <span class="badge warn-badge">Vérification impossible</span>
            <?php elseif ($aJour): ?>
                <span class="badge ok-badge">À jour</span>
            <?php else: ?>
                <span class="badge warn-badge">Mise à jour disponible</span>
            <?php endif; ?>
        </dd></div>
    </dl>
</div>

<div class="card form mt-22">
    <h2 class="mt-0">Canal</h2>
    <p class="muted small">Le canal <strong>test</strong> reçoit toutes les nouveautés en premier ; le canal <strong>stable</strong> ne suit que les versions validées.</p>
    <form method="post" action="?p=maj">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Canal de mise à jour
            <select name="canal" onchange="this.form.submit()">
                <option value="test" <?= $canal === 'test' ? 'selected' : '' ?>>Test (nouveautés en avant-première)</option>
                <option value="stable" <?= $canal === 'stable' ? 'selected' : '' ?>>Stable (versions validées)</option>
            </select>
        </label>
        <noscript><div class="form-actions"><button type="submit">Enregistrer</button></div></noscript>
    </form>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Diagnostic du serveur</h2>
    <p class="muted small">Détermine comment la mise à jour automatique pourra s'effectuer.</p>
    <dl class="info-grid">
        <div><dt>Fonction <code>exec()</code></dt><dd>
            <?php if ($execDispo): ?><span class="badge ok-badge">disponible</span><?php else: ?><span class="badge warn-badge">désactivée</span><?php endif; ?>
        </dd></div>
        <div><dt>Commande <code>git</code></dt><dd>
            <?php if ($gitDispo): ?><span class="badge ok-badge">disponible</span><?php else: ?><span class="badge warn-badge">indisponible</span><?php endif; ?>
        </dd></div>
    </dl>
    <p class="muted small">
        <?php if ($gitDispo): ?>
            ✓ La mise à jour automatique pourra se faire par <code>git</code> (comme <code>deploy.sh</code>).
        <?php elseif ($execDispo): ?>
            <code>exec()</code> est là mais pas <code>git</code> : la mise à jour automatique passera par téléchargement d'archive, ou restera en SSH.
        <?php else: ?>
            <code>exec()</code> est désactivée : la mise à jour automatique nécessitera un téléchargement d'archive, ou la mise à jour restera manuelle (SSH / <code>deploy.sh</code>).
        <?php endif; ?>
    </p>
    <p class="muted small">La mise à jour en un clic depuis cette page n'est pas encore active (à venir).</p>
</div>
