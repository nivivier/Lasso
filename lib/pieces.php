<?php
// Pièces comptables — les justificatifs (SPEC_PIECES.md).
//
// Une pièce est une ENTITÉ, pas une pièce jointe sur une écriture : elle se
// saisit avant que le moindre mouvement bancaire existe. Une facture arrive le
// 3, se paie le 30, l'écriture n'apparaît qu'à l'import du mois suivant — entre
// les deux, la pièce est la seule chose qui sache qu'il y a quelque chose à
// payer.

// Les cinq types, dans l'ordre où le formulaire les propose. Chacun porte ce
// que le formulaire doit savoir de lui — le type n'est pas une étiquette, c'est
// ce qui décide des champs à poser :
//
//   echeance  le type porte-t-il une date limite ? Un ticket déjà payé au
//             moment où on le photographie n'en a pas.
//   sens      le signe du montant, connu d'avance. Une facture reçue est une
//             dépense, une quittance une recette : demander « dépense ou
//             recette ? » sur ces types-là, c'est faire choisir ce qu'on sait
//             déjà. Seul « autre » laisse la question ouverte ('').
//   paye      la pièce naît payée : le ticket de carte sort du compte de
//             l'association au moment de l'achat, la quittance constate de
//             l'argent déjà reçu. La date du jour s'y pose d'office.
//   tiersPour à qui la pièce s'adresse, quand elle s'adresse à quelqu'un.
//             Vide = le champ bénéficiaire ne paraît pas.
//   nouveau   le titre de la page de création. Écrit en toutes lettres et non
//             fabriqué par « Nouvelle » + libellé, qui donnerait « Nouvelle
//             ticket de carte » et « Nouvelle à rembourser ».
//
// ⚠️ « Ticket de carte » et « À rembourser » sont deux types et non un seul
// parce qu'ils ne disent pas la même dette : le premier est déjà sorti du
// compte, le second est de l'argent que QUELQU'UN a avancé et que
// l'association lui doit encore. Les confondre, c'est perdre la trace de ce
// qu'on doit à ses bénévoles.
const PIECE_TYPES = [
    'facture_recue' => ['libelle' => 'Facture reçue',  'icone' => 'receipt-swiss-franc', 'echeance' => true,
                        'sens' => 'depense', 'paye' => false, 'tiersPour' => '',
                        'nouveau' => 'Nouvelle facture reçue',
                        'aide' => 'Reçue d\'un fournisseur, à payer avant son échéance.'],
    'ticket'        => ['libelle' => 'Ticket de carte', 'icone' => 'credit-card',        'echeance' => false,
                        'sens' => 'depense', 'paye' => true,  'tiersPour' => '',
                        'nouveau' => 'Nouveau ticket de carte',
                        'aide' => 'Déjà payé depuis le compte de l\'association.'],
    'remboursement' => ['libelle' => 'À rembourser',    'icone' => 'hand-coins',         'echeance' => false,
                        'sens' => 'depense', 'paye' => false, 'tiersPour' => 'À rembourser à',
                        'nouveau' => 'Nouvelle dépense à rembourser',
                        'aide' => 'Quelqu\'un a avancé l\'argent : note de frais.'],
    'quittance'     => ['libelle' => 'Quittance',       'icone' => 'file-check',         'echeance' => false,
                        'sens' => 'recette', 'paye' => true,  'tiersPour' => 'Bénéficiaire',
                        'nouveau' => 'Nouvelle quittance',
                        'aide' => 'Pour de l\'argent reçu : Lasso produit le document.'],
    'autre'         => ['libelle' => 'Autre',           'icone' => 'file-text',          'echeance' => false,
                        'sens' => '',        'paye' => false, 'tiersPour' => '',
                        'nouveau' => 'Nouvelle pièce',
                        'aide' => 'Tout le reste. C\'est le seul type où le sens se choisit.'],
];

