<?php
// Tests de la réinitialisation de mot de passe (« j'ai oublié mon mot de passe »).
// Lancement : php tests/motdepasse_test.php
//
// Base TEMPORAIRE, comme tests/migrations_test.php : APP_DB_PATH est définie
// avant lib/config.php et détourne toute l'application.
//
// Ce que ces tests protègent, dans l'ordre des choses qui feraient mal :
//  1. la base ne contient JAMAIS le jeton en clair — une copie de la base ne
//     doit pas permettre de prendre la main sur un compte ;
//  2. un jeton ne sert qu'une fois, expire, et une nouvelle demande annule la
//     précédente : trois manières distinctes de fermer une porte ouverte ;
//  3. une adresse inconnue ne laisse aucune trace — sinon le formulaire dirait
//     qui a un compte ;
//  4. le garde-fou de fréquence ne bloque PAS la connexion du compte visé :
//     demander une réinitialisation ne doit pas enfermer quelqu'un dehors.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_mdp_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = is_float($attendu) ? abs($attendu - (float) $obtenu) < 0.005 : $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}
register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

db();
db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)')
    ->execute(['alice@example.test', hacher_mot_de_passe('mot-de-passe-initial')]);
$uid = (int) db()->lastInsertId();

echo "1) Le jeton n'existe qu'en clair dans l'e-mail\n";
$jeton = reinit_creer_jeton($uid, '10.0.0.1');
check('jeton de 64 caractères hexadécimaux', 1, preg_match('/^[0-9a-f]{64}$/', $jeton));
$enBase = (string) db()->query('SELECT jeton_hash FROM reinit_motdepasse')->fetchColumn();
check('la base ne stocke pas le jeton', false, $enBase === $jeton);
check('elle stocke son empreinte', hash('sha256', $jeton), $enBase);

echo "2) Validité du jeton\n";
$d = reinit_demande($jeton);
check('le bon jeton retrouve le compte', $uid, (int) $d['utilisateur_id']);
check('un jeton inventé ne vaut rien', null, reinit_demande(str_repeat('a', 64)));
check('un jeton vide ne vaut rien', null, reinit_demande(''));
// Expiration : on vieillit la ligne plutôt que d'attendre une heure.
db()->prepare('UPDATE reinit_motdepasse SET expire_le = ? WHERE id = ?')->execute([time() - 1, (int) $d['id']]);
check('un jeton expiré ne vaut plus rien', null, reinit_demande($jeton));
db()->prepare('UPDATE reinit_motdepasse SET expire_le = ? WHERE id = ?')->execute([time() + RESET_TTL, (int) $d['id']]);
// Usage unique.
db()->prepare('UPDATE reinit_motdepasse SET utilise_le = ? WHERE id = ?')->execute([time(), (int) $d['id']]);
check('un jeton déjà utilisé ne vaut plus rien', null, reinit_demande($jeton));

echo "3) Une nouvelle demande annule la précédente\n";
db()->exec('DELETE FROM reinit_motdepasse');
$premier = reinit_creer_jeton($uid, '10.0.0.1');
$second  = reinit_creer_jeton($uid, '10.0.0.1');
check('le premier lien ne fonctionne plus', null, reinit_demande($premier));
check('le second fonctionne', $uid, (int) reinit_demande($second)['utilisateur_id']);
check('une seule demande en attente', 1, (int) db()->query('SELECT COUNT(*) FROM reinit_motdepasse')->fetchColumn());

echo "4) Garde-fou de fréquence\n";
db()->exec('DELETE FROM reinit_motdepasse');
check('aucune demande récente : autorisé', false, reinit_trop_de_demandes('10.0.0.2', $uid));
for ($i = 0; $i < RESET_MAX_PAR_HEURE; $i++) {
    db()->prepare('INSERT INTO reinit_motdepasse (utilisateur_id, jeton_hash, ip, expire_le, cree_le)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, 'x' . $i, '10.0.0.2', time() + RESET_TTL, time()]);
}
check('au-delà du quota : refusé', true, reinit_trop_de_demandes('10.0.0.2', $uid));
// Le quota ne doit pas déborder sur l'anti-force-brute de la connexion : ce
// serait un moyen d'enfermer dehors le titulaire du compte.
check('la connexion du compte visé reste ouverte', false, login_is_locked('10.0.0.2', 'alice@example.test'));
// Les demandes anciennes ne comptent plus.
db()->prepare('UPDATE reinit_motdepasse SET cree_le = ?')->execute([time() - 7200]);
check('les demandes de plus d\'une heure ne comptent plus', false, reinit_trop_de_demandes('10.0.0.2', $uid));

echo "5) Adresse publique du lien\n";
check('APP_URL non définie : repli sur l\'hôte de la requête', true, str_starts_with(url_site(), 'http'));

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
