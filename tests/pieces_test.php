<?php
// Garde-fou des pièces comptables. Lancement : php tests/pieces_test.php
//
// Ce que ce fichier protège tient en une phrase : L'ÉTAT D'UNE ÉCRITURE ET
// CELUI D'UNE PIÈCE SE CALCULENT DEUX FOIS, en PHP pour l'affichage et en SQL
// pour les filtres et les compteurs. Deux implémentations de la même règle,
// c'est deux chances de dériver — et la dérive serait muette : l'écran dirait
// « 12 écritures sans pièce », le filtre en montrerait 9, et personne ne
// saurait laquelle a raison.
//
// Le test ne relit donc pas les règles une à une : il monte des cas, puis
// vérifie que les deux chemins tombent d'accord et que les états forment une
// PARTITION EXACTE — chaque écriture dans un état et un seul, sans trou ni
// recouvrement. C'est ce que l'œil ne peut pas vérifier sur six écrans.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_pieces_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/fichiers.php';
require_once __DIR__ . '/../lib/pieces.php';

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

// pieces_suggestions() passe par module_accessible(), donc par les droits de
// l'utilisateur COURANT : sans session elle rendrait un tableau vide, et les
// assertions du § 6 passeraient sans rien vérifier. On monte donc un compte
// qui a tout, comme l'application en a un.
$pdo->exec("INSERT INTO utilisateurs (id, email, mot_de_passe) VALUES (1, 'test@example.test', 'x')");
$permStmt = $pdo->prepare('INSERT OR REPLACE INTO utilisateur_permissions (utilisateur_id, module, niveau) VALUES (1, ?, ?)');
foreach (PERMISSION_MODULES as $m) {
    $permStmt->execute([$m, 'ecriture']);
}
$_SESSION = ['uid' => 1, 'last_activity' => time(), 'login_time' => time()];