// Le menu « Nouvelle pièce » : un type par entrée, pour qu'on arrive sur le
// formulaire en ayant déjà répondu à sa première question. Rendu ici et non
// dans chaque vue — la liste des pièces et le tableau de bord ouvrent le MÊME
// menu, et un second exemplaire aurait fini par ne plus dire la même chose
// (docs/UI.md § 6).
function pieces_menu_nouvelle_html(array $bouton = [], array $opts = []): string
{
    // Ce que le formulaire doit savoir en plus du type : d'où l'on vient
    // ('depuis'), et l'écriture à laquelle rattacher la pièce ('params'),
    // quand on dépose depuis la colonne « Pièce » d'une écriture.
    $suite = isset($opts['depuis']) ? '&depuis=' . rawurlencode((string) $opts['depuis']) : '';
    foreach ((array) ($opts['params'] ?? []) as $cleP => $valP) {
        $suite .= '&' . rawurlencode((string) $cleP) . '=' . rawurlencode((string) $valP);
    }
    $entrees = [];
    foreach (PIECE_TYPES as $cle => $t) {
        $entrees[] = [
            'libelle' => $t['libelle'],
            'icone'   => $t['icone'],
            'titre'   => $t['aide'],
            'href'    => '?p=piece_form&type=' . $cle . $suite,
        ];
    }
    return menu_deroulant_html(
        $bouton + ['icone' => 'file-plus', 'libelle' => 'Nouvelle pièce', 'plein' => true],
        $entrees,
        ['classe' => trim('pieces-creer ' . (string) ($opts['classe'] ?? ''))]
    );
}

// Les modes de paiement. '' n'est pas un mode : c'est l'absence de paiement.
//
// ⚠️ « hors_compte » n'est pas un état, c'est un MODE : espèces, carte privée,
// autre banque. Il dit qu'aucune écriture ne viendra jamais, et c'est à ce
// titre qu'il classe la pièce — sans lui, une dépense réglée en espèces serait
// restée à vie dans « à rapprocher ».
const PIECE_MODES_PAIEMENT = [
    'compte'      => 'Depuis le compte suivi',
    'hors_compte' => 'Hors du compte suivi (espèces, autre banque…)',
];

// Les trois états d'une pièce, et rien d'autre. Chaque écran qui range, compte
// ou filtre des pièces passe par ici — c'est la même discipline que
// ecriture_piece_etat() de l'autre côté du rapprochement.
const PIECE_ETATS = [
    'a_payer'      => 'À payer',
    'a_rapprocher' => 'À rapprocher',
    'classee'      => 'Classée',
];

function piece_etat(array $p): string
{
    if ((string) ($p['date_payee'] ?? '') === '') {
        return 'a_payer';
    }
    if (!empty($p['ecriture_id']) || (string) ($p['mode_paiement'] ?? '') === 'hors_compte') {
        return 'classee';
    }
    return 'a_rapprocher';
}

// Le même calcul en SQL, pour filtrer et compter sans tout charger. $p préfixe
// la table (« p. »). ⚠️ Doit dire exactement la même chose que piece_etat()
// ci-dessus — tests/pieces_test.php le vérifie sur les deux chemins.
function piece_etat_sql(string $etat, string $p = 'p.'): string
{
    return match ($etat) {
        'a_payer'      => "{$p}date_payee = ''",
        'classee'      => "{$p}date_payee <> '' AND ({$p}ecriture_id IS NOT NULL OR {$p}mode_paiement = 'hors_compte')",
        'a_rapprocher' => "{$p}date_payee <> '' AND {$p}ecriture_id IS NULL AND {$p}mode_paiement <> 'hors_compte'",
        default        => '1=1',
    };
}

// Une pièce est-elle en retard ? Échéance passée et pas encore payée. C'est ce
// que la liste signale à l'ambre, et ce que compte la pastille de l'onglet.
function piece_en_retard(array $p, ?string $aujourdhui = null): bool
{
    $ech = (string) ($p['date_echeance'] ?? '');
    return $ech !== '' && (string) ($p['date_payee'] ?? '') === '' && $ech < ($aujourdhui ?? date('Y-m-d'));
}

function piece_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pieces WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Le compteur de la pastille : ce qu'il reste à payer. Les pièces en retard
// comptent comme les autres — la pastille dit « il y a du travail », pas « il y
// a une urgence », c'est la carte du tableau de bord qui distingue.
function nb_pieces_a_payer(): int
{
    if (!module_accessible('pieces')) {
        return 0;
    }
    return (int) db()->query('SELECT COUNT(*) FROM pieces WHERE ' . piece_etat_sql('a_payer', ''))->fetchColumn();
}

