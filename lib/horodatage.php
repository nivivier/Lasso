<?php
// Horodatages : la base les tient en UTC, « Y-m-d H:i:s » sans fuseau — c'est
// ce qu'écrit datetime('now'), défaut de toutes les colonnes horodatées
// (cree_le, importe_le, envoye_le…). PHP écrit donc avec
// horodatage_maintenant(), jamais date(), et l'on relit avec horodatage() ou
// horodatage_ts(), jamais strtotime() seul : il prendrait l'UTC pour de
// l'heure locale, et l'heure affichée retarderait d'une heure (deux en été).
// Une valeur qui porte son décalage (date('c'), « …+01:00 ») se lit telle
// quelle. Le pourquoi : docs/DECISIONS.md § Horodatages.
// ⚠️ Ne concerne pas les DATES sans heure (date d'une facture, d'un
// événement, dernier_contact_le) : un jour n'a pas de fuseau.
function horodatage_maintenant(): string
{
    return gmdate('Y-m-d H:i:s');
}

function horodatage_ts(?string $valeur): ?int
{
    $v = trim((string) $valeur);
    if ($v === '') {
        return null;
    }
    $ts = strtotime(preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) ? $v : $v . ' UTC');
    return $ts === false ? null : $ts;
}

// L'horodatage en heure locale (APP_FUSEAU), ou '' s'il est vide ou illisible.
function horodatage(?string $valeur, string $format = 'd.m.Y H:i'): string
{
    $ts = horodatage_ts($valeur);
    return $ts === null ? '' : date($format, $ts);
}
