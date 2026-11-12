<?php
// UN rattachement d'une personne à une structure, sur la fiche de la personne :
// la mini-fiche de la structure (views/_structure_mini.php), son rôle, et le
// bouton qui défait le lien en mode modification.
//
// Sorti de la boucle pour être rendu AUSSI tout seul : quand on lie une
// structure, route_personne_structure_lier() renvoie cette ligne-là et le
// script l'insère, sans recharger la page (docs/UI.md § 5).
//
// Attendu de l'appelant : $st (une ligne de personne_structures()), $pid,
// $peutLier (droit d'écriture sur le carnet).
/** @var array $st */ /** @var int $pid */ /** @var bool $peutLier */
$msS    = $st['structure'];
$msHref = '?p=structure&id=' . (int) $st['structure_id'];
$msSousNom = $st['role'] !== '' ? e((string) $st['role']) : '';
$msBadges = ((int) $st['est_booking'] === 1 ? badge('Booking', 'ok') : '')
    . ((int) $st['est_administration'] === 1 ? badge('Administration', 'ok') : '')
    . ((int) $st['actif'] !== 1 ? badge('Rattachement clos', 'muted') : '');
// Un rattachement clos se grise, même si la structure, elle, est bien
// vivante : c'est le LIEN que cette carte montre.
$msInactif = (int) $st['actif'] !== 1 || ($st['structure']['statut'] ?? '') === 'inactif';
$msAction = '';
if ($peutLier) {
    $msAction = '<form method="post" action="?p=personne_structure_delier" class="ms-action edit-only" data-confirm="Délier « '
        . e((string) ($st['structure']['nom'] ?? '')) . ' » ?">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="personne_id" value="' . (int) $pid . '">'
        . '<input type="hidden" name="lien_id" value="' . (int) $st['id'] . '">'
        . '<button type="submit" class="btn ghost btn-sm icon-only" title="Délier" aria-label="Délier '
        . e((string) ($st['structure']['nom'] ?? '')) . '">' . icon('unlink') . '</button>'
        . '</form>';
}
require __DIR__ . '/_structure_mini.php';
