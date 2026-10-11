<?php /** @var array $suggestions */ /** @var ?int $ok */

$peutEcrire = peut_ecrire('pieces');
$jour = fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '—';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel('?p=pieces', 'Pièces') ?>

<?php if ($ok !== null): ?><p class="ok flash"><?= $ok ?> rapprochement(s) posé(s).</p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title"><h1>Rapprochements proposés</h1></div>
</div>

<?php if (!$suggestions): ?>
    <p class="muted">Aucun rapprochement à proposer. Une pièce et une écriture se répondent
       quand leur montant coïncide au centime et que leurs dates sont proches.</p>
<?php else: ?>
<?php // On PROPOSE, on ne pose pas : une facture reçue peut arriver en double,
      // et deux factures du même montant au même fournisseur sont la règle
      // plutôt que l'exception. Un lien faux posé en silence ne se découvre
      // qu'au bouclement (SPEC_PIECES.md § 8). ?>
<p class="muted">Montants identiques au centime, dates voisines. Cochez ce qui est juste —
   rien n'est posé tant que vous n'avez pas validé. Rattacher ne classe pas l'écriture : le
   lettrage reste un geste à part.</p>

<form method="post" action="?p=pieces_rapprochements">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="table-scroll mt-16">
    <table class="list">
        <thead><tr>
            <?php if ($peutEcrire): ?><th class="col-check"><input type="checkbox" id="rappr-tout" title="Tout cocher" aria-label="Tout cocher"></th><?php endif; ?>
            <th>La pièce</th>
            <th>L'écriture</th>
            <th class="num">Montant</th>
            <th>Indice</th>
        </tr></thead>
        <tbody>
        <?php foreach ($suggestions as $s): $p = $s['piece']; $ecr = $s['ecriture']; ?>
            <tr>
                <?php if ($peutEcrire): ?>
                <td class="col-check">
                    <?php // La paire entière, et pas la seule pièce : la
                          // suggestion a pu changer entre l'affichage et la
                          // validation, et la route revérifie les deux bouts. ?>
                    <input type="checkbox" name="paires[]" class="row-check"
                           value="<?= (int) $p['id'] ?>:<?= (int) $ecr['id'] ?>" <?= $s['par_nom'] ? 'checked' : '' ?>>
                </td>
                <?php endif; ?>
                <td>
                    <a href="?p=piece&id=<?= (int) $p['id'] ?>" class="titre-lien"><?= e((string) $p['libelle']) ?></a>
                    <span class="row-field-txt"><?= e((string) ($p['tiers'] ?: '—')) ?> · <?= e($jour($p['date_payee'] ?: ($p['date_echeance'] ?: $p['date_piece']))) ?></span>
                </td>
                <td>
                    <?= e((string) ($ecr['tiers'] ?: $ecr['texte'])) ?>
                    <span class="row-field-txt"><?= e((string) $ecr['compte_libelle']) ?> · <?= e($jour($ecr['date_op'])) ?></span>
                </td>
                <td class="num <?= (float) $p['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $p['montant']) ?></td>
                <td>
                    <?php // Le nom retrouvé dans le libellé fait toute la
                          // différence entre « probablement » et « sûrement » :
                          // ces lignes-là arrivent cochées, les autres non. ?>
                    <?php if ($s['par_nom']): ?>
                        <?= badge('Nom reconnu', 'ok') ?>
                    <?php else: ?>
                        <?= badge('Montant et date', 'muted') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($peutEcrire): ?>
    <div class="mt-22">
        <button type="submit" class="btn"><?= icon('check') ?> Rattacher ce qui est coché</button>
    </div>
    <?php endif; ?>
</form>
<?php endif; ?>
</div></div>
<?php if ($suggestions && $peutEcrire): ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('rappr-tout')?.addEventListener('change', e => {
    document.querySelectorAll('input[name="paires[]"]').forEach(c => { c.checked = e.target.checked; });
});
</script>
<?php endif; ?>
