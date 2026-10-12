<?php
// Garde-fou de l'annuaire des personnes. Lancement : php tests/personnes_test.php
//
// La migration 100 a sorti l'identité de trois tables qui ne se parlaient pas
// — les contacts d'une structure, les employés, les comptes — pour la poser
// dans « personnes ». Deux choses doivent tenir, et aucune ne se voit à
// l'écran :
//
//   1. écrire un contact touche DEUX tables, et le lire doit rendre
//      exactement les mêmes clés qu'avant, sans quoi des dizaines d'écrans
//      afficheraient des noms vides sans la moindre erreur ;
//   2. la FUSION ne perd rien et ne rend personne joignable à nouveau. Une
//      fusion fausse ne se défait pas : c'est le geste le plus dangereux de
//      tout le module.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_personnes_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/fichiers.php';
require_once __DIR__ . '/../lib/personnes.php';

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

$pdo = db();
foreach (['personne_structures', 'personnes', 'structures'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec("INSERT INTO structures (id, nom) VALUES (1, 'Salle A'), (2, 'Salle B')");

echo "1) Un contact se lit comme avant\n";

// ⚠️ LE test de compatibilité. Des dizaines d'écrans lisent $c['prenom'],
// $c['email'], $c['role'] — si CONTACT_SELECT cessait de les rendre, ils
// afficheraient du vide sans la moindre erreur, et personne ne le verrait
// avant de regarder la page.
$cid = contact_creer(1, [
    'prenom' => 'Jeanne', 'nom' => 'Dupraz', 'email' => 'j@test.ch', 'telephone' => '0221234567',
    'langue' => 'fr', 'role' => 'Programmation', 'est_booking' => 1, 'actif' => 1,
]);
$c = contact_charger($cid);
$attendues = ['id', 'structure_id', 'personne_id', 'role', 'formulaire_url', 'est_administration',
              'est_booking', 'actif', 'cree_le', 'prenom', 'nom', 'email', 'telephone', 'langue', 'desinscrit'];
check('toutes les clés d\'un contact sont là', [], array_values(array_diff($attendues, array_keys($c ?? []))));
check('le prénom vient de la personne', 'Jeanne', (string) $c['prenom']);
check('le rôle vient du lien', 'Programmation', (string) $c['role']);
check('est_booking vient du lien', 1, (int) $c['est_booking']);

echo "\n2) Le rôle n'est pas la personne\n";

// Ce que le N↔N apporte, et qu'aucun écran ne pouvait montrer avant : la même
// personne, deux salles, deux rôles.
$cid2 = contact_creer(2, ['role' => 'Technique'], (int) $c['personne_id']);
$st = personne_structures((int) $c['personne_id']);
check('une personne, deux structures', 2, count($st));
check('…avec deux rôles distincts', ['Programmation', 'Technique'],
    array_values(array_map(fn ($r) => (string) $r['role'], $st)));
check('…et une seule fiche d\'identité', 1, (int) $pdo->query('SELECT COUNT(*) FROM personnes')->fetchColumn());

// Modifier depuis une structure modifie la personne, donc l'autre aussi.
contact_enregistrer($cid2, ['nom' => 'Dupraz-Martin']);
check('le nom corrigé d\'un côté vaut de l\'autre', 'Dupraz-Martin', (string) contact_charger($cid)['nom']);

echo "\n3) Retirer un contact ne supprime pas la personne\n";

contact_supprimer($cid2);
check('le lien part', null, contact_charger($cid2));
check('…mais la personne reste, elle est encore ailleurs', 'Dupraz-Martin',
    (string) (personne_charger((int) $c['personne_id'])['nom'] ?? ''));
contact_supprimer($cid);
check('…et ne part que lorsque plus rien ne la retient', null, personne_charger((int) $c['personne_id']));

echo "\n4) La fusion ne perd rien\n";

$a = personne_creer(['prenom' => 'Paul', 'nom' => 'Durand', 'email' => 'p@test.ch', 'rue' => 'Rue A 1']);
$b = personne_creer(['prenom' => 'Paul', 'nom' => 'Durand', 'email' => 'p@test.ch', 'telephone' => '079']);
$pdo->prepare('UPDATE personnes SET desinscrit = 1 WHERE id = ?')->execute([$b]);
contact_creer(1, ['role' => 'Un'], $a);
contact_creer(1, ['role' => 'Deux'], $b);
contact_creer(2, ['role' => 'Trois'], $b);

personnes_fusionner($a, [$b]);
$p = personne_charger($a);
check('la cible garde ce qu\'elle avait', 'Rue A 1', (string) $p['rue']);
// ⚠️ Une source ne comble que les champs VIDES. « La dernière valeur gagne »
// ferait perdre une adresse renseignée au profit d'une fiche vide, sans que
// rien ne le dise.
check('…et hérite de ce qui lui manquait', '079', (string) $p['telephone']);
// ⚠️ Si l'une des fiches dit « ne plus m'écrire », c'est la personne entière
// qui l'a demandé. Fusionner ne doit JAMAIS rendre quelqu'un joignable.
check('la désinscription se propage, jamais l\'inverse', 1, (int) $p['desinscrit']);
check('la source disparaît', null, personne_charger($b));
// Deux rôles dans la MÊME structure n'en font qu'un : la fusion ne doit pas
// laisser la personne deux fois dans le même carnet.
check('deux rôles dans la même structure fusionnent', 2, count(personne_structures($a)));
check('aucune clé étrangère cassée', [], $pdo->query('PRAGMA foreign_key_check')->fetchAll());

echo "\n5) Les doublons se montrent, ils ne se fusionnent pas tout seuls\n";

$pdo->exec("DELETE FROM personne_structures");
$pdo->exec("DELETE FROM personnes");
personne_creer(['prenom' => 'Anne', 'nom' => 'Roux', 'email' => 'info@salle.ch']);
personne_creer(['prenom' => 'Marc', 'nom' => 'Blanc', 'email' => 'info@salle.ch']);
personne_creer(['prenom' => 'Luc', 'nom' => 'Noir', 'email' => 'luc@test.ch']);
$d = personnes_doublons();
check('une boîte partagée est signalée comme doublon probable', 1, count($d));
check('…avec ses deux fiches', 2, count($d[0]['personnes']));
// Et surtout : rien n'a bougé. Anne et Marc ne sont pas la même personne,
// seule leur boîte l'est — c'est pourquoi la fusion reste un geste humain.
check('…et rien n\'a été fusionné', 3, (int) $pdo->query('SELECT COUNT(*) FROM personnes')->fetchColumn());

echo "\n6) Un nom affichable, toujours\n";

check('prénom + nom', 'Luc Noir', personne_nom(['prenom' => 'Luc', 'nom' => 'Noir']));
check('à défaut, l\'adresse électronique', 'a@b.ch', personne_nom(['prenom' => '', 'nom' => '', 'email' => 'a@b.ch']));
check('à défaut de tout, un mot plutôt que du vide', 'Sans nom', personne_nom([]));

@unlink($tmp);
foreach (['-wal', '-shm'] as $suf) { @unlink($tmp . $suf); }

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
