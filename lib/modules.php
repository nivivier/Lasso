<?php
// Registre des modules applicatifs, activables/désactivables indépendamment
// (association « salaires seuls », « compta seule », etc.). Le cœur — comptes
// utilisateurs, apparence, paramètres généraux — n'est jamais désactivable.
//
// Le schéma de base reste toujours créé en entier (lib/db.php) : désactiver un
// module masque ses routes et son entrée de menu, il ne touche pas aux données.
// Les réactiver restitue l'accès aux données existantes, intactes.

declare(strict_types=1);

const MODULES = [
    'salaires'   => [
        'label'       => 'Fiches de salaire',
        'description' => 'Employés, fiches de salaire, certificats de salaire, taux',
        'requires'    => [],
    ],
    'compta'     => [
        'label'       => 'Comptabilité',
        'description' => 'Relevés bancaires, plan comptable, écritures, comptes annuels',
        'requires'    => [],
    ],
    'analytique' => [
        'label'       => 'Comptabilité analytique',
        'description' => "Axes, ventilation des écritures, des charges sociales et des fiches de salaire",
        'requires'    => ['compta'],
    ],
];

// Cœur de l'application : jamais désactivable, listé à titre indicatif dans
// les paramètres de modules.
const MODULE_COEUR = [
    'label'       => 'Cœur',
    'description' => 'Comptes utilisateurs, apparence, informations de l\'employeur, mises à jour, gestion des modules',
];

// Modules actuellement activés. Par défaut : tous — préserve le comportement
// des installations existantes, qui n'ont jamais configuré ce réglage.
function modules_actifs(): array
{
    $val = param('modules_actifs', implode(',', array_keys(MODULES)));
    $ids = array_filter(array_map('trim', explode(',', (string) $val)), fn ($id) => $id !== '');
    return array_values(array_intersect(array_keys(MODULES), $ids));
}

function module_actif(string $id): bool
{
    return in_array($id, modules_actifs(), true);
}

// Enregistre la sélection de modules activés. Un module dont une dépendance
// est absente est retiré automatiquement : jamais d'état incohérent (ex.
// « analytique » actif sans « compta »).
function set_modules_actifs(array $ids): void
{
    $ids = array_values(array_intersect(array_keys(MODULES), $ids));
    do {
        $avant = $ids;
        foreach (MODULES as $id => $def) {
            if (!in_array($id, $ids, true)) {
                continue;
            }
            foreach ($def['requires'] as $req) {
                if (!in_array($req, $ids, true)) {
                    $ids = array_values(array_diff($ids, [$id]));
                }
            }
        }
    } while ($ids !== $avant);

    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['modules_actifs', implode(',', $ids)]);
}

// Route d'atterrissage par défaut, selon les modules actifs.
function route_defaut(): string
{
    if (module_actif('salaires')) {
        return 'resumes';
    }
    if (module_actif('compta')) {
        return 'compta_ecritures';
    }
    return 'compte';
}