// La pastille d'état, sur le modèle de facturation_badge(). Une pièce payée
// porte SA DATE plutôt que le mot « payée » : c'est l'information qu'on cherche
// quand on la relit, et le mot ne dit rien que la colonne ne dise déjà.
function piece_badge_html(array $p, ?string $etat = null, ?bool $retard = null): string
{
    $etat   = $etat   ?? piece_etat($p);
    $retard = $retard ?? piece_en_retard($p);
    if ($etat === 'a_payer') {
        return $retard ? badge('En retard', 'warn') : badge('À payer', 'muted');
    }
    $jour = (string) ($p['date_payee'] ?? '');
    $jour = $jour !== '' ? date('d.m.Y', strtotime($jour)) : 'Payée';
    if ($etat === 'classee') {
        return badge($jour, 'ok');
    }
    // Payée mais pas encore rapprochée : ni un geste à faire ni une affaire
    // classée. L'écriture viendra d'elle-même au prochain import.
    return badge($jour, 'emise');
}

// ---------------------------------------------------------------------------
// L'état d'une ÉCRITURE du point de vue du justificatif.
//
// ⚠️ UNE SEULE FONCTION, et c'est volontaire. Six endroits posent la même
// question — la colonne du tableau, les sentinelles du filtre, le compteur, la
// carte du tableau de bord, l'export CSV, l'archive annuelle. Six requêtes
// écrites chacune dans son coin finiraient par ne plus donner le même nombre,
// et personne ne saurait laquelle a raison.
//
// L'ordre de résolution n'est pas indifférent : une écriture qui paie une fiche
// de salaire EST justifiée par elle, même si on lui a par ailleurs accroché une
// pièce. Le justificatif le plus fort gagne.
const ECRITURE_PIECE_ETATS = ['fiche', 'facture', 'piece', 'dispensee', 'manquante'];

function ecriture_piece_etat(array $ecr): string
{
    if (!empty($ecr['fiche_id']))   { return 'fiche'; }
    if (!empty($ecr['facture_id'])) { return 'facture'; }
    if (!empty($ecr['nb_pieces']))  { return 'piece'; }
    if (trim((string) ($ecr['piece_dispense_motif'] ?? '')) !== '') { return 'dispensee'; }
    // La dispense par catégorie : déclarée une fois sur le plan comptable. Une
    // écriture NON LETTRÉE n'a pas de catégorie, donc pas de dispense par ce
    // chemin — elle reste « manquante » jusqu'à ce qu'on la lettre. C'est
    // voulu : lettrer vient avant justifier.
    if (!empty($ecr['plan_compte_id']) && isset($ecr['piece_attendue']) && (int) $ecr['piece_attendue'] === 0) {
        return 'dispensee';
    }
    return 'manquante';
}

// Ce que ecriture_piece_etat() attend dans la ligne qu'on lui passe : à joindre
// à toute requête qui l'appellera. Mis ici pour que la fonction et la requête
// qui la nourrit ne puissent pas diverger.
// Avec elles vient l'identifiant de la PREMIÈRE pièce jointe : l'état « pièce »
// se montre partout comme un lien vers la preuve (ecriture_piece_href()), et
// une requête qui donnerait l'état sans le lien ferait une icône morte.
const ECRITURE_PIECE_SELECT = ', (SELECT COUNT(*) FROM pieces pz WHERE pz.ecriture_id = e.id) AS nb_pieces'
    . ', (SELECT pz.id FROM pieces pz WHERE pz.ecriture_id = e.id ORDER BY pz.id LIMIT 1) AS piece_id'
    . ', (SELECT pc.piece_attendue FROM plan_comptes pc WHERE pc.id = e.plan_compte_id) AS piece_attendue';

// Où mène l'icône de la colonne « Pièce » : vers la preuve elle-même, qui est
// la seule chose qu'on veuille voir en la montrant. Rend une URL nue — le
// « depuis » est l'affaire de l'appelant —, ou une chaîne vide quand il n'y a
// rien à ouvrir : une dispense n'est pas un document, et un module auquel le
// compte n'a pas accès n'est pas une porte (le dispatcher refuserait, et
// proposer un lien mort est pire que ne rien proposer).
function ecriture_piece_href(array $ecr, string $etat): string
{
    return match ($etat) {
        'fiche'   => module_accessible('salaires')    ? '?p=fiche&id=' . (int) $ecr['fiche_id'] : '',
        'facture' => module_accessible('facturation') ? '?p=facture&id=' . (int) $ecr['facture_id'] : '',
        'piece'   => module_accessible('pieces') && !empty($ecr['piece_id'])
            ? '?p=piece&id=' . (int) $ecr['piece_id'] : '',
        default   => '',
    };
}

