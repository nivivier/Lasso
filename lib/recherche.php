<?php
// Recherche unifiée : une seule saisie qui traverse employés, structures,
// factures, événements et spectacles, au lieu d'imposer le parcours
// « choisir le module → ouvrir la liste → filtrer ».
//
// SÉCURITÉ — le point à ne jamais relâcher ici. C'est la seule fonctionnalité
// qui interroge plusieurs modules dans la même requête, donc la seule où une
// erreur de filtrage exposerait les données d'un module auquel l'utilisateur
// n'a pas accès. Chaque source vérifie DEUX conditions avant d'exécuter la
// moindre requête :
//   - module_actif()  : le module est activé pour cette installation ;
//   - peut_lire()     : CET utilisateur a le droit de lecture dessus.
// La route n'est donc rattachée à aucun module (accessible à tout compte
// connecté, comme le tableau de bord) : le tri se fait ici, source par source.

declare(strict_types=1);

// Résultats affichés par catégorie. Volontairement court : la recherche sert à
// ATTEINDRE une fiche connue, pas à explorer. Au-delà, un lien renvoie vers la
// liste du module, qui a ses propres filtres.
const RECHERCHE_LIMITE = 8;

// En dessous de deux caractères, toute saisie ramènerait la moitié de la base.
const RECHERCHE_MIN = 2;

