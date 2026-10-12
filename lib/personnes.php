<?php
// Les personnes (SPEC_PERSONNES.md).
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

// Les doublons probables : même e-mail, ou même prénom + nom. Ne fusionne
// rien — avec ~2900 personnes, des boîtes partagées (info@…) et des
// homonymes, un regroupement automatique fabriquerait des fusions fausses et
// muettes, et une fusion fausse coûte bien plus cher qu'un doublon. Cette
// fonction ne fait que les MONTRER, une par une, à valider.
function personnes_doublons(int $max = 100): array
{
    $sql = "SELECT LOWER(TRIM(email)) AS cle, 'email' AS motif, COUNT(*) AS n
              FROM personnes WHERE TRIM(email) <> '' GROUP BY cle HAVING n > 1
            UNION ALL
            SELECT LOWER(TRIM(prenom) || ' ' || TRIM(nom)) AS cle, 'nom' AS motif, COUNT(*) AS n
              FROM personnes WHERE TRIM(prenom || nom) <> '' GROUP BY cle HAVING n > 1
            ORDER BY n DESC, cle LIMIT ?";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $max, PDO::PARAM_INT);
    $stmt->execute();
    $groupes = [];
    foreach ($stmt as $g) {
        $q = $g['motif'] === 'email'
            ? db()->prepare('SELECT * FROM personnes WHERE LOWER(TRIM(email)) = ? ORDER BY id')
            : db()->prepare("SELECT * FROM personnes WHERE LOWER(TRIM(prenom) || ' ' || TRIM(nom)) = ? ORDER BY id");
        $q->execute([$g['cle']]);
        $groupes[] = ['cle' => (string) $g['cle'], 'motif' => (string) $g['motif'], 'personnes' => $q->fetchAll()];
    }
    return $groupes;
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
