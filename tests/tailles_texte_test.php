<?php
// Garde-fou de l'échelle typographique. Lancement : php tests/tailles_texte_test.php
//
// Six tokens portent les tailles de texte de toute l'application
// (--fs-tiny … --fs-huge, assets/app.css). La convention n'était écrite
// NULLE PART : le code la disait 206 fois contre 32, et c'est dans ces 32
// que la dette s'était logée.
//
// Douze d'entre elles recopiaient un token à l'identique — 11px là où
// --fs-small vaut 11px, 16px là où --fs-large vaut 16px — donc une valeur
// qui cessait de suivre le token le jour où celui-ci changerait, sans que
// rien ne le signale. Onze autres réglaient les mini-cartes de téléphone à
// l'œil (10px, 12px), hors de l'échelle : ces écrans se sont mis à vivre
// leur propre vie, invisible au développement qui se fait au large.
//
// Le test ne tient PAS une liste de ce qui devrait exister : il lit la
// feuille de style, et pose deux questions dont la réponse ne peut pas
// dériver.

$racine = dirname(__DIR__);

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
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

$css = (string) file_get_contents($racine . '/assets/app.css');

// Les tokens tels que la feuille les déclare, pas tels qu'on les croit.
preg_match_all('/--fs-([a-z]+)\s*:\s*([0-9.]+)px/', $css, $m);
$tokens = [];
foreach ($m[1] as $i => $nom) {
    $tokens['--fs-' . $nom] = $m[2][$i] . 'px';
}

echo "1) L'échelle existe et elle est complète\n";

check('les six tokens de taille sont déclarés', [
    '--fs-tiny'  => '9px',
    '--fs-small' => '11px',
    '--fs-basic' => '13px',
    '--fs-large' => '16px',
    '--fs-big'   => '20px',
    '--fs-huge'  => '32px',
], $tokens);

// Un var(--fs-…) mal orthographié est une règle VALIDE qui ne dessine rien :
// la propriété est invalide à l'exécution, l'élément hérite du corps de son
// parent, et l'écart se voit à peine.
preg_match_all('/var\(\s*(--fs-[a-z-]+)\s*\)/', $css, $mv);
$inconnus = array_values(array_unique(array_diff($mv[1], array_keys($tokens))));
check('chaque var(--fs-…) pointe un token déclaré', [], $inconnus);

echo "\n2) Aucune taille en dur ne recopie un token\n";

// C'est le vrai piège : la valeur et le token disent la même chose
// aujourd'hui, et plus la même demain.
preg_match_all('/font-size:\s*([0-9.]+px)/', $css, $md);
$enDur = $md[1];
$parValeur = array_count_values($enDur);

$doublons = [];
foreach ($parValeur as $valeur => $nb) {
    $token = array_search($valeur, $tokens, true);
    if ($token !== false) {
        $doublons[] = $valeur . ' (× ' . $nb . ') → ' . $token;
    }
}
check('aucune valeur en dur n\'a déjà son token', [], $doublons);

echo "\n3) Les tailles hors échelle sont celles qu'on a décidées\n";

// Il en reste neuf, toutes des corps d'AFFICHAGE : un chiffre, une date, un
// nom qu'on lit en premier. Elles tombent entre deux crans de l'échelle ou
// au-dessus du dernier, et les élargir pour elles seules déformerait
// l'échelle. Elles sont donc nommées ici une par une : en ajouter une
// nouvelle fait échouer ce test, ce qui est le but — la question doit se
// poser, pas se régler à l'œil.
//
//   12px  .dash-chart .ch-label   — légende du graphique comptable (SVG)
//   15px  .evt-ville, .ms-nom     — la ligne qu'on cherche des yeux
//   17px  .evt-date-j             — le jour, dans la pastille d'agenda
//   18px  .cartes-factures .col-montant, .fr-print-ou — montant, lieu imprimé
//   22px  .camp-icone .avatar-ini — initiales dans un médaillon de 64px
//   25px  .fr-print-date          — la date d'une feuille de route imprimée
//   34px  .camp-chiffre b         — le total d'une campagne
ksort($parValeur);
$horsEchelle = array_filter($parValeur, fn ($v, $k) => array_search($k, $tokens, true) === false, ARRAY_FILTER_USE_BOTH);
check('le relevé des tailles hors échelle n\'a pas bougé', [
    '12px' => 1,
    '15px' => 2,
    '17px' => 1,
    '18px' => 2,
    '22px' => 1,
    '25px' => 1,
    '34px' => 1,
], $horsEchelle);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
