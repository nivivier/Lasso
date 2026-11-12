<?php
// Routes de l'annuaire des personnes (docs/SPEC_PERSONNES.md).
//
// Écran du CARNET D'ADRESSES : l'annuaire, et rien d'autre.
//
// Le dédoublonnage, lui, vit dans ?p=incoherences, avec les autres outils de reprise de
// données (doublons de structures et de contacts, lieux à rattacher…). Ce
// n'est pas un geste du travail courant : on le fait une fois après une
// importation, et sa place n'était pas dans la barre d'actions d'un écran
// qu'on ouvre tous les jours.

function route_personnes(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    // Le filtre de la colonne « Rattachements », mémorisé en session comme les
    // autres filtres de colonne (filtre_coche()).
    $rattachement = filtre_coche('rattachement', 'personnes_rattachement', array_keys(PERSONNE_RATTACHEMENTS));

    $total = (int) db()->query('SELECT COUNT(*) FROM personnes')->fetchColumn();
    // Toujours filtrée côté serveur : l'annuaire dépasse largement le seuil
    // de la pagination client, et charger 2900 fiches pour en montrer 50
    // serait absurde.
    $where = ' WHERE 1=1';
    $params = [];
    if ($recherche !== '') {
        [$sql, $params] = recherche_sql(['p.prenom', 'p.nom', 'p.email', 'p.telephone']);
        $where .= $sql;
    }
    if ($rattachement) {
        // Unies en OR : cocher « employée » et « compte » montre les deux,
        // comme partout ailleurs dans l'application.
        $where .= ' AND (' . implode(' OR ', array_map(
            fn (string $r) => '(' . personne_rattachement_sql($r) . ')',
            $rattachement
        )) . ')';
    }
    // Le tri des colonnes, mémorisé en session comme partout ailleurs
    // (tri_colonne()). « rattachements » trie sur le NOMBRE de structures : la
    // colonne en montre les noms, mais c'est « qui en a le plus » qu'on y
    // cherche, pas l'ordre alphabétique de la première.
    $tri = tri_colonne('personnes', [
        'nom'        => ['p.nom COLLATE NOCASE', 'p.prenom COLLATE NOCASE'],
        'email'      => 'p.email COLLATE NOCASE',
        'telephone'  => 'p.telephone COLLATE NOCASE',
        'rattachements' => 'nb_structures',
    ]);
    $orderBy = $tri['sql'] !== ''
        ? $tri['sql'] . ', p.id'
        : ' ORDER BY p.nom COLLATE NOCASE, p.prenom COLLATE NOCASE';

    $pgTaille = pagination_taille('personnes_taille');
    $stmtTot = db()->prepare('SELECT COUNT(*) FROM personnes p' . $where);
    $stmtTot->execute($params);
    $pgTotal = (int) $stmtTot->fetchColumn();
    $pgPage  = pagination_page();
    [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);
    // Le compte des rattachements en une seule requête corrélée plutôt
    // qu'une par ligne : à 2900 personnes, la seconde forme se sent.
    $stmt = db()->prepare(
        'SELECT p.*,
                (SELECT COUNT(*) FROM personne_structures ps WHERE ps.personne_id = p.id) AS nb_structures,
                (SELECT COUNT(*) FROM employes e WHERE e.personne_id = p.id) AS est_employe,
                (SELECT COUNT(*) FROM utilisateurs u WHERE u.personne_id = p.id) AS est_utilisateur
           FROM personnes p' . $where . $orderBy . $limitSql
    );
    $stmt->execute(array_merge($params, $limitParams));
    $personnes = $stmt->fetchAll();

    // Les structures de chaque personne de la PAGE, nommées : la colonne
    // disait « 1 structure », ce qui demandait d'ouvrir la fiche pour savoir
    // laquelle. Une seule requête pour les cinquante lignes — une par ligne,
    // c'est ce qu'on vient de retirer de l'écran de fusion.
    $structuresParPersonne = [];
    if ($personnes) {
        $ids = array_column($personnes, 'id');
        $stmtS = db()->prepare(
            'SELECT ps.personne_id, s.id, s.nom
               FROM personne_structures ps JOIN structures s ON s.id = ps.structure_id
              WHERE ps.personne_id IN (' . sql_in($ids) . ')
              ORDER BY s.nom COLLATE NOCASE'
        );
        $stmtS->execute(array_map('intval', $ids));
        foreach ($stmtS as $r) {
            $structuresParPersonne[(int) $r['personne_id']][] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom']];
        }
    }

    // Vue deux colonnes : la personne choisie (?sel=), à défaut la première de
    // la liste. Mêmes données que la page pleine — personne_donnees_vue().
    // ⚠️ 'structures' : la fiche entend les structures de CETTE personne, et la
    // liste n'a pas de clé de ce nom — sans quoi l'une écraserait l'autre dans
    // le render() ci-dessous.
    $vue = vue_liste('personnes_vue');
    $duo = duo_selection($vue, $personnes, 'personne_donnees_vue');

    render('personnes', array_merge($duo, [
        'vue'       => $vue,
        'duoSel'    => $duo['duoSel'],
        'personnes' => $personnes,
        'structuresParPersonne' => $structuresParPersonne,
        'recherche' => $recherche,
        'rattachement' => $rattachement,
        'total'     => $total,
        'tri'       => $tri,
        'pgRoute'   => 'personnes',
        'pgParams'  => array_filter(['q' => $recherche, 'rattachement' => $rattachement]),
        'pgPage'    => $pgPage,
        'pgTaille'  => $pgTaille,
        'pgTotal'   => $pgTotal ?? 0,
    ]), 'Personnes');
}

