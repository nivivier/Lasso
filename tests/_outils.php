<?php
// Outils communs à tous les fichiers de tests : le compteur et check().
// Chaque fichier le charge (require_once __DIR__ . '/_outils.php') au lieu de
// redéfinir sa propre check() : 39 copies avaient divergé (tolérance sur les
// montants dans cinq d'entre elles seulement, trois mises en page d'échec).
// Pas de suffixe _test : tests/run.php ne le lance pas comme un test.

declare(strict_types=1);

$tests = 0;
$fails = 0;

// Une attente en nombre à virgule se compare au centime près (montants en
// CHF, arrondis par r2()) ; toute autre attente, à l'identique (===).
// TESTS_SILENCIEUX (défini AVANT le require) : seuls les échecs s'impriment —
// pour les tests qui vérifient des milliers de cas (pages_test, base_demo_test).
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = is_float($attendu)
        ? abs($attendu - (float) $obtenu) < 0.005
        : $attendu === $obtenu;
    if ($ok) {
        if (!defined('TESTS_SILENCIEUX')) {
            printf("  ok    %s\n", $label);
        }
        return;
    }
    $fails++;
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}
