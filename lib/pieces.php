<?php
// Pièces comptables — les justificatifs (SPEC_PIECES.md).
//
// Une pièce est une ENTITÉ, pas une pièce jointe sur une écriture : elle se
// saisit avant que le moindre mouvement bancaire existe. Une facture arrive le
// 3, se paie le 30, l'écriture n'apparaît qu'à l'import du mois suivant — entre
// les deux, la pièce est la seule chose qui sache qu'il y a quelque chose à
// payer.

// Les quatre types, dans l'ordre où le formulaire les propose. « echeance »
// dit si le type porte une date limite : un ticket de caisse est déjà payé au
// moment où on le photographie, lui en demander une n'aurait pas de sens.
const PIECE_TYPES = [
    'facture_recue' => ['libelle' => 'Facture reçue', 'icone' => 'receipt-swiss-franc', 'echeance' => true],
    'ticket'        => ['libelle' => 'Ticket / reçu',  'icone' => 'receipt',             'echeance' => false],
    'quittance'     => ['libelle' => 'Quittance',      'icone' => 'file-check',          'echeance' => false],
    'autre'         => ['libelle' => 'Autre',          'icone' => 'file-text',           'echeance' => false],
];

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
const ECRITURE_PIECE_SELECT = ', (SELECT COUNT(*) FROM pieces pz WHERE pz.ecriture_id = e.id) AS nb_pieces'
    . ', (SELECT pc.piece_attendue FROM plan_comptes pc WHERE pc.id = e.plan_compte_id) AS piece_attendue';

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