// Une source = une catégorie de résultats. Déclarées en tableau plutôt qu'en
// suite de « if » : ajouter une entité ne doit demander qu'une entrée ici, et
// le contrôle de droits reste appliqué uniformément à toutes.
//
// 'modules' : lecture sur AU MOINS UN d'entre eux (structures est partagée
//             entre Facturation et Booking, comme dans index.php).
// 'sql'     : doit projeter exactement ces colonnes —
//               id, titre, sous_titre, tri, texte
//             où « texte » concatène tout ce sur quoi porte la recherche. Cette
//             projection est ce qui permet à la requête extérieure de filtrer
//             et de compter sans rien savoir des tables sous-jacentes (les
//             alias de colonnes internes n'y seraient plus accessibles).
function recherche_sources(): array
{
    return [
        'employes' => [
            'label'   => 'Employés',
            'icone'   => 'users',
            'modules' => ['salaires'],
            'route'   => 'employe_voir',
            'liste'   => 'employes',
            'ordre'   => 'ORDER BY tri DESC, titre',
            'sql'     => "SELECT e.id,
                                 trim(e.prenom || ' ' || e.nom) AS titre,
                                 CASE WHEN trim(coalesce(e.email,'')) <> '' THEN e.email
                                      ELSE trim(coalesce(e.npa_localite,'')) END AS sous_titre,
                                 e.actif AS tri,
                                 trim(coalesce(e.prenom,'') || ' ' || coalesce(e.nom,'') || ' ' ||
                                      coalesce(e.email,'') || ' ' || coalesce(e.npa_localite,'')) AS texte
                          FROM employes e",
        ],
        'structures' => [
            'label'   => 'Structures',
            'icone'   => 'house',
            'modules' => ['facturation', 'booking'],
            'route'   => 'structure',
            'liste'   => 'structures',
            'ordre'   => 'ORDER BY titre',
            'sql'     => "SELECT s.id, s.nom AS titre,
                                 trim(coalesce(s.adresse_localite,'') ||
                                      CASE WHEN trim(coalesce(s.adresse_localite,'')) <> ''
                                            AND trim(coalesce(s.categorie,'')) <> ''
                                       THEN ' · ' ELSE '' END ||
                                      coalesce(s.categorie,'')) AS sous_titre,
                                 0 AS tri,
                                 trim(coalesce(s.nom,'') || ' ' || coalesce(s.adresse_localite,'') || ' ' ||
                                      coalesce(s.email,'') || ' ' || coalesce(s.personne_contact,'') || ' ' ||
                                      coalesce(s.telephone,'') || ' ' || coalesce(s.categorie,'')) AS texte
                          FROM structures s",
        ],
        // Contacts d'une structure. Deux particularités :
        //
        // 1. « booking » seul, alors que les structures elles-mêmes sont
        //    partagées avec « facturation » — le bloc qui affiche les contacts
        //    sur la fiche structure est gardé par booking (voir $avecAside dans
        //    views/structure_form.php). Élargir à facturation exposerait ici des
        //    données que la fiche elle-même refuse de montrer.
        // 2. L'id projeté est celui de la STRUCTURE, pas du contact : un contact
        //    n'a pas de page à lui, il vit dans la fiche de sa structure — donc
        //    le résultat doit y mener. Plusieurs contacts d'une même structure
        //    peuvent ainsi remonter en plusieurs lignes vers la même page, ce
        //    qui est voulu : chaque ligne montre QUEL contact a matché.
        'contacts' => [
            'label'   => 'Contacts',
            'icone'   => 'user',
            'modules' => ['booking'],
            'route'   => 'structure',
            'liste'   => 'structures',
            'ordre'   => 'ORDER BY tri DESC, titre',
            'sql'     => "SELECT c.structure_id AS id,
                                 trim(c.prenom || ' ' || c.nom) AS titre,
                                 trim(coalesce(st.nom,'') ||
                                      CASE WHEN trim(coalesce(c.role,'')) <> ''
                                        THEN ' · ' || c.role ELSE '' END) AS sous_titre,
                                 c.actif AS tri,
                                 trim(coalesce(c.prenom,'') || ' ' || coalesce(c.nom,'') || ' ' ||
                                      coalesce(c.email,'') || ' ' || coalesce(c.telephone,'') || ' ' ||
                                      coalesce(c.role,'')) AS texte
                          FROM structure_contacts c
                          LEFT JOIN structures st ON st.id = c.structure_id",
        ],
        'factures' => [
            'label'   => 'Factures',
            'icone'   => 'receipt-swiss-franc',
            'modules' => ['facturation'],
            'route'   => 'facture',
            'liste'   => 'facturation_liste',
            'ordre'   => 'ORDER BY tri DESC',
            'sql'     => "SELECT f.id,
                                 CASE WHEN trim(coalesce(f.numero,'')) <> '' THEN f.numero
                                      ELSE 'Brouillon' END AS titre,
                                 trim(coalesce(st.nom,'') ||
                                      CASE WHEN trim(coalesce(st.nom,'')) <> '' THEN ' · ' ELSE '' END ||
                                      coalesce(f.statut,'')) AS sous_titre,
                                 coalesce(f.date_emission,'') AS tri,
                                 trim(coalesce(f.numero,'') || ' ' || coalesce(f.communication,'') || ' ' ||
                                      coalesce(st.nom,'')) AS texte
                          FROM factures f LEFT JOIN structures st ON st.id = f.structure_id",
        ],
        'evenements' => [
            'label'   => 'Événements',
            'icone'   => 'calendar',
            'modules' => ['evenements'],
            'route'   => 'evenement',
            'liste'   => 'evenements_liste',
            'ordre'   => 'ORDER BY tri DESC',
            // Même composition de titre que l'export iCal : « Artiste (Spectacle) »
            // quand le spectacle a un parent.
            'sql'     => "SELECT ev.id,
                                 trim(coalesce(spp.nom || ' (' || sp.nom || ')', sp.nom, 'Événement')) AS titre,
                                 trim(ev.date ||
                                      CASE WHEN trim(coalesce(ev.ville,'')) <> '' THEN ' · ' || ev.ville ELSE '' END ||
                                      CASE WHEN trim(coalesce(ev.salle,'')) <> '' THEN ', ' || ev.salle ELSE '' END) AS sous_titre,
                                 ev.date AS tri,
                                 trim(coalesce(ev.ville,'') || ' ' || coalesce(ev.salle,'') || ' ' ||
                                      coalesce(ev.festival,'') || ' ' || coalesce(sp.nom,'') || ' ' ||
                                      coalesce(spp.nom,'') || ' ' || coalesce(ev.date,'')) AS texte
                          FROM evenements ev
                          LEFT JOIN spectacles sp  ON sp.id  = ev.spectacle_id
                          LEFT JOIN spectacles spp ON spp.id = sp.parent_id",
        ],
        'spectacles' => [
            'label'   => 'Spectacles',
            'icone'   => 'music',
            'modules' => ['evenements'],
            'route'   => 'spectacle',
            'liste'   => 'spectacles',
            'ordre'   => 'ORDER BY titre',
            'sql'     => "SELECT sp.id,
                                 trim(coalesce(par.nom || ' (' || sp.nom || ')', sp.nom)) AS titre,
                                 CASE WHEN par.nom IS NULL THEN 'Artiste' ELSE 'Spectacle' END AS sous_titre,
                                 0 AS tri,
                                 trim(coalesce(sp.nom,'') || ' ' || coalesce(par.nom,'') || ' ' ||
                                      coalesce(sp.notes,'')) AS texte
                          FROM spectacles sp LEFT JOIN spectacles par ON par.id = sp.parent_id",
        ],
    ];
}