// Les données de la fiche d'une personne : la page pleine (route_personne())
// comme le volet droit de la vue deux colonnes (route_personnes(), ?vue=duo)
// les lisent ici — une seule fiche à tenir. Rend null quand l'identifiant ne
// désigne personne. Même procédé que structure/facture/employé.
function personne_donnees_vue(int $id): ?array
{
    $p = $id ? personne_charger($id) : null;
    if (!$p) {
        return null;
    }
    // Ce qui la retient : ses structures, son emploi, son compte. C'est tout
    // l'intérêt du modèle — ces trois-là étaient sans rapport.
    $stmtE = db()->prepare('SELECT id, numero_avs, actif FROM employes WHERE personne_id = ?');
    $stmtE->execute([$id]);
    $stmtU = db()->prepare('SELECT id, email FROM utilisateurs WHERE personne_id = ?');
    $stmtU->execute([$id]);
    // Les pièces qui la DÉSIGNENT : la quittance qu'on lui a remise, la note de
    // frais qu'on doit lui rembourser. C'est la seule trace, sur sa fiche, de
    // ce qui s'est passé en argent entre elle et l'association — et la seule
    // façon de retrouver une quittance quand on en a le nom mais pas le numéro.
    // module_accessible() et non module_actif() : l'écran des personnes est au
    // « coeur », mais les pièces restent derrière leur propre droit de lecture.
    $pieces = [];
    if (module_accessible('pieces')) {
        $stmtP = db()->prepare(
            "SELECT * FROM pieces WHERE personne_id = ?
             ORDER BY COALESCE(NULLIF(date_piece,''), cree_le) DESC, id DESC"
        );
        $stmtP->execute([$id]);
        $pieces = $stmtP->fetchAll();
    }

    return [
        'p'           => $p,
        'structures'  => personne_structures($id),
        'employes'    => $stmtE->fetchAll(),
        'utilisateurs' => $stmtU->fetchAll(),
        'pieces'      => $pieces,
        // Ce qu'on peut encore lier — rendu seulement pour qui peut écrire.
        'structuresDispo' => peut_ecrire('carnet') ? personne_structures_dispo($id) : [],
        'ok'          => isset($_GET['ok']),
    ];
}

function route_personne(): void
{
    require_login();
    // Sans identifiant : la fiche en CRÉATION, comme ?p=structure — le bouton
    // « Nouvelle personne » de la liste y mène. Une personne vierge, sans
    // rattachement : on la relie ensuite depuis sa fiche.
    if ((int) ($_GET['id'] ?? 0) === 0) {
        render('personne', personne_vierge_vue(), 'Nouvelle personne');
        return;
    }
    $donnees = personne_donnees_vue((int) ($_GET['id'] ?? 0));
    if ($donnees === null) {
        redirect('personnes');
    }
    render('personne', $donnees, personne_nom($donnees['p']));
}