// Le même calcul en SQL, pour le filtre de colonne et les compteurs. Rend une
// condition portant sur « e. » (les écritures), à combiner en OR quand
// plusieurs états sont cochés.
function ecriture_piece_etat_sql(string $etat): string
{
    $aPiece    = 'EXISTS (SELECT 1 FROM pieces pz WHERE pz.ecriture_id = e.id)';
    $dispensee = "(TRIM(e.piece_dispense_motif) <> ''"
        . ' OR (e.plan_compte_id IS NOT NULL AND EXISTS'
        . '     (SELECT 1 FROM plan_comptes pc WHERE pc.id = e.plan_compte_id AND pc.piece_attendue = 0)))';
    $justifiee = "(e.fiche_id IS NOT NULL OR e.facture_id IS NOT NULL OR $aPiece)";

    return match ($etat) {
        'fiche'     => 'e.fiche_id IS NOT NULL',
        'facture'   => 'e.fiche_id IS NULL AND e.facture_id IS NOT NULL',
        'piece'     => "e.fiche_id IS NULL AND e.facture_id IS NULL AND $aPiece",
        'presente'  => $justifiee,
        'dispensee' => "NOT $justifiee AND $dispensee",
        'manquante' => "NOT $justifiee AND NOT $dispensee",
        default     => '1=1',
    };
}

// Comment la colonne « Pièce » se lit : un mot et une icône par état. Le mot
// sert d'infobulle et de libellé dans le panneau de filtres du téléphone, où
// aucun en-tête de colonne ne nomme ce qu'on filtre.
const ECRITURE_PIECE_LIBELLES = [
    'fiche'     => 'Fiche de salaire',
    'facture'   => 'Facture émise',
    'piece'     => 'Pièce jointe',
    'dispensee' => 'Sans pièce attendue',
    'manquante' => 'Pièce manquante',
];

const ECRITURE_PIECE_ICONES = [
    'fiche'     => 'file-text',
    'facture'   => 'receipt-swiss-franc',
    'piece'     => 'paperclip',
    'dispensee' => 'minus',
    'manquante' => '',
];

// ---------------------------------------------------------------------------
// Rapprochement