// Modules à la fois activés sur cette installation ET lisibles par
// l'utilisateur courant. Calculé une fois par recherche, pas une fois par
// source.
function recherche_modules_accessibles(): array
{
    return array_values(array_filter(PERMISSION_MODULES, 'module_accessible'));
}

// Cœur du filtrage, isolé en fonction PURE pour être testable sans base ni
// session : c'est l'invariant de sécurité de tout ce fichier, il doit pouvoir
// être vérifié en continu. $accessibles = sortie de
// recherche_modules_accessibles().
//
// Un seul module suffit (logique « OU »), pour les entités partagées : les
// structures sont visibles depuis Facturation comme depuis Booking.
function recherche_source_visible_pour(array $source, array $accessibles): bool
{
    foreach ($source['modules'] as $m) {
        if (in_array($m, $accessibles, true)) {
            return true;
        }
    }
    return false;
}

// Vrai si l'utilisateur courant peut voir cette source.
function recherche_source_visible(array $source): bool
{
    return recherche_source_visible_pour($source, recherche_modules_accessibles());
}

// Découpe la saisie en mots, tous exigés (ET). « hector deborde » trouve donc
// l'événement dont le titre contient les deux, dans n'importe quel ordre —
// alors qu'un LIKE sur la chaîne entière n'aurait rien trouvé. Renvoie
// [fragmentSQL, paramètres].
function recherche_clause(string $q): array
{
    $mots = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $conditions = [];
    $params = [];
    foreach ($mots as $mot) {
        // SANS_ACCENTS() des deux côtés (fonction SQLite enregistrée par db()) :
        // le LIKE de SQLite est insensible à la casse ASCII mais SENSIBLE aux
        // accents, donc « deborde » ne trouvait pas « déborde » et « GENÈVE »
        // ne trouvait pas « genève ». Le repli est appliqué à la colonne comme
        // au terme saisi, sans quoi la comparaison serait asymétrique.
        $conditions[] = "SANS_ACCENTS(texte) LIKE ? ESCAPE '\\'";
        $params[] = '%' . like_echappe(texte_sans_accents($mot)) . '%';
    }
    return [implode(' AND ', $conditions), $params];
}

// Résultats groupés par catégorie :
// [cle => ['label', 'icone', 'route', 'liste', 'total', 'resultats' => [...]]].
// Les catégories sans résultat sont omises ; celles auxquelles l'utilisateur
// n'a pas accès ne sont même pas interrogées.
function recherche_globale(string $q): array
{
    if (mb_strlen(trim($q)) < RECHERCHE_MIN) {
        return [];
    }
    [$clause, $params] = recherche_clause($q);
    if ($clause === '') {
        return [];
    }

    $out = [];
    $accessibles = recherche_modules_accessibles();
    foreach (recherche_sources() as $cle => $source) {
        if (!recherche_source_visible_pour($source, $accessibles)) {
            continue;
        }
        // La source est enveloppée en sous-requête : le filtre porte sur la
        // colonne projetée « texte », sans dépendre des tables ni des alias
        // internes de chaque source.
        //
        // COUNT(*) OVER () plutôt qu'une requête de comptage séparée : le total
        // avant limite est calculé dans la même passe que les résultats. Deux
        // requêtes refaisaient le même parcours filtré — et ce parcours est
        // coûteux, puisque SANS_ACCENTS() est un rappel PHP appelé pour chaque
        // ligne (aucun index ne peut servir sur une expression calculée).
        // Mesuré sur la source la plus grosse : 3,40 ms -> 1,72 ms.
        // Le fenêtrage s'applique avant LIMIT, le total est donc bien celui de
        // l'ensemble filtré, pas des 8 lignes ramenées.
        //
        // LIMITE est une constante entière du code, jamais une saisie.
        $stmt = db()->prepare(
            "SELECT *, COUNT(*) OVER () AS total_global"
            . " FROM ({$source['sql']}) WHERE $clause {$source['ordre']} LIMIT " . RECHERCHE_LIMITE
        );
        $stmt->execute($params);
        $resultats = $stmt->fetchAll();
        if (!$resultats) {
            continue;
        }

        $out[$cle] = [
            'label'     => $source['label'],
            'icone'     => $source['icone'],
            'route'     => $source['route'],
            'liste'     => $source['liste'],
            'total'     => (int) $resultats[0]['total_global'],
            'resultats' => $resultats,
        ];
    }
    return $out;
}

function recherche_total(array $groupes): int
{
    return array_sum(array_column($groupes, 'total'));
}
