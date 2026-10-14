<?php
// Routes de l'annuaire des personnes (SPEC_PERSONNES.md).
//
// Écran de PARAMÈTRES, sous « coeur » : une personne traverse le booking, les
// salaires et les comptes, aucun module ne peut en être propriétaire. C'est
// aussi d'ici qu'on fusionne les doublons — le dédoublonnage de la migration a
// été volontairement laissé à faire à la main.

function route_personnes(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $vue = valeur_autorisee((string) ($_GET['vue'] ?? 'liste'), ['liste', 'doublons'], 'liste');

    $personnes = [];
    $doublons  = [];
    $total = (int) db()->query('SELECT COUNT(*) FROM personnes')->fetchColumn();
    if ($vue === 'doublons') {
        $doublons = personnes_doublons();
    } else {
        // Toujours filtrée côté serveur : l'annuaire dépasse largement le seuil
        // de la pagination client, et charger 2900 fiches pour en montrer 50
        // serait absurde.
        $where = '';
        $params = [];
        if ($recherche !== '') {
            [$sql, $params] = recherche_sql(['p.prenom', 'p.nom', 'p.email', 'p.telephone']);
            $where = ' WHERE 1=1' . $sql;
        }
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
               FROM personnes p' . $where . ' ORDER BY p.nom COLLATE NOCASE, p.prenom COLLATE NOCASE' . $limitSql
        );
        $stmt->execute(array_merge($params, $limitParams));
        $personnes = $stmt->fetchAll();
    }

    render('personnes', [
        'personnes' => $personnes,
        'doublons'  => $doublons,
        'vue'       => $vue,
        'recherche' => $recherche,
        'total'     => $total,
        'nbDoublons' => $vue === 'doublons' ? count($doublons) : count(personnes_doublons(20)),
        'ok'        => $_GET['ok'] ?? null,
        'pgRoute'   => 'personnes',
        'pgParams'  => array_filter(['q' => $recherche]),
        'pgPage'    => $pgPage ?? 1,
        'pgTaille'  => $pgTaille ?? pagination_taille('personnes_taille'),
        'pgTotal'   => $pgTotal ?? 0,
    ], 'Personnes');
}

function route_personne(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $p  = $id ? personne_charger($id) : null;
    if (!$p) {
        redirect('personnes');
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

    render('personne', [
        'p'           => $p,
        'structures'  => personne_structures($id),
        'employes'    => $stmtE->fetchAll(),
        'utilisateurs' => $stmtU->fetchAll(),
        'pieces'      => $pieces,
        'ok'          => isset($_GET['ok']),
    ], personne_nom($p));
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
    redirect('personnes', ['vue' => 'doublons'] + ($n ? ['ok' => $n] : []));
}