// Les structures auxquelles cette personne n'est pas encore rattachée, pour
// le champ « Lier » de sa fiche.
function personne_structures_dispo(int $personneId): array
{
    $stmt = db()->prepare('SELECT id, nom FROM structures
        WHERE id NOT IN (SELECT structure_id FROM personne_structures WHERE personne_id = ?)
        ORDER BY nom COLLATE NOCASE');
    $stmt->execute([$personneId]);
    return $stmt->fetchAll();
}

// Les données d'une fiche en création : les mêmes clés que
// personne_donnees_vue(), toutes vides — la vue n'a qu'un seul jeu de noms à lire.
function personne_vierge_vue(array $saisie = [], ?string $err = null): array
{
    $p = ['id' => 0, 'desinscrit' => 0, 'notes' => (string) ($saisie['notes'] ?? '')];
    foreach (PERSONNE_CHAMPS as $c) {
        $p[$c] = (string) ($saisie[$c] ?? '');
    }
    return ['p' => $p, 'structures' => [], 'employes' => [], 'utilisateurs' => [], 'pieces' => [],
            'ok' => false, 'err' => $err];
}

function route_personne_enregistrer(): void
{
    require_login();
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && !personne_charger($id)) {
        redirect('personnes');
    }
    $champs = [];
    foreach (PERSONNE_CHAMPS as $c) {
        $champs[$c] = trim((string) ($_POST[$c] ?? ''));
    }
    if (!$id) {
        // Une personne se reconnaît à son nom OU à son adresse : sans aucun
        // des trois, il n'y aurait rien à afficher dans la liste.
        if ($champs['prenom'] === '' && $champs['nom'] === '' && $champs['email'] === '') {
            render('personne', personne_vierge_vue($_POST, 'Indiquez au moins un nom ou une adresse électronique.'), 'Nouvelle personne');
            return;
        }
        $id = personne_creer($champs);
    } else {
        personne_enregistrer($id, $champs);
    }
    // La désinscription est une propriété de la personne, et elle se retire
    // ici — c'est le seul endroit d'où l'on peut rendre quelqu'un joignable.
    db()->prepare('UPDATE personnes SET desinscrit = ?, notes = ? WHERE id = ?')
        ->execute([isset($_POST['desinscrit']) ? 1 : 0, trim((string) ($_POST['notes'] ?? '')), $id]);
    redirect('personne', ['id' => $id, 'ok' => 1]);
}

function route_personnes_fusionner(): void
{
    require_login();
    check_csrf();
    $cible = (int) ($_POST['cible'] ?? 0);
    $sources = array_map('intval', (array) ($_POST['sources'] ?? []));
    $n = $cible ? personnes_fusionner($cible, $sources) : 0;
    // Retour à l'outil d'où l'on vient, et à SA section : la page de reprise
    // en compte cinq, et revenir en haut obligerait à redescendre.
    redirect('incoherences', ['section' => 'doublons-personnes'] + ($n ? ['ok' => 'personnes', 'np' => $n] : []));
}


// Rattacher une personne à une structure, depuis SA fiche (?p=personne). Le
// lien est celui de la fiche structure — personne_structures, avec un rôle —,
// vu de l'autre bout. Un même lien ne se pose pas deux fois : la personne
// déjà rattachée garde le sien, rôle compris.
//
// Ajout en arrière-plan (docs/UI.md § 5) : la route renvoie la ligne rendue
// par le MÊME partiel que la fiche. Sans script, retour à la fiche.
function route_personne_structure_lier(): void
{
    require_login();
    check_csrf();
    $personneId  = (int) ($_POST['personne_id'] ?? 0);
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $existe = db()->prepare('SELECT 1 FROM structures WHERE id = ?');
    $existe->execute([$structureId]);
    if (!personne_charger($personneId) || !$existe->fetchColumn()) {
        redirect('personnes');
    }
    $deja = db()->prepare('SELECT id FROM personne_structures WHERE personne_id = ? AND structure_id = ?');
    $deja->execute([$personneId, $structureId]);
    $lienId = (int) $deja->fetchColumn();
    if (!$lienId) {
        $lienId = contact_creer($structureId, ['role' => trim((string) ($_POST['role'] ?? ''))], $personneId);
        journaliser('structure', $structureId, 'edition',
            'Contact ajouté : ' . personne_nom(personne_charger($personneId)), null, $personneId);
    }
    if (($_POST['retour'] ?? '') === 'json') {
        $ligne = array_values(array_filter(personne_structures($personneId), fn ($l) => (int) $l['id'] === $lienId));
        reponse_ajout_json(null, rendre_fragment('_personne_structure_ligne', [
            'st' => $ligne[0] ?? [], 'pid' => $personneId, 'peutLier' => true,
        ]));
        return;
    }
    redirect('personne', ['id' => $personneId]);
}

// Défaire ce lien. ⚠️ PAS contact_supprimer() : celle-ci efface aussi la
// personne quand plus rien ne la retient — juste sur la fiche d'une structure,
// où un contact orphelin n'a plus d'écran ; absurde ici, où l'on est SUR sa
// fiche. Seul le lien s'en va.
function route_personne_structure_delier(): void
{
    require_login();
    check_csrf();
    $personneId = (int) ($_POST['personne_id'] ?? 0);
    $lienId     = (int) ($_POST['lien_id'] ?? 0);
    $lien = db()->prepare('SELECT structure_id FROM personne_structures WHERE id = ? AND personne_id = ?');
    $lien->execute([$lienId, $personneId]);
    $structureId = (int) $lien->fetchColumn();
    if ($structureId) {
        db()->prepare('DELETE FROM personne_structures WHERE id = ?')->execute([$lienId]);
        $p = personne_charger($personneId);
        journaliser('structure', $structureId, 'edition',
            'Contact supprimé : ' . ($p ? personne_nom($p) : 'contact'), null, $personneId);
    }
    redirect('personne', ['id' => $personneId]);
}
