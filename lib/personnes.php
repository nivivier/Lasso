<?php
// Les personnes (docs/SPEC_PERSONNES.md).
//
// Une personne existait dans trois tables qui ne se parlaient pas : les
// contacts d'une structure, les employés, les comptes. Elle a maintenant sa
// table, et les trois autres s'y rattachent.
//
// ⚠️ LE RÔLE N'EST PAS LA PERSONNE. personne_structures porte ce qu'on est
// DANS une structure — « Programmation », joignable pour le booking, pour
// l'administration — et rien de ce qu'on est tout court. C'est la confusion
// que l'ancienne table structure_contacts entretenait, et toute l'affaire de
// cette séparation : la même personne peut être programmatrice ici et
// trésorière là, et changer de salle sans changer de nom.

// Ce qu'on lit d'un CONTACT — c'est-à-dire d'un lien, identité comprise. Le
// reste du code attend les colonnes sous les noms que structure_contacts leur
// donnait : les alias les lui rendent, et aucun écran n'a eu à changer de
// vocabulaire.
//
// ⚠️ Ces deux constantes vont ENSEMBLE. Un SELECT sans le FROM qui lui
// correspond échoue à l'exécution, ce qui est préférable à l'inverse : une
// requête qui oublierait les alias rendrait des noms vides, sans rien dire.
const CONTACT_SELECT = 'cs.id, cs.structure_id, cs.personne_id, cs.role, cs.formulaire_url,'
    . ' cs.est_administration, cs.est_booking, cs.actif, cs.cree_le,'
    . ' pe.prenom, pe.nom, pe.email, pe.telephone, pe.langue, pe.desinscrit';
const CONTACT_FROM = ' FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id';

// Idem pour un EMPLOYÉ : l'emploi vit sur employes (AVS, canton, procédure,
// taux), l'identité sur personnes. Les vues continuent de lire $e['prenom'].
const EMPLOYE_SELECT = 'e.id, e.personne_id, e.numero_avs, e.canton, e.procedure, e.salaire_horaire,'
    . ' e.supplement_vacances, e.impot_source_taux, e.actif, e.avatar_couleur, e.avatar_photo, e.cree_le,'
    . ' pe.prenom, pe.nom, pe.email, pe.rue, pe.npa_localite, pe.date_naissance';
const EMPLOYE_FROM = ' FROM employes e LEFT JOIN personnes pe ON pe.id = e.personne_id';

// Les champs d'identité, dans l'ordre où on les saisit. Sert à l'écriture :
// une seule liste, pour que l'insertion et la mise à jour ne divergent pas.
const PERSONNE_CHAMPS = ['prenom', 'nom', 'email', 'telephone', 'langue', 'rue', 'npa_localite', 'date_naissance'];

function personne_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM personnes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Crée une personne et rend son identifiant. $champs : un sous-ensemble de
// PERSONNE_CHAMPS ; ce qui manque reste vide.
function personne_creer(array $champs): int
{
    $cols = array_values(array_intersect(PERSONNE_CHAMPS, array_keys($champs)));
    if (!$cols) {
        $cols = ['prenom'];
        $champs['prenom'] = '';
    }
    $sql = 'INSERT INTO personnes (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    db()->prepare($sql)->execute(array_map(fn ($c) => (string) ($champs[$c] ?? ''), $cols));
    return (int) db()->lastInsertId();
}

function personne_enregistrer(int $id, array $champs): void
{
    $cols = array_values(array_intersect(PERSONNE_CHAMPS, array_keys($champs)));
    if (!$cols) {
        return;
    }
    $sql = 'UPDATE personnes SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . ' WHERE id = ?';
    db()->prepare($sql)->execute(array_merge(array_map(fn ($c) => (string) ($champs[$c] ?? ''), $cols), [$id]));
}

// Le nom affichable d'une personne. Une personne sans nom existe — un contact
// importé dont on n'a que l'adresse —, et il vaut mieux montrer l'adresse que
// du vide.
function personne_nom(array $p): string
{
    $nom = trim(trim((string) ($p['prenom'] ?? '')) . ' ' . trim((string) ($p['nom'] ?? '')));
    if ($nom !== '') {
        return $nom;
    }
    $email = trim((string) ($p['email'] ?? ''));
    return $email !== '' ? $email : 'Sans nom';
}

