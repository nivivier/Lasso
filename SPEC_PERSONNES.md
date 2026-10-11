# Spécification — Les personnes

Statut : **cadrage pris, écriture détaillée à venir** (11.10.2026). Les
questions structurantes sont tranchées (§ 5) ; le détail des écrans s'écrira au
moment d'attaquer le chantier, qui vient après l'étape 5 de `SPEC_PIECES.md`.
Ce document existe d'abord pour que ces décisions ne se reperdent pas.

## 1. Le problème

Une personne existe aujourd'hui dans **trois tables qui ne se parlent pas** :

| Table | Lignes | Ce qu'elle porte |
| --- | --- | --- |
| `structure_contacts` | ~2 900 | prénom, nom, e-mail, téléphone, langue **+** rôle, `est_booking`, `est_administration`, `desinscrit`, `actif` — rattachés à **une seule** structure |
| `employes` | ~6 | identité, adresse postale, n° AVS, avatar |
| `utilisateurs` | 1–2 | identifiant de connexion |

Le musicien qui joue chez nous et figure aussi dans l'annuaire de booking est
deux lignes sans rapport. Le programmateur qui passe d'une salle à une autre est
deux contacts, et son historique se coupe en deux. L'utilisateur qui est aussi
employé est deux fois la même personne.

`structure_contacts` **confond deux choses** : une personne, et son rôle dans
une structure. Elle est déjà, de fait, la table de liaison — elle a son
`structure_id`. Il n'y a qu'à en sortir ce qui décrit la personne.

## 2. Le modèle visé

```
personnes            prénom, nom, email, téléphone, langue, adresse postale,
                     date_naissance, desinscrit
personne_structures  personne_id + structure_id + rôle, est_booking,
                     est_administration, actif
                     → l'ancienne structure_contacts, renommée pour ce qu'elle est
utilisateurs         + personne_id    0..1 ↔ 1
employes             + personne_id    0..1 ↔ 1
```

**Le mailing vise la personne** (§ 5). `mailing_file_attente` et
`mailing_envois` cessent de pointer un rôle pour pointer un être humain : c'est
à lui qu'on écrit, et c'est lui qui se désinscrit. `evenement_feuille` et
`structure_message_brouillons`, qui désignent un interlocuteur *dans une
structure donnée*, suivent le lien.

**La désinscription est une propriété de la personne** (§ 5), pas du lien :
qui se désinscrit ne reçoit plus rien, même en tant que contact d'une autre
structure. C'est ce qu'on croit demander en cliquant.

## 3. La migration

La contrainte qui aurait tout commandé — les liens de désinscription signés déjà
partis, qu'une renumérotation aurait transformés en « Lien invalide » — **ne
s'applique pas** : aucun mailing n'est parti en production. Les identifiants
peuvent changer, la refonte peut être propre.

Restent quatre tables qui pointent `structure_contacts(id)` :
`mailing_file_attente`, `mailing_envois`, `structure_message_brouillons`,
`evenement_feuille`. Les reporter est du travail ordinaire, et
`tests/migrations_test.php` vérifie déjà `PRAGMA foreign_key_check` sur toute la
chaîne : une clé cassée ne passerait pas l'intégration continue.

⚠️ Pour reconstruire une table avec un schéma modifié, suivre la règle de
`CLAUDE.md` : table temporaire `x_new`, copie, `DROP TABLE x`, `ALTER TABLE
x_new RENAME TO x` — **jamais** `RENAME TO x_old` puis recréation, qui laisse
des clés étrangères cassées (`migration_21` sert d'exemple).

**Une personne par contact, aucun regroupement automatique** (§ 5). Avec ~2 900
lignes, des boîtes partagées (`info@…`) et des homonymes, un dédoublonnage
automatique fabriquerait des fusions fausses et silencieuses — et une fusion
fausse coûte bien plus cher qu'un doublon. Un écran « Doublons probables »
proposera plus tard des fusions à valider une par une.

⚠️ **À mesurer AVANT de lancer la migration** : combien de personnes sont
aujourd'hui désinscrites d'un côté et joignables de l'autre. Passer la
désinscription au niveau de la personne les fera toutes basculer en
« désinscrite », et c'est un changement de comportement qu'il faut avoir
chiffré, pas découvert.

## 4. Ce que ça apporte

- Une **quittance** peut être faite à une personne comme à une structure
  (`SPEC_PIECES.md § 10`).
- Un employé, un utilisateur et un contact qui sont la même personne le disent.
- Un contact qui change de structure garde son identité et son historique.
- L'annuaire devient dédoublonnable, un jour, sans refonte.

## 5. Ce qui a été tranché

| Question | Réponse |
| --- | --- |
| Forme de la migration | **Refonte propre** : `personnes` + `personne_structures`, le mailing visant la personne |
| Portée de la désinscription | **Par personne, partout** |
| Doublons de l'annuaire | **Un écran de fusion manuelle, plus tard.** Une personne par contact à la migration |
| Personnes physiques saisies comme structures (les « Freelance ») | **On les laisse où elles sont.** Ces fiches portent déjà des événements, des campagnes, des factures ; les déplacer obligerait tout ce qui pointe une structure à accepter aussi une personne. Une quittance à un freelance se fait à la structure |
| Adresse postale | **Elle monte sur `personnes`.** Une seule adresse, que le certificat de salaire et la quittance lisent au même endroit |
| Écran et droits | **« Personnes » dans Paramètres, droit `coeur`.** Une personne traverse le booking, les salaires et les comptes : aucun module ne peut en être propriétaire |
| Historique | **Rattaché à la personne ET à la structure** |
| Ordre | **Après l'étape 5 de `SPEC_PIECES.md`**, avant les quittances |

## 6. Deux points de mise en œuvre déjà connus

**L'adresse et les fiches de salaire.** Déplacer `employes.rue` /
`npa_localite` est sans danger pour l'historique : une fiche **fige déjà**
l'adresse de l'employé à l'enregistrement (`fiches.employe_nom`,
`employe_rue`, `employe_npa`, `employe_avs`). Les fiches existantes lisent leur
propre copie et ne bougeront pas. Seul le **certificat de salaire** lit
l'adresse en direct (`views/_certificat_body.php`) : il suivra la relation, ce
qui ne change pas son comportement actuel.

**L'historique sur deux axes.** La table est déjà polymorphe —
`entite_type` + `entite_id`. Plutôt que deux lignes pour un même échange,
ajouter une colonne `personne_id` facultative à côté de la clé existante : une
entrée, deux axes de lecture, et les entrées actuelles — qui n'ont pas de
personne — restent valides telles quelles.

## 7. À cadrer au moment d'attaquer

- Le détail des écrans : fiche d'une personne, liste, recherche, rattachements.
- Ce que devient `recherche_sources()` (`lib/recherche.php`) : une personne
  devient-elle une entité cherchable de plus ?
- `SPEC_PERMISSIONS.md` : l'écran Personnes sous `coeur`, et sa place dans
  `parametres_groupes()`.