// La base neuve est SEMÉE (seed_*, lib/db/schema.php) : on part donc d'un
// plan comptable et d'écritures qui existent déjà. On nettoie plutôt que de
// composer avec, pour que les décomptes ci-dessous portent sur des cas connus.
foreach (['pieces', 'ecritures', 'plan_comptes', 'comptes_bancaires'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec("INSERT INTO comptes_bancaires (id, libelle, iban) VALUES (1, 'Compte test', 'CH00TEST')");
$pdo->exec("INSERT INTO plan_comptes (id, libelle, sens, piece_attendue) VALUES (1, 'Loyer', 'charge', 1)");
$pdo->exec("INSERT INTO plan_comptes (id, libelle, sens, piece_attendue) VALUES (2, 'Frais bancaires', 'charge', 0)");

// Une écriture par cas, et un cas par état attendu.
$ecr = function (int $id, array $champs) use ($pdo): void {
    $champs += ['date_op' => '2026-01-10', 'texte' => 'E' . $id, 'montant' => -100.0];
    $cols = array_merge(['id', 'compte_bancaire_id', 'hash'], array_keys($champs));
    $vals = array_merge([$id, 1, 'h' . $id], array_values($champs));
    $pdo->prepare('INSERT INTO ecritures (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute($vals);
};
$ecr(1, []);                                            // rien du tout          → manquante
$ecr(2, ['plan_compte_id' => 1]);                       // lettrée, pièce due    → manquante
$ecr(3, ['plan_compte_id' => 2]);                       // catégorie dispensée   → dispensee
$ecr(4, ['piece_dispense_motif' => 'Pas de facture']);  // dispense isolée       → dispensee
$ecr(5, []);                                            // une pièce la justifie → piece
$pdo->exec("INSERT INTO pieces (libelle, montant, ecriture_id, date_payee, mode_paiement) VALUES ('Facture', -100, 5, '2026-01-10', 'compte')");

echo "1) Les cinq états d'une écriture\n";

$lignes = $pdo->query('SELECT e.*' . ECRITURE_PIECE_SELECT . ' FROM ecritures e ORDER BY e.id')->fetchAll();
$etats = [];
foreach ($lignes as $l) { $etats[(int) $l['id']] = ecriture_piece_etat($l); }
check('rien du tout → manquante', 'manquante', $etats[1] ?? '?');
check('lettrée dans une catégorie qui attend une pièce → manquante', 'manquante', $etats[2] ?? '?');
check('catégorie dispensée (plan_comptes.piece_attendue = 0) → dispensee', 'dispensee', $etats[3] ?? '?');
check('motif de dispense sur l\'écriture → dispensee', 'dispensee', $etats[4] ?? '?');
check('une pièce rattachée → piece', 'piece', $etats[5] ?? '?');

// ⚠️ Le justificatif le plus fort gagne : une écriture qui paie une fiche de
// salaire EST justifiée par elle, même si on lui a par ailleurs accroché une
// pièce et posé une dispense. Sans cet ordre, l'état dépendrait de celui des
// trois qu'on regarde en premier.
$pdo->exec("UPDATE ecritures SET piece_dispense_motif = 'motif' WHERE id = 5");
$l5 = $pdo->query('SELECT e.*' . ECRITURE_PIECE_SELECT . ' FROM ecritures e WHERE e.id = 5')->fetch();
check('une pièce l\'emporte sur une dispense', 'piece', ecriture_piece_etat($l5));
$pdo->exec("UPDATE ecritures SET piece_dispense_motif = '' WHERE id = 5");

echo "\n2) PHP et SQL disent la même chose\n";

// LE test de ce fichier. Les deux chemins existent pour des raisons
// différentes — l'un affiche une ligne déjà chargée, l'autre filtre sans rien
// charger — et rien dans le code ne les force à rester d'accord.
$parPhp = array_count_values($etats);
$ecarts = [];
foreach (ECRITURE_PIECE_ETATS as $etat) {
    $nSql = (int) $pdo->query('SELECT COUNT(*) FROM ecritures e WHERE ' . ecriture_piece_etat_sql($etat))->fetchColumn();
    $nPhp = $parPhp[$etat] ?? 0;
    if ($nSql !== $nPhp) {
        $ecarts[] = "$etat : PHP=$nPhp SQL=$nSql";
    }
}
check('aucun écart entre ecriture_piece_etat() et son SQL', [], $ecarts);

// Une partition : chaque écriture dans un état et un seul. Un recouvrement
// ferait compter deux fois la même ligne dans un filtre à deux cases cochées ;
// un trou la rendrait introuvable quelles que soient les cases.
$total = (int) $pdo->query('SELECT COUNT(*) FROM ecritures')->fetchColumn();
$somme = 0;
foreach (ECRITURE_PIECE_ETATS as $etat) {
    $somme += (int) $pdo->query('SELECT COUNT(*) FROM ecritures e WHERE ' . ecriture_piece_etat_sql($etat))->fetchColumn();
}
check('les cinq états partitionnent exactement les écritures', $total, $somme);

echo "\n3) Les trois états d'une pièce\n";

$pdo->exec("DELETE FROM pieces");
$pdo->exec("INSERT INTO pieces (id, libelle, montant, date_echeance) VALUES (1, 'Pas payée', -100, '2026-01-31')");
$pdo->exec("INSERT INTO pieces (id, libelle, montant, date_payee, mode_paiement) VALUES (2, 'Payée, pas rapprochée', -100, '2026-01-20', 'compte')");
$pdo->exec("INSERT INTO pieces (id, libelle, montant, date_payee, mode_paiement, ecriture_id) VALUES (3, 'Rapprochée', -100, '2026-01-20', 'compte', 1)");
$pdo->exec("INSERT INTO pieces (id, libelle, montant, date_payee, mode_paiement) VALUES (4, 'Espèces', -40, '2026-01-05', 'hors_compte')");

$pieces = [];
foreach ($pdo->query('SELECT * FROM pieces ORDER BY id') as $p) { $pieces[(int) $p['id']] = $p; }
check('sans date de paiement → à payer', 'a_payer', piece_etat($pieces[1]));
check('payée, sans écriture → à rapprocher', 'a_rapprocher', piece_etat($pieces[2]));
check('payée et rapprochée → classée', 'classee', piece_etat($pieces[3]));
// ⚠️ Sans ce cas, une dépense réglée en espèces serait restée à vie dans « à
// rapprocher » : aucune écriture ne viendra jamais la solder.
check('réglée hors du compte suivi → classée, sans écriture', 'classee', piece_etat($pieces[4]));

$ecartsP = [];
foreach (array_keys(PIECE_ETATS) as $etat) {
    $nSql = (int) $pdo->query('SELECT COUNT(*) FROM pieces p WHERE ' . piece_etat_sql($etat))->fetchColumn();
    $nPhp = count(array_filter($pieces, fn ($p) => piece_etat($p) === $etat));
    if ($nSql !== $nPhp) {
        $ecartsP[] = "$etat : PHP=$nPhp SQL=$nSql";
    }
}
check('aucun écart entre piece_etat() et son SQL', [], $ecartsP);
check('les trois états partitionnent exactement les pièces', count($pieces), array_sum(
    array_map(fn ($e) => (int) $pdo->query('SELECT COUNT(*) FROM pieces p WHERE ' . piece_etat_sql($e))->fetchColumn(), array_keys(PIECE_ETATS))
));

echo "\n4) Le retard\n";

// Une pièce déjà payée n'est jamais « en retard », même si l'échéance est
// passée : le geste a été fait. C'est l'erreur qu'un simple « échéance < ce
// jour » aurait commise, et elle aurait teinté d'ambre des lignes réglées.
check('échéance passée et pas payée → en retard', true, piece_en_retard($pieces[1], '2026-02-10'));
check('échéance passée mais payée → pas en retard', false, piece_en_retard($pieces[2] + ['date_echeance' => '2026-01-31'], '2026-02-10'));
check('échéance à venir → pas en retard', false, piece_en_retard($pieces[1], '2026-01-15'));
check('sans échéance → jamais en retard', false, piece_en_retard($pieces[4], '2030-01-01'));

echo "\n5) Les tables se répondent\n";

// Un type, un mode ou un état dont l'icône ou le libellé manque s'affiche vide
// sans rien signaler — le même piège que les statuts de structure.
check('chaque état d\'écriture a son libellé', [], array_values(array_diff(ECRITURE_PIECE_ETATS, array_keys(ECRITURE_PIECE_LIBELLES))));
check('chaque état d\'écriture a son icône', [], array_values(array_diff(ECRITURE_PIECE_ETATS, array_keys(ECRITURE_PIECE_ICONES))));
$sansTrace = [];
foreach (ECRITURE_PIECE_ICONES as $etat => $nom) {
    // « manquante » n'a volontairement pas d'icône : c'est son absence qui se
    // lit dans la colonne.
    if ($nom !== '' && trim(icone_chemins($nom)) === '') { $sansTrace[] = $etat . ' → ' . $nom; }
}
check('chaque icône d\'état a son tracé', [], $sansTrace);
$sansTraceType = [];
foreach (PIECE_TYPES as $k => $t) {
    if (trim(icone_chemins($t['icone'])) === '') { $sansTraceType[] = $k . ' → ' . $t['icone']; }
}
check('chaque type de pièce a son tracé d\'icône', [], $sansTraceType);

echo "\n6) Les rapprochements proposés\n";

$pdo->exec("DELETE FROM pieces");
$pdo->exec("DELETE FROM ecritures");
$ecr(10, ['date_op' => '2026-03-01', 'montant' => -1200.0, 'texte' => 'LOYER MARS']);
$ecr(11, ['date_op' => '2026-05-01', 'montant' => -1200.0, 'texte' => 'LOYER MAI']);
$ecr(12, ['date_op' => '2026-03-20', 'montant' => -480.0,  'texte' => 'PAIEMENT SWISSCOM SA']);
$ecr(13, ['date_op' => '2026-03-21', 'montant' => -999.0,  'texte' => 'AUTRE CHOSE']);

// Une facture de loyer payée le 1er mars. Son écriture du 1er mars est déjà
// prise : la suivante est à 61 jours, hors fenêtre.
$pdo->exec("INSERT INTO pieces (id, libelle, tiers, montant, date_payee, mode_paiement, ecriture_id) VALUES (20, 'Déjà liée', 'Régie', -1200, '2026-03-01', 'compte', 10)");
$pdo->exec("INSERT INTO pieces (id, libelle, tiers, montant, date_payee, mode_paiement) VALUES (21, 'Loyer mars', 'Régie', -1200, '2026-03-01', 'compte')");
$sugg = pieces_suggestions();
// ⚠️ LE piège qui a réellement mordu : avec execute([...]), PDO lie la fenêtre
// en CHAÎNE, et SQLite tient alors tout nombre pour inférieur à tout texte —
// « 61 <= '45' » est vrai. La fenêtre ne filtrait rien et le loyer de mai se
// proposait pour la facture de mars.
check('une écriture hors de la fenêtre de 45 jours n\'est pas proposée', [], array_map(
    fn ($s) => (int) $s['ecriture']['id'],
    array_values(array_filter($sugg, fn ($s) => (int) $s['piece']['id'] === 21))
));

// Le nom retrouvé dans le libellé : c'est lui qui fait la différence entre
// « probablement » et « sûrement », et qui décide des lignes pré-cochées.
$pdo->exec("INSERT INTO pieces (id, libelle, tiers, montant, date_payee, mode_paiement) VALUES (22, 'Abonnement', 'Swisscom', -480, '2026-03-18', 'compte')");
$sugg = pieces_suggestions();
$pour22 = array_values(array_filter($sugg, fn ($s) => (int) $s['piece']['id'] === 22));
check('le nom du tiers retrouvé dans le libellé → proposé', 1, count($pour22));
check('…et signalé comme reconnu par le nom', true, $pour22 ? (bool) $pour22[0]['par_nom'] : false);
check('…sur la bonne écriture', 12, $pour22 ? (int) $pour22[0]['ecriture']['id'] : 0);

// Un tiers trop court se retrouverait dans la moitié des libellés.
check('un tiers de moins de trois lettres ne vaut pas reconnaissance', false,
    pieces_nom_present('AB', ['texte' => 'VIREMENT ABC SARL']));
check('la casse ne compte pas', true,
    pieces_nom_present('swisscom', ['texte' => 'PAIEMENT SWISSCOM SA']));

// Une pièce réglée hors du compte n'attend aucune écriture : la proposer
// serait proposer un rapprochement qui n'a pas lieu d'être.
$pdo->exec("INSERT INTO pieces (id, libelle, tiers, montant, date_payee, mode_paiement) VALUES (23, 'Espèces', 'Swisscom', -480, '2026-03-18', 'hors_compte')");
$sugg = pieces_suggestions();
check('une pièce réglée hors du compte n\'est jamais proposée', [], array_values(array_filter(
    array_map(fn ($s) => (int) $s['piece']['id'], $sugg), fn ($id) => $id === 23
)));

// Une écriture ne peut être proposée qu'une fois : valider deux suggestions
// qui la désignent donnerait deux pièces sur le même paiement.
$ecrProposees = array_map(fn ($s) => (int) $s['ecriture']['id'], $sugg);
check('aucune écriture proposée deux fois', count($ecrProposees), count(array_unique($ecrProposees)));

@unlink($tmp);
foreach (['-wal', '-shm'] as $suf) { @unlink($tmp . $suf); }

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
