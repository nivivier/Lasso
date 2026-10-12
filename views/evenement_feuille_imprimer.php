<?php
// Feuille de route imprimable d'une date : ce qu'on emporte le jour même, sur
// papier ou en PDF depuis le navigateur. Le contenu vient du corps partagé avec
// la fenêtre de consultation (_evenement_feuille_corps.php) ; il n'y a ici que
// l'enveloppe — l'en-tête à l'employeur, le bouton d'impression, le pied.
/** @var array $evenement */ /** @var array $elements */ /** @var array $organisateurs */
/** @var array $destinataires */ /** @var array $sansAdresse */ /** @var bool $peutEnvoyer */
/** @var string $nomEmployeur */
$titrePage = trim((string) ($evenement['projet_nom'] ?? '')) ?: 'Date';
?>
<?php
$impTitre = "Feuille de route — " . $titrePage . ', ' . date('d.m.Y', strtotime((string) $evenement['date']));
// La feuille est resserrée : marges réduites d'un cran par rapport à la page
// A4 générique, pour tenir sur une page sans rogner le contenu.
$impClasse = 'sheet-feuille';

// L'envoi à l'équipe vit dans la barre d'impression, donc dans la fenêtre
// d'aperçu qui affiche cette page : c'est là qu'on a la feuille sous les yeux
// et qu'on décide de la diffuser. Construit en tampon parce qu'il contient du
// PHP, et passé au partiel qui porte la barre.
ob_start(); ?>
        <?php if ($peutEnvoyer): ?>
        <?php // Empêché plutôt qu'absent quand personne n'est joignable : la
              // raison se lit au survol, là où un bouton disparu n'apprend rien.
              // Même choix que « Contacter » dans le suivi d'une campagne. ?>
        <?php $frRaison = $destinataires ? '' : ($sansAdresse
            ? "Aucun employé lié à cette date n'a d'adresse e-mail."
            : "Aucun employé n'est lié à cette date."); ?>
        <?php // target="_top" : cette page est chargée dans une iframe par la
              // fenêtre d'aperçu (views/layout.php). Sans lui, la redirection
              // qui suit l'envoi afficherait la fiche de l'événement DANS le
              // cadre d'aperçu, à la place de la feuille. ?>
        <form method="post" action="?p=evenement_feuille_envoyer" target="_top" class="d-inline"
              data-confirm="<?= $destinataires
                  ? 'Envoyer cette feuille de route à ' . count($destinataires) . ' employé(e)s : '
                    . e(implode(', ', array_column($destinataires, 'nom'))) . ' ?'
                  : '' ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $evenement['id'] ?>">
            <button type="submit" class="btn ghost"<?= $destinataires ? '' : ' disabled' ?>
                    title="<?= $destinataires
                        ? 'Envoyer à ' . e(implode(', ', array_column($destinataires, 'nom')))
                        : e($frRaison) ?>"><?= icon('send') ?> Envoyer à tous les employés</button>
        </form>
        <?php endif; ?>
<?php $impActions = ob_get_clean();
require __DIR__ . '/_impression_debut.php'; ?>
        <div class="fr-print">
            <?php require __DIR__ . '/_evenement_feuille_corps.php'; ?>
            <?php // Le logo en pied, tout petit : sur une feuille de route, c'est
                  // la date et le lieu qu'on cherche des yeux, pas l'identité de
                  // qui l'a éditée — elle est là pour signer, pas pour ouvrir. ?>
            <p class="fr-print-pied">
                <?php $logo = param_logo('clair'); ?>
                <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" class="fr-print-logo"><?php endif; ?>
                <?= e($nomEmployeur) ?> · feuille de route éditée le <?= e(date('d.m.Y')) ?>
            </p>
        </div>
<?php require __DIR__ . '/_impression_fin.php'; ?>
