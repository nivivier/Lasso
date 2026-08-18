<?php
// Tests de la recherche unifiée. Lancement : php tests/recherche_test.php
// N'utilise pas la base : on teste la construction de la clause SQL et la
// DÉCLARATION des sources. recherche_globale() elle-même interroge la base et
// dépend de la session courante, elle n'est pas couverte ici.

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/recherche.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        printf("  ok    %s\n", $label);
        return;
    }
    $fails++;
    printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

echo "1) Découpage de la saisie en mots (tous exigés)\n";
[$c1, $p1] = recherche_clause('hector');
check('un mot → une condition', 1, substr_count($c1, 'LIKE'));
check('un mot → un paramètre', 1, count($p1));

[$c2, $p2] = recherche_clause('hector deborde');
check('deux mots → deux conditions', 2, substr_count($c2, 'LIKE'));
check('deux mots → reliés par AND (et non OR)', true, str_contains($c2, ' AND ') && !str_contains($c2, ' OR '));
check('deux mots → deux paramètres', 2, count($p2));

[$c3, $p3] = recherche_clause('  espaces   multiples  ');
check('espaces multiples ignorés', 2, count($p3));

echo "2) Repli des accents, des deux côtés de la comparaison\n";
check('la colonne est repliée par SANS_ACCENTS()', true, str_contains($c1, 'SANS_ACCENTS(texte)'));
[, $pAcc] = recherche_clause('DÉBORDE');
check('le terme saisi est replié et mis en minuscules', '%deborde%', $pAcc[0]);
[, $pCedille] = recherche_clause('Françoise');
check('cédille repliée', '%francoise%', $pCedille[0]);

echo "3) Jokers SQL traités comme des caractères littéraux\n";
[, $pPct] = recherche_clause('100%');
check('le % saisi est échappé', '%100\%%', $pPct[0]);
[, $pUnd] = recherche_clause('a_b');
check('le _ saisi est échappé', '%a\_b%', $pUnd[0]);
check("clause avec ESCAPE", true, str_contains($c1, "ESCAPE '\\'"));

echo "4) Seuil de longueur\n";
check('un seul caractère → aucune recherche', [], recherche_globale('a'));
check('chaîne vide → aucune recherche', [], recherche_globale(''));
check('espaces seuls → aucune recherche', [], recherche_globale('   '));

// Garde-fou de SÉCURITÉ : une source mal déclarée exposerait des données d'un
// module auquel l'utilisateur n'a pas accès (« modules » absent = jamais
// filtré), ou ferait échouer la requête extérieure (colonne « texte » absente).
// Ces contrôles portent sur la déclaration, donc attrapent l'erreur à l'ajout
// d'une source, pas en production.
echo "5) Déclaration des sources — garde-fous\n";
$sources = recherche_sources();
check('au moins une source déclarée', true, count($sources) > 0);

foreach ($sources as $cle => $s) {
    foreach (['label', 'icone', 'modules', 'route', 'liste', 'ordre', 'sql'] as $clef) {
        check("« $cle » déclare « $clef »", true, isset($s[$clef]));
    }
    check("« $cle » : au moins un module de rattachement", true, is_array($s['modules']) && count($s['modules']) > 0);
    // Sans ce filtre, la source serait interrogée pour tout le monde.
    foreach ($s['modules'] as $m) {
        check("« $cle » : module « $m » connu", true, in_array($m, PERMISSION_MODULES, true));
    }
    // La requête extérieure filtre sur « texte » et trie sur les alias projetés :
    // chacun doit donc être un VRAI alias de sortie (« … AS texte »), pas un
    // simple mot présent quelque part dans la requête.
    foreach (['titre', 'sous_titre', 'tri', 'texte'] as $colonne) {
        check(
            "« $cle » projette « $colonne » comme alias",
            true,
            (bool) preg_match('/\bAS\s+' . $colonne . '\b/i', $s['sql'])
        );
    }
    // « id » est projetée telle quelle (« e.id »), sans alias.
    check("« $cle » projette « id »", true, (bool) preg_match('/\b[a-z]+\.id\b|\bAS\s+id\b/i', $s['sql']));
}

// Le filtrage par droits est l'invariant de sécurité du fichier : une source
// interrogée alors que l'utilisateur n'a pas le module exposerait des données
// que le reste de l'application lui refuse.
echo "6) Filtrage par droits (recherche_source_visible_pour)\n";
$src = recherche_sources();

check('employés visibles avec « salaires »', true,
    recherche_source_visible_pour($src['employes'], ['salaires']));
check('employés INVISIBLES sans « salaires »', false,
    recherche_source_visible_pour($src['employes'], ['booking', 'compta', 'evenements']));
check('aucun module accessible → aucune source visible', false,
    recherche_source_visible_pour($src['employes'], []));

// Entité partagée : un seul des deux modules suffit.
check('structures visibles avec « facturation » seul', true,
    recherche_source_visible_pour($src['structures'], ['facturation']));
check('structures visibles avec « booking » seul', true,
    recherche_source_visible_pour($src['structures'], ['booking']));
check('structures INVISIBLES sans aucun des deux', false,
    recherche_source_visible_pour($src['structures'], ['salaires', 'compta']));

// Les contacts sont plus restreints que les structures qui les portent : la
// fiche structure ne les montre qu'avec booking (voir $avecAside dans
// views/structure_form.php). Les exposer à un compte « facturation » les
// rendrait visibles là où la fiche elle-même les cache.
check('contacts visibles avec « booking »', true,
    recherche_source_visible_pour($src['contacts'], ['booking']));
check('contacts INVISIBLES avec « facturation » seul', false,
    recherche_source_visible_pour($src['contacts'], ['facturation']));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
