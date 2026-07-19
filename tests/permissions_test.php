<?php
// Tests des droits par module (lecture/écriture). Lancement : php tests/permissions_test.php
// N'utilise pas la base de données (fonctions pures de lib/modules.php) :
// permissions_utilisateur()/peut_lire()/peut_ecrire()/require_*()/nb_admins()/
// enregistrer_permissions_utilisateur() appellent db()/current_user(), non testées ici.

require_once __DIR__ . '/../lib/modules.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-46s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

echo "1) permission_donne_lecture() / permission_donne_ecriture()\n";
check('lecture présente = accès lecture', true, permission_donne_lecture(['compta' => 'lecture'], 'compta'));
check('écriture présente = accès lecture aussi', true, permission_donne_lecture(['compta' => 'ecriture'], 'compta'));
check('absence de ligne = pas de lecture', false, permission_donne_lecture(['compta' => 'lecture'], 'evenements'));
check('lecture ne donne pas écriture', false, permission_donne_ecriture(['compta' => 'lecture'], 'compta'));
check('écriture donne écriture', true, permission_donne_ecriture(['compta' => 'ecriture'], 'compta'));
check('absence de ligne = pas d\'écriture', false, permission_donne_ecriture([], 'coeur'));

echo "2) clamp_permissions_dependantes() — analytique dépend de compta\n";
check(
    'analytique écriture + compta lecture → analytique ramené à lecture',
    ['compta' => 'lecture', 'analytique' => 'lecture'],
    clamp_permissions_dependantes(['compta' => 'lecture', 'analytique' => 'ecriture'])
);
check(
    'analytique écriture + compta écriture → inchangé',
    ['compta' => 'ecriture', 'analytique' => 'ecriture'],
    clamp_permissions_dependantes(['compta' => 'ecriture', 'analytique' => 'ecriture'])
);
check(
    'analytique seul (sans compta) → retiré entièrement',
    ['salaires' => 'lecture'],
    clamp_permissions_dependantes(['salaires' => 'lecture', 'analytique' => 'lecture'])
);
check(
    'aucun module dépendant présent → inchangé',
    ['coeur' => 'ecriture', 'salaires' => 'lecture'],
    clamp_permissions_dependantes(['coeur' => 'ecriture', 'salaires' => 'lecture'])
);
check(
    'analytique lecture + compta écriture → inchangé (lecture ≤ écriture)',
    ['compta' => 'ecriture', 'analytique' => 'lecture'],
    clamp_permissions_dependantes(['compta' => 'ecriture', 'analytique' => 'lecture'])
);

echo "3) PERMISSION_MODULES\n";
check('coeur listé', true, in_array('coeur', PERMISSION_MODULES, true));
check('un module par entrée de MODULES + coeur', count(MODULES) + 1, count(PERMISSION_MODULES));

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