// Les structures auxquelles une personne est rattachée, avec son rôle dans
// chacune. C'est ce que le N↔N apporte et qu'aucun écran ne pouvait montrer
// avant : le programmateur qui change de salle garde son fil.
function personne_structures(int $personneId): array
{
    $stmt = db()->prepare(
        'SELECT cs.*, s.nom AS structure_nom FROM personne_structures cs
           JOIN structures s ON s.id = cs.structure_id
          WHERE cs.personne_id = ? ORDER BY s.nom COLLATE NOCASE'
    );
    $stmt->execute([$personneId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Un CONTACT : une personne vue depuis une structure.
//
// Écrire un contact touche DEUX tables — l'identité d'un côté, le rôle de
// l'autre. Ces trois fonctions sont le seul endroit où cette couture se voit :
// partout ailleurs, on manipule un contact comme avant.

function contact_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT ' . CONTACT_SELECT . CONTACT_FROM . ' WHERE cs.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Les champs qui vivent sur le LIEN, par opposition à PERSONNE_CHAMPS.
const CONTACT_CHAMPS = ['role', 'formulaire_url', 'est_administration', 'est_booking', 'actif'];

// Crée un contact : une personne, puis son rattachement. $personneId permet de
// rattacher quelqu'un QUI EXISTE DÉJÀ à une structure de plus — c'est ce que
// le N↔N apporte, et c'est par là que le programmateur qui change de salle
// garde son fil.
function contact_creer(int $structureId, array $champs, ?int $personneId = null): int
{
    $personneId = $personneId ?: personne_creer($champs);
    $cols = array_values(array_intersect(CONTACT_CHAMPS, array_keys($champs)));
    $sql = 'INSERT INTO personne_structures (personne_id, structure_id' . ($cols ? ', ' . implode(',', $cols) : '')
        . ') VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')';
    db()->prepare($sql)->execute(array_merge([$personneId, $structureId], array_map(fn ($c) => $champs[$c], $cols)));
    return (int) db()->lastInsertId();
}

// ⚠️ Modifier un contact modifie LA PERSONNE : son nom et son adresse
// électronique changent partout où elle apparaît, pas seulement ici. C'est
// voulu — c'est la même personne — et c'est le seul comportement qui ne mente
// pas, mais il mérite d'être su.
function contact_enregistrer(int $contactId, array $champs): void
{
    $contact = contact_charger($contactId);
    if (!$contact) {
        return;
    }
    personne_enregistrer((int) $contact['personne_id'], $champs);
    $cols = array_values(array_intersect(CONTACT_CHAMPS, array_keys($champs)));
    if ($cols) {
        db()->prepare('UPDATE personne_structures SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . ' WHERE id = ?')
            ->execute(array_merge(array_map(fn ($c) => $champs[$c], $cols), [$contactId]));
    }
}

// Retire un contact d'une structure. La PERSONNE ne part que si plus rien ne
// la retient — aucun autre rattachement, aucun compte, aucun employé. Sans
// cette règle, l'annuaire accumulerait des fiches que plus aucun écran ne
// montre ; avec une règle plus brutale, retirer quelqu'un d'une salle
// l'effacerait de l'autre.
function contact_supprimer(int $contactId): void
{
    $contact = contact_charger($contactId);
    if (!$contact) {
        return;
    }
    $pid = (int) $contact['personne_id'];
    db()->prepare('DELETE FROM personne_structures WHERE id = ?')->execute([$contactId]);
    if (!personne_est_retenue($pid)) {
        db()->prepare('DELETE FROM personnes WHERE id = ?')->execute([$pid]);
    }
}

// Quelque chose retient-il encore cette personne ? Les envois de mailing ne
// comptent PAS : c'est un journal, il garde l'adresse du destinataire en
// clair et survit très bien à la disparition de la fiche.
function personne_est_retenue(int $personneId): bool
{
    foreach (['personne_structures', 'employes', 'utilisateurs'] as $table) {
        $stmt = db()->prepare("SELECT 1 FROM $table WHERE personne_id = ? LIMIT 1");
        $stmt->execute([$personneId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

// Le nom d'un COMPTE de connexion. Rattaché à une personne, c'est elle qui le
// porte : une seule source, et modifier le nom dans l'annuaire le change
// partout — c'est tout l'objet du carnet. Sinon, les champs propres du compte,
// et à défaut son adresse (un compte créé sans identité n'est pas une ligne
// vide).
//
// ⚠️ Un compte rattaché n'a donc plus de prénom/nom à lui : l'enregistrement
// les vide (route_utilisateur_enregistrer()). Sans cela, les deux identités
// pouvaient diverger en silence, ce que la refonte des personnes devait
// justement finir.
function utilisateur_nom(array $u): string
{
    $pid = (int) ($u['personne_id'] ?? 0);
    if ($pid && ($pe = personne_charger($pid))) {
        return personne_nom($pe);
    }
    $nom = trim(trim((string) ($u['prenom'] ?? '')) . ' ' . trim((string) ($u['nom'] ?? '')));
    return $nom !== '' ? $nom : trim((string) ($u['email'] ?? ''));
}

// L'annuaire sous la forme qu'attend un champ cherchable (lassoInitCatSearch) :
// [id, libellé]. Trié par nom, et plafonné — à 2900 fiches, les écrire toutes
// dans la page coûte deux cents kilo-octets par champ ; au-delà du plafond, la
// recherche de l'écran reste le bon chemin.
function personnes_options(int $max = 3000): array
{
    $sql = 'SELECT id, prenom, nom, email FROM personnes
            ORDER BY nom COLLATE NOCASE, prenom COLLATE NOCASE LIMIT ?';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $max, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt as $pe) {
        $out[] = ['id' => (int) $pe['id'], 'libelle' => personne_nom($pe)];
    }
    return $out;
}

// Ce qui RETIENT une personne, et par quoi l'annuaire se filtre. Les quatre
// ne s'excluent pas : une employée est souvent aussi contact d'une structure,
// et elle a parfois un compte. En cocher deux revient donc à montrer les deux
// (condition unie en OR, comme les états d'une pièce) — sauf « aucun », qui
// est par construction le complément des trois autres.
const PERSONNE_RATTACHEMENTS = [
    'structure' => 'Contact de structure',
    'employe'   => 'Employée',
    'compte'    => 'Compte de connexion',
    'aucun'     => 'Rattachée à rien',
];

// La condition SQL d'un rattachement, portant sur « p. » (les personnes).
// ⚠️ Écrite en EXISTS et non sur les alias nb_structures/est_employe du SELECT :
// SQLite n'accepte pas un alias de projection dans un WHERE, et la requête de
// COMPTAGE, qui partage ce filtre, ne les calcule même pas.
function personne_rattachement_sql(string $cle): string
{
    $struct  = 'EXISTS (SELECT 1 FROM personne_structures ps WHERE ps.personne_id = p.id)';
    $employe = 'EXISTS (SELECT 1 FROM employes e WHERE e.personne_id = p.id)';
    $compte  = 'EXISTS (SELECT 1 FROM utilisateurs u WHERE u.personne_id = p.id)';
    return match ($cle) {
        'structure' => $struct,
        'employe'   => $employe,
        'compte'    => $compte,
        'aucun'     => "NOT $struct AND NOT $employe AND NOT $compte",
        default     => '1=1',
    };
}

// Les doublons probables : même e-mail, ou même prénom + nom. Ne fusionne
// rien — avec ~2900 personnes, des boîtes partagées (info@…) et des
// homonymes, un regroupement automatique fabriquerait des fusions fausses et
// muettes, et une fusion fausse coûte bien plus cher qu'un doublon. Cette
// fonction ne fait que les MONTRER, une par une, à valider.
function personnes_doublons(int $max = 100): array
{
    // ⚠️ La clé « nom » est trimée EN ENTIER : « prénom || ' ' || nom » laisse
    // une espace finale quand le nom est vide (les contacts importés sous un
    // seul mot, « L'équipe »), et cette espace se retrouvait dans le titre du
    // groupe comme dans la clé qui sert à l'écarter.
    $sql = "SELECT LOWER(TRIM(email)) AS cle, 'email' AS motif, COUNT(*) AS n
              FROM personnes WHERE TRIM(email) <> '' GROUP BY cle HAVING n > 1
            UNION ALL
            SELECT LOWER(TRIM(TRIM(prenom) || ' ' || TRIM(nom))) AS cle, 'nom' AS motif, COUNT(*) AS n
              FROM personnes WHERE TRIM(prenom || nom) <> '' GROUP BY cle HAVING n > 1
            ORDER BY n DESC, cle LIMIT ?";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $max, PDO::PARAM_INT);
    $stmt->execute();
    $ecartees = personnes_doublons_ignores_cles();
    // nb_structures voyage AVEC la fiche : l'écran de fusion le montre pour
    // chaque candidate — c'est ce qui dit laquelle porte le plus de liens, donc
    // laquelle garder. Une requête corrélée plutôt qu'une par ligne dans la
    // vue, qui est ce qu'il y avait avant.
    $cols = 'p.*, (SELECT COUNT(*) FROM personne_structures ps WHERE ps.personne_id = p.id) AS nb_structures';
    $groupes = [];
    foreach ($stmt as $g) {
        if (isset($ecartees[personne_doublon_cle((string) $g['motif'], (string) $g['cle'])])) {
            continue;   // suggestion écartée : « ce nom-là n'est pas un doublon »
        }
        $q = $g['motif'] === 'email'
            ? db()->prepare("SELECT $cols FROM personnes p WHERE LOWER(TRIM(p.email)) = ? ORDER BY p.id")
            : db()->prepare("SELECT $cols FROM personnes p WHERE LOWER(TRIM(TRIM(p.prenom) || ' ' || TRIM(p.nom))) = ? ORDER BY p.id");
        $q->execute([$g['cle']]);
        $groupes[] = ['cle' => (string) $g['cle'], 'motif' => (string) $g['motif'], 'personnes' => $q->fetchAll()];
    }
    return $groupes;
}

// Les suggestions de doublons qu'on a écartées (migration_103). La clé réunit
// le motif et la valeur en une chaîne : c'est elle qui voyage dans les cases à
// cocher du formulaire, et le séparateur ne peut pas apparaître dans un motif.
function personne_doublon_cle(string $motif, string $cle): string
{
    return $motif . '|' . $cle;
}

// [clé => [motif, cle]] — le tableau sert de table de présence dans
// personnes_doublons(), et de liste à l'écran des suggestions écartées.
function personnes_doublons_ignores_cles(): array
{
    $out = [];
    foreach (db()->query('SELECT motif, cle FROM personne_doublons_ignores ORDER BY cle') as $r) {
        $out[personne_doublon_cle((string) $r['motif'], (string) $r['cle'])] =
            ['motif' => (string) $r['motif'], 'cle' => (string) $r['cle']];
    }
    return $out;
}

// Écarte des suggestions. $cles : les chaînes « motif|valeur » du formulaire.
// Rend le nombre de suggestions effectivement écartées.
function personnes_doublons_ignorer(array $cles): int
{
    // INSERT OR IGNORE : réécarter ce qui l'est déjà n'est pas une erreur et ne
    // doit pas rafraîchir la date — même règle que pour les structures.
    $st = db()->prepare('INSERT OR IGNORE INTO personne_doublons_ignores (motif, cle) VALUES (?, ?)');
    $n = 0;
    foreach ($cles as $c) {
        [$motif, $valeur] = array_pad(explode('|', (string) $c, 2), 2, '');
        if ($valeur === '' || !in_array($motif, ['email', 'nom'], true)) {
            continue;
        }
        $st->execute([$motif, $valeur]);
        $n += $st->rowCount();
    }
    return $n;
}

// Remet des suggestions écartées dans la détection.
function personnes_doublons_reprendre(array $cles): int
{
    $st = db()->prepare('DELETE FROM personne_doublons_ignores WHERE motif = ? AND cle = ?');
    $n = 0;
    foreach ($cles as $c) {
        [$motif, $valeur] = array_pad(explode('|', (string) $c, 2), 2, '');
        if ($valeur === '') {
            continue;
        }
        $st->execute([$motif, $valeur]);
        $n += $st->rowCount();
    }
    return $n;
}

// Fusionne $sources dans $cible : tout ce qui pointait une source pointe la
// cible, puis les sources disparaissent.
//
// ⚠️ La cible GARDE ses valeurs ; une source ne comble que les champs vides.
// L'inverse — la dernière valeur gagne — ferait perdre une adresse renseignée
// au profit d'une fiche vide, et on ne saurait pas que c'est arrivé.
//
// ⚠️ desinscrit se propage : si l'une des fiches dit « ne plus m'écrire »,
// c'est la personne entière qui l'a demandé. Fusionner ne doit jamais rendre
// quelqu'un joignable à nouveau.
function personnes_fusionner(int $cibleId, array $sources): int
{
    $sources = array_values(array_filter(array_map('intval', $sources), fn ($id) => $id > 0 && $id !== $cibleId));
    $cible = personne_charger($cibleId);
    if (!$cible || !$sources) {
        return 0;
    }
    $in = sql_in($sources);
    db()->beginTransaction();
    try {
        $complet = $cible;
        foreach (db()->query('SELECT * FROM personnes WHERE id IN (' . implode(',', $sources) . ')') as $src) {
            foreach (PERSONNE_CHAMPS as $c) {
                if (trim((string) ($complet[$c] ?? '')) === '') {
                    $complet[$c] = $src[$c] ?? '';
                }
            }
            if ((int) ($src['desinscrit'] ?? 0) === 1) {
                $complet['desinscrit'] = 1;
            }
        }
        personne_enregistrer($cibleId, $complet);
        db()->prepare('UPDATE personnes SET desinscrit = ? WHERE id = ?')
            ->execute([(int) ($complet['desinscrit'] ?? 0), $cibleId]);

        foreach (['personne_structures', 'mailing_file_attente', 'mailing_envois', 'historique', 'employes', 'utilisateurs'] as $table) {
            db()->prepare("UPDATE $table SET personne_id = ? WHERE personne_id IN ($in)")
                ->execute(array_merge([$cibleId], $sources));
        }
        // Deux rôles identiques dans la même structure n'en font qu'un : la
        // fusion ne doit pas laisser la personne deux fois dans le même
        // carnet. On garde le plus ancien, qui porte l'historique.
        db()->exec(
            'DELETE FROM personne_structures WHERE id NOT IN
             (SELECT MIN(id) FROM personne_structures GROUP BY personne_id, structure_id)'
        );
        db()->prepare('DELETE FROM personnes WHERE id IN (' . implode(',', $sources) . ')')->execute();
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return count($sources);
}
