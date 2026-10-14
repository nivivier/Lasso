# Spécification — Les personnes

Statut : **implémenté** (12.10.2026), migration 100. Ce document reste le
« pourquoi » — en cas de désaccord avec le code livré, c'est le code qui fait
foi.

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
| Doublons de l'annuaire | **Un écran de fusion manuelle, plus tard.** Une personne par contact à la migration. ⚠️ **Fait** : il vit dans `?p=incoherences`, section « Doublons de l'annuaire », avec les autres outils de reprise. Une suggestion s'écarte (« ce n'est pas un doublon ») et se reprend ; ce qu'on écarte est la **clé** — le nom ou l'adresse —, pas le groupe, sans quoi la suggestion reviendrait au premier import qui ajoute une fiche de plus |
| Personnes physiques saisies comme structures (les « Freelance ») | **On les laisse où elles sont.** Ces fiches portent déjà des événements, des campagnes, des factures ; les déplacer obligerait tout ce qui pointe une structure à accepter aussi une personne. Une quittance à un freelance se fait à la structure |
| Adresse postale | **Elle monte sur `personnes`.** Une seule adresse, que le certificat de salaire et la quittance lisent au même endroit |
| Écran et droits | **« Personnes » dans Paramètres, droit `coeur`** — faute de mieux : une personne traverse le booking, les salaires et les comptes, et aucun de ces modules-là ne pouvait en être propriétaire. ⚠️ **Dépassé depuis** : l'écran vit dans le module **Carnet d'adresses** (droit `carnet`), créé pour porter ce qu'aucun autre ne pouvait — voir `CLAUDE.md § Modules & droits` |
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

## 7. Comment ça a été fait

**Les identifiants ont été PRÉSERVÉS** à la migration : `personnes.id` et
`personne_structures.id` reprennent `structure_contacts.id`. Renuméroter était
permis — aucun mailing n'était parti —, mais préserver ne coûtait rien et
supprime toute une classe d'erreurs de reprise : les quatre tables qui
pointaient un contact restent justes sans aucune table de correspondance.

⚠️ **Deux constantes font que rien n'a eu à changer dans les vues.**
`CONTACT_SELECT`/`CONTACT_FROM` et `EMPLOYE_SELECT`/`EMPLOYE_FROM`
(`lib/personnes.php`) rendent les colonnes sous les noms que le code attendait
déjà : `$c['prenom']`, `$e['rue']`. Le stockage a changé, la forme lue non —
sans quoi il aurait fallu reprendre une centaine d'endroits, et un `SELECT *`
oublié aurait affiché du vide **sans la moindre erreur**. Un SELECT va
toujours avec son FROM : l'inverse échoue à l'exécution, ce qui est
préférable.

**La fusion est le geste le plus dangereux du module**, et elle est écrite
comme tel : la cible garde ses valeurs, une source ne comble que les champs
vides, la désinscription se propage sans jamais s'annuler, et deux rôles dans
la même structure n'en font qu'un. « La dernière valeur gagne » aurait fait
perdre une adresse renseignée au profit d'une fiche vide, en silence.

Mesuré sur les données réelles : 2903 contacts → 2903 personnes + 2903 liens,
6 employés liés avec leur adresse, zéro clé étrangère cassée.

## 8. Ce qui reste ouvert

- **Le chiffre à relever avant de s'en servir en production** : combien de
  personnes étaient désinscrites d'un côté et joignables de l'autre. La
  désinscription vaut désormais pour la personne entière, et ces gens-là ont
  tous basculé. Le jeu de développement n'en compte qu'une, il ne dit rien de
  la production.
- **La recherche unifiée** (`recherche_sources()`) cherche les contacts par
  leur lien, pas les personnes en tant que telles : une personne sans aucun
  rattachement est introuvable autrement que par l'écran Personnes.
- **Les personnes physiques saisies comme structures** (les « Freelance ») sont
  restées où elles étaient, par décision (§ 5).