// Les écritures auxquelles une pièce peut se rattacher : celles du même SENS
// qu'elle (une facture reçue se paie par un débit), pas déjà prises par une
// autre pièce, par une facture émise ni par une fiche de salaire. Celle de
// cette pièce-ci reste dans la liste, sans quoi rouvrir la fenêtre pour
// corriger la délierait — même précaution que fonds_ecritures_rapprochables().
//
// Triées par proximité : d'abord l'écart de montant, puis la distance à la date
// de la pièce. C'est ce qui met la bonne en tête neuf fois sur dix.
function pieces_ecritures_rapprochables(array $piece, int $max = 200): array
{
    $montant = (float) ($piece['montant'] ?? 0);
    $ref     = (string) ($piece['date_payee'] ?: ($piece['date_echeance'] ?: $piece['date_piece']));
    $sienne  = (int) ($piece['ecriture_id'] ?? 0);
    $sens    = $montant < 0 ? 'e.montant <= 0' : 'e.montant >= 0';

    $sql = "SELECT e.id, e.date_op, e.texte, e.tiers, e.montant, c.libelle AS compte_libelle
              FROM ecritures e
              JOIN comptes_bancaires c ON c.id = e.compte_bancaire_id
             WHERE $sens
               AND e.fiche_id IS NULL AND e.facture_id IS NULL
               AND (e.id = :sienne OR NOT EXISTS (SELECT 1 FROM pieces pz WHERE pz.ecriture_id = e.id))
          ORDER BY ABS(e.montant - :montant), ABS(julianday(e.date_op) - julianday(:ref)), e.date_op DESC
             LIMIT :max";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':sienne', $sienne, PDO::PARAM_INT);
    $stmt->bindValue(':montant', $montant);
    $stmt->bindValue(':ref', $ref !== '' ? $ref : date('Y-m-d'));
    $stmt->bindValue(':max', $max, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// L'inverse : les pièces qu'on peut accrocher à une écriture. Plusieurs pièces
// pour une écriture est permis (un virement groupé solde trois factures), donc
// on ne retire que celles déjà prises par une AUTRE écriture.
function pieces_rapprochables(array $ecriture, int $max = 200): array
{
    $montant = (float) ($ecriture['montant'] ?? 0);
    $ref     = (string) ($ecriture['date_op'] ?? date('Y-m-d'));
    $eid     = (int) ($ecriture['id'] ?? 0);
    $sens    = $montant < 0 ? 'p.montant <= 0' : 'p.montant >= 0';

    $sql = "SELECT p.* FROM pieces p
             WHERE $sens
               AND (p.ecriture_id IS NULL OR p.ecriture_id = :eid)
               AND p.mode_paiement <> 'hors_compte'
          ORDER BY ABS(p.montant - :montant),
                   ABS(julianday(COALESCE(NULLIF(p.date_payee,''), NULLIF(p.date_echeance,''), p.date_piece)) - julianday(:ref)),
                   p.id DESC
             LIMIT :max";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':eid', $eid, PDO::PARAM_INT);
    $stmt->bindValue(':montant', $montant);
    $stmt->bindValue(':ref', $ref);
    $stmt->bindValue(':max', $max, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Rattache une pièce à une écriture, ou l'en détache ($ecritureId = 0).
//
// ⚠️ Rattacher NE LETTRE PAS. La catégorie présumée d'une pièce ne sert qu'à
// ranger les pièces entre elles : lettrer et justifier restent deux gestes. Les
// mêler ferait apparaître une troisième provenance là où origine_lettrage n'en
// connaît que deux (« regle », « manuel »), et on ne saurait plus pourquoi une
// écriture porte la catégorie qu'elle porte.
//
// Rattacher vaut en revanche confirmation du paiement : une écriture est la
// preuve que l'argent est parti. Une pièce qu'on rapproche sans l'avoir marquée
// payée prend donc la date de l'écriture.
function piece_rapprocher(int $pieceId, int $ecritureId): void
{
    if ($ecritureId <= 0) {
        db()->prepare('UPDATE pieces SET ecriture_id = NULL WHERE id = ?')->execute([$pieceId]);
        return;
    }
    $stmt = db()->prepare('SELECT date_op FROM ecritures WHERE id = ?');
    $stmt->execute([$ecritureId]);
    $dateOp = (string) ($stmt->fetchColumn() ?: '');
    db()->prepare(
        "UPDATE pieces
            SET ecriture_id = ?,
                date_payee = CASE WHEN date_payee = '' THEN ? ELSE date_payee END,
                mode_paiement = CASE WHEN mode_paiement = '' THEN 'compte' ELSE mode_paiement END
          WHERE id = ?"
    )->execute([$ecritureId, $dateOp, $pieceId]);
}

// Supprime une pièce et le fichier qu'elle portait — sinon data/fichiers/
// accumule indéfiniment ce que plus rien ne désigne.
function piece_supprimer(int $id): void
{
    $p = piece_charger($id);
    if (!$p) {
        return;
    }
    db()->prepare('DELETE FROM pieces WHERE id = ?')->execute([$id]);
    fichier_prive_supprimer((string) $p['fichier']);
}

// ---------------------------------------------------------------------------
// Les rapprochements PROPOSÉS
//
// ⚠️ On propose, on ne pose pas. C'est là que ce module s'écarte
// délibérément de facturation_suggerer_rapprochements(), qui lie tout seul à
// l'import : une facture REÇUE peut arriver en double, et deux factures du
// même montant au même fournisseur sont la règle plutôt que l'exception —
// un abonnement mensuel en produit douze par an. Un lien faux posé en silence
// ne se découvre qu'au bouclement, un an plus tard, et il faut alors deviner
// lequel des deux paiements allait avec laquelle des deux factures.
//
// Rien n'est stocké : les suggestions se recalculent à chaque affichage. Une
// table de suggestions aurait vieilli — une pièce rattachée à la main, une
// écriture supprimée, et elle proposerait des rapprochements impossibles.

// Fenêtre de rapprochement, en jours autour de la date de référence de la
// pièce. 45 jours parce que les relevés s'importent une fois par mois : une
// facture payée le 30 peut n'apparaître qu'à l'import du mois suivant, et la
// date du prélèvement n'est pas celle de l'échéance.
const PIECE_FENETRE_JOURS = 45;

// Au plus UNE suggestion par pièce, la meilleure. Proposer deux écritures pour
// la même pièce obligerait à choisir dans une liste, alors que la pièce n'en
// prend qu'une : autant ne montrer que celle qui gagne, et laisser la fiche de
// la pièce pour les cas douteux — elle, montre toutes les candidates.
//
// Le montant doit coller AU CENTIME. Un écart, si petit soit-il, veut dire
// autre chose (des frais, un acompte, une erreur) et mérite qu'on regarde.
function pieces_suggestions(int $max = 50): array
{
    if (!module_accessible('pieces') || !module_accessible('compta')) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT p.* FROM pieces p
          WHERE p.ecriture_id IS NULL AND p.mode_paiement <> 'hors_compte' AND p.montant <> 0
       ORDER BY COALESCE(NULLIF(p.date_payee,''), NULLIF(p.date_echeance,''), p.date_piece) DESC
          LIMIT ?"
    );
    $stmt->bindValue(1, $max, PDO::PARAM_INT);
    $stmt->execute();
    $pieces = $stmt->fetchAll();
    if (!$pieces) {
        return [];
    }

    // Une écriture ne peut être proposée qu'une fois dans la même liste :
    // valider deux suggestions qui la désignent donnerait deux pièces sur le
    // même paiement sans que personne ne l'ait voulu.
    $prises = [];
    $out = [];
    $req = db()->prepare(
        "SELECT e.*, c.libelle AS compte_libelle FROM ecritures e
           JOIN comptes_bancaires c ON c.id = e.compte_bancaire_id
          WHERE ABS(e.montant - :montant) < 0.005
            AND e.fiche_id IS NULL AND e.facture_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM pieces pz WHERE pz.ecriture_id = e.id)
            AND ABS(julianday(e.date_op) - julianday(:ref)) <= :fenetre
       ORDER BY ABS(julianday(e.date_op) - julianday(:ref))"
    );
    foreach ($pieces as $p) {
        $ref = (string) ($p['date_payee'] ?: ($p['date_echeance'] ?: $p['date_piece']));
        if ($ref === '') {
            continue;
        }
        // ⚠️ bindValue avec PARAM_INT, et surtout pas execute([...]) : un
        // tableau passé à execute() lie TOUT en chaîne, et SQLite compare
        // alors un nombre à un texte — or un nombre y est TOUJOURS inférieur à
        // un texte, quelles que soient leurs valeurs. « 61 <= '45' » est vrai.
        // La fenêtre ne filtrait donc rien, et le loyer de mai se proposait
        // pour la facture de mars. Mesuré.
        $req->bindValue(':montant', (float) $p['montant']);
        $req->bindValue(':ref', $ref);
        $req->bindValue(':fenetre', PIECE_FENETRE_JOURS, PDO::PARAM_INT);
        $req->execute();
        $candidates = $req->fetchAll();
        $meilleure = null;
        $parNom = false;
        foreach ($candidates as $c) {
            if (isset($prises[(int) $c['id']])) {
                continue;
            }
            // Le nom du tiers retrouvé dans le libellé fait toute la
            // différence entre « c'est probablement ça » et « c'est ça » : on
            // préfère donc une écriture plus lointaine en date mais nommée à
            // une écriture voisine et muette.
            $nomTrouve = pieces_nom_present((string) $p['tiers'], $c);
            if ($meilleure === null || ($nomTrouve && !$parNom)) {
                $meilleure = $c;
                $parNom = $nomTrouve;
            }
            if ($parNom) {
                break;
            }
        }
        if ($meilleure !== null) {
            $prises[(int) $meilleure['id']] = true;
            $out[] = ['piece' => $p, 'ecriture' => $meilleure, 'par_nom' => $parNom];
        }
    }
    return $out;
}

// Le tiers de la pièce apparaît-il dans ce que la banque a écrit ? Comparaison
// insensible à la casse sur les trois champs où un nom peut se trouver — le
// relevé le met tantôt en contre-partie structurée, tantôt noyé dans le texte.
function pieces_nom_present(string $tiers, array $ecriture): bool
{
    $tiers = mb_strtolower(trim($tiers), 'UTF-8');
    // Moins de trois lettres, le nom n'en est pas un : « AB » se retrouve dans
    // la moitié des libellés et ferait passer n'importe quoi pour une
    // correspondance certaine.
    if (mb_strlen($tiers, 'UTF-8') < 3) {
        return false;
    }
    $foin = mb_strtolower(
        (string) ($ecriture['texte'] ?? '') . ' ' . ($ecriture['tiers'] ?? '') . ' ' . ($ecriture['communication'] ?? ''),
        'UTF-8'
    );
    return str_contains($foin, $tiers);
}

function nb_pieces_suggestions(): int
{
    return count(pieces_suggestions());
}

// Ce que la carte du tableau de bord montre : les pièces à payer, les plus
// pressées d'abord — une échéance proche passe avant une échéance lointaine,
// et une pièce sans échéance ferme la marche. Plus le compte TOTAL, qui porte
// sur tout et non sur les cinq lignes montrées : c'est l'écart entre les deux
// que la dernière ligne « et X autres » rend lisible (docs/UI.md § 9).
function pieces_dashboard(int $max = 5): array
{
    if (!module_accessible('pieces')) {
        return ['pieces' => [], 'nb' => 0];
    }
    $nb = (int) db()->query('SELECT COUNT(*) FROM pieces p WHERE ' . piece_etat_sql('a_payer'))->fetchColumn();
    if ($nb === 0) {
        return ['pieces' => [], 'nb' => 0];
    }
    $stmt = db()->prepare(
        'SELECT p.* FROM pieces p WHERE ' . piece_etat_sql('a_payer')
        . " ORDER BY CASE WHEN p.date_echeance = '' THEN 1 ELSE 0 END, p.date_echeance, p.id LIMIT ?"
    );
    $stmt->bindValue(1, $max, PDO::PARAM_INT);
    $stmt->execute();
    return ['pieces' => $stmt->fetchAll(), 'nb' => $nb];
}

// ---------------------------------------------------------------------------
// Les QUITTANCES
//
// Une quittance est une pièce que Lasso PRODUIT (origine = 'emise'), sans
// fichier téléversé. Elle constate après coup — argent reçu d'une vente, d'une
// caisse de merchandising, d'une cotisation — là où une facture réclame avant.
// Pas de QR, pas de délai de paiement : réutiliser la facturation aurait dit
// la chose de travers.
//
// Le bénéficiaire est une STRUCTURE ou une PERSONNE, jamais les deux. Deux
// colonnes nullables plutôt qu'un couple type+id : SQLite sait alors vérifier
// la clé étrangère, et une structure supprimée ne laisse pas une quittance
// pointant un identifiant devenu celui de quelqu'un d'autre.

// Numérotation propre, annuelle : Q-2026-001. Série DISTINCTE de celle des
// factures — le préfixe dit tout de suite de quel document il s'agit, et
// aucune des deux séries n'a de trou. La mécanique, elle, est la même
// (prochain_numero_annuel(), lib/helpers.php) : le piège du tri lexicographique
// a été payé une fois en facturation, il n'avait pas à l'être une seconde.
function quittance_prochain_numero(int $annee): string
{
    return prochain_numero_annuel(db(), 'pieces', 'Q', $annee);
}

// Celui que la pièce désigne, en un nom et une adresse prêts à imprimer : le
// bénéficiaire d'une quittance, la personne à rembourser d'une note de frais.
// Une structure ou une personne — ou, à défaut des deux, le tiers saisi à la
// main, parce qu'on encaisse parfois de quelqu'un qui n'est dans aucun carnet.
function piece_beneficiaire(array $p): array
{
    if (!empty($p['structure_id'])) {
        $stmt = db()->prepare('SELECT nom, adresse_rue, adresse_npa, adresse_localite FROM structures WHERE id = ?');
        $stmt->execute([(int) $p['structure_id']]);
        if ($s = $stmt->fetch()) {
            return [
                'nom'     => (string) $s['nom'],
                'adresse' => trim((string) $s['adresse_rue']),
                'localite' => trim((string) $s['adresse_npa'] . ' ' . (string) $s['adresse_localite']),
            ];
        }
    }
    if (!empty($p['personne_id'])) {
        if ($pe = personne_charger((int) $p['personne_id'])) {
            return [
                'nom'      => personne_nom($pe),
                'adresse'  => trim((string) $pe['rue']),
                'localite' => trim((string) $pe['npa_localite']),
            ];
        }
    }
    return ['nom' => trim((string) ($p['tiers'] ?? '')), 'adresse' => '', 'localite' => ''];
}
