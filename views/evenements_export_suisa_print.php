<?php
/** @var array $entetes */ /** @var array $lignes */ /** @var string $exportQs */
// Aperçu de l'export SUISA. Page d'impression ordinaire : c'est la fenêtre
// partagée des aperçus (liens [data-preview]) qui l'affiche, comme pour une
// fiche de salaire ou un bilan.
$nomEmployeur = (string) param('employeur_nom');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Export SUISA<?= $nomEmployeur !== '' ? ' — ' . e($nomEmployeur) : '' ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <?php // Une seule action mise en évidence — le téléchargement, qui est le but
          // de cet écran. La copie reste secondaire. Pas d'impression : un
          // tableau de dix-huit colonnes n'est pas fait pour le papier. ?>
    <div class="print-toolbar">
        <a class="btn" href="?p=evenements_export_suisa&amp;<?= e($exportQs) ?>"><?= icon('download') ?> Télécharger en CSV</a>
        <button type="button" class="btn ghost" id="suisa-copier"><?= icon('copy') ?> Copier</button>
    </div>
    <div class="sheet sheet-ajuste">
        <h1 class="export-titre">Export SUISA <span class="muted"><?= count($lignes) ?> <?= count($lignes) > 1 ? 'événements' : 'événement' ?></span></h1>
        <div class="table-scroll">
        <table class="list export-apercu-table">
            <thead><tr><?php foreach ($entetes as $t): ?><th><?= e($t) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (!$lignes): ?>
                <tr><td colspan="<?= count($entetes) ?>" class="muted">Aucun événement à exporter avec les filtres actuels.</td></tr>
            <?php endif; ?>
            <?php foreach ($lignes as $ligne): ?>
                <tr><?php foreach ($ligne as $v): ?><td><?= $v !== '' ? e((string) $v) : '<span class="muted">—</span>' ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
// « Copier » : le tableau dans le presse-papiers, en HTML (il se colle avec ses
// colonnes dans un tableur) ET en texte tabulé (repli pour le texte brut). Les
// cellules vides s'affichent avec un tiret, mais se copient vides — un tiret
// collé dans un tableur serait une valeur parasite.
document.getElementById('suisa-copier').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    const table = document.querySelector('.export-apercu-table');
    const tsv = [...table.querySelectorAll('tr')].map(tr =>
        [...tr.querySelectorAll('th, td')]
            .map(c => c.textContent.trim() === '—' ? '' : c.textContent.trim())
            .join('\t')
    ).join('\n');
    const initial = btn.innerHTML;
    const dire = (t) => { btn.textContent = t; setTimeout(() => { btn.innerHTML = initial; }, 1800); };
    // De la copie la plus riche à la plus simple : tous les navigateurs n'ont
    // pas les mêmes moyens, et on préfère dire l'échec plutôt que le taire.
    try {
        await navigator.clipboard.write([new ClipboardItem({
            'text/html':  new Blob([table.outerHTML], { type: 'text/html' }),
            'text/plain': new Blob([tsv], { type: 'text/plain' }),
        })]);
        return dire('Copié');
    } catch (err) { /* on tente plus simple */ }
    try {
        await navigator.clipboard.writeText(tsv);
        return dire('Copié');
    } catch (err) { /* on tente plus simple encore */ }
    try {
        const sel = window.getSelection(), plage = document.createRange();
        plage.selectNodeContents(table);
        sel.removeAllRanges(); sel.addRange(plage);
        const ok = document.execCommand('copy');
        sel.removeAllRanges();
        dire(ok ? 'Copié' : 'Copie impossible');
    } catch (err) { dire('Copie impossible'); }
});
</script>
</body>
</html>
