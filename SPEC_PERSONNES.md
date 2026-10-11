# Spécification — Les personnes

Statut : **cadrage pris, écriture détaillée à venir** (11.10.2026). Les quatre
questions structurantes sont tranchées (§ 5) ; le détail des écrans s'écrira au
moment d'attaquer le chantier, qui vient après les étapes 1 à 4 de
`SPEC_PIECES.md`. Ce document existe d'abord pour que ces décisions ne se
reperdent pas.

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
une structure. C'est aussi ce qui rend la correction abordable — la table **est
déjà** la table de liaison, elle a son `structure_id`. Il n'y a pas à la
remplacer, seulement à en sortir ce qui décrit la personne.

## 2. Le modèle visé

```
personnes            prénom, nom, email, téléphone, langue, adresse,
                     date_naissance, desinscrit
structure_contacts   + personne_id    → devient la liaison N↔N,
                                        et ne garde que le RÔLE
utilisateurs         + personne_id    0..1 ↔ 1
employes             + personne_id    0..1 ↔ 1
```

## 3. La contrainte dure

⚠️ **Les identifiants de `structure_contacts` ne peuvent pas changer.**

Le lien de désinscription des e-mails déjà partis porte `structure_id` +
`contact_id` + une signature calculée sur les deux
(`route_desinscription()`, `desinscription_signature()`). Renuméroter, c'est
transformer tous ces liens en « Lien invalide » — sur des messages envoyés il y
a des mois, sans aucun moyen de les rattraper. Quatre autres tables pointent ce
`contact_id` : `mailing_file_attente`, `mailing_envois`,
`structure_message_brouillons`, `evenement_feuille`.

**Conséquence : migration additive seulement.** Une personne par contact
existant, aucun regroupement automatique, les `id` préservés. Avec ~2 900
lignes, des boîtes partagées (`info@…`) et des homonymes, un dédoublonnage
automatique fabriquerait des fusions fausses et silencieuses — et une fusion
fausse est bien plus coûteuse qu'un doublon.

⚠️ Pour recréer `structure_contacts` avec un schéma allégé, suivre la règle de
`CLAUDE.md` : table temporaire `x_new`, copie, `DROP`, `RENAME` — **jamais**
`RENAME TO x_old` puis recréation, qui laisse des clés étrangères cassées. Les
quatre tables ci-dessus le vérifieront sans pitié
(`tests/migrations_test.php`, `PRAGMA foreign_key_check`).

## 4. Ce que ça apporte

- Une **quittance** peut être faite à une personne comme à une structure
  (`SPEC_PIECES.md § 9`).
- Un employé, un utilisateur et un contact qui sont la même personne le disent.
- Un contact qui change de structure garde son identité et son historique.
- L'annuaire devient dédoublonnable, un jour, sans refonte.

## 5. Ce qui a été tranché

| Question | Réponse |
| --- | --- |
| Portée de la désinscription | **Par personne, partout.** Le drapeau passe sur `personnes` : qui se désinscrit ne reçoit plus rien, même en tant que contact d'une autre structure. C'est ce qu'on croit demander en cliquant. ⚠️ Changement de comportement à la migration : des gens aujourd'hui désinscrits d'un côté et joignables de l'autre cesseront de l'être — à vérifier avant, pour savoir combien |
| Doublons de l'annuaire | **Un écran de fusion manuelle, plus tard.** La migration crée une personne par contact, sans rien regrouper. Un écran « Doublons probables » (même e-mail, ou même nom + prénom) proposera des fusions à valider une par une, dans un second temps |
| Personnes physiques saisies comme structures (les « Freelance ») | **On les laisse où elles sont.** Ces fiches portent déjà des événements, des campagnes, des factures ; les déplacer obligerait tout ce qui pointe une structure à accepter aussi une personne. Une quittance à un freelance se fait à la structure |
| Ordre des chantiers | **Après les étapes 1 à 4 de `SPEC_PIECES.md`**, avant les quittances |

## 6. À cadrer au moment d'attaquer

- **L'adresse postale** : aujourd'hui sur `employes` seulement. Une quittance en
  a besoin. Monte-t-elle sur `personnes` (et l'employé la lit de là), ou reste-t-elle
  où elle est ?
- Les **écrans** : y a-t-il une liste des personnes, ou n'existent-elles qu'au
  travers des structures, des employés et des comptes ?
- Le **module** et les **droits** : `coeur`, `booking`, ou un module à part ? Une
  personne traverse le booking, les salaires et les comptes — c'est le premier
  objet du modèle dans ce cas, et `SPEC_PERMISSIONS.md` devra le dire.
- L'`historique` : suit-il la personne ou le lien personne × structure ?
