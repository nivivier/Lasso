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
        'ok'          => isset($_GET['ok']),
    ];
}

function route_personne(): void
{
    require_login();
    $donnees = personne_donnees_vue((int) ($_GET['id'] ?? 0));
    if ($donnees === null) {
        redirect('personnes');
    }
    render('personne', $donnees, personne_nom($donnees['p']));
}

function route_personne_enregistrer(): void
{
    require_login();
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id || !personne_charger($id)) {
        redirect('personnes');
    }
    $champs = [];
    foreach (PERSONNE_CHAMPS as $c) {
        $champs[$c] = trim((string) ($_POST[$c] ?? ''));
    }
    personne_enregistrer($id, $champs);
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
