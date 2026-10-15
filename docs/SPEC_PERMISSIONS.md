# Spécification — Droits d'accès par module (lecture / écriture)

Statut : **module en service**. Ce document est le besoin cadré avec l'utilisateur
avant l'implémentation, gardé pour le « pourquoi » d'un modèle de données ou d'une
règle métier — le code y renvoie. Il n'a pas suivi toutes les évolutions depuis :
**en cas de désaccord, le code fait foi**, et `README.md` décrit ce que
l'application fait aujourd'hui.

## 1. Objectif

Aujourd'hui, `utilisateurs` n'a aucune notion de rôle : tout compte connecté a un accès
total à toute l'application (cf. commentaire actuel de `route_utilisateurs()` : *« Tous les
comptes ont les mêmes droits »*). On introduit des **droits par module**, à deux niveaux
(**lecture** / **écriture**), par utilisateur — dans l'esprit des modules déjà
activables/désactivables globalement (`lib/modules.php`), mais appliqués individuellement.

Contrainte de départ posée par l'utilisateur : il doit toujours exister **au moins un
administrateur**, défini comme un utilisateur ayant le droit **écriture sur le module
Cœur** (paramètres, apparence, mises à jour, **et gestion des comptes/permissions**).
« Admin » n'est donc pas un rôle nommé à part — c'est une conséquence directe du modèle
lecture/écriture appliqué au module Cœur, qui devient lui-même un module comme les autres
au sens des permissions (il l'est déjà partiellement : `MODULE_COEUR` existe dans
`lib/modules.php`, toujours actif, mais sans dimension par-utilisateur pour l'instant).

## 2. Principe général

- Une portée par module (`carnet`, `salaires`, `compta`, `analytique`, `pieces`,
  `facturation`, `evenements`, `booking`, `fonds`, `mailing`) + `coeur`.
- Pour chaque paire (utilisateur, module), un niveau : **aucun** (implicite, pas de
  droit accordé), **lecture**, ou **écriture**. Écriture inclut lecture (pas besoin de
  deux droits distincts pour un même module).
- Un module désactivé globalement (`module_actif()` = faux) reste invisible pour tout le
  monde, indépendamment des permissions individuelles — les deux mécanismes se combinent
  (`module actif ET utilisateur autorisé`), ils ne se remplacent pas.
- Sept modules dépendent d'un autre (`requires`) : `analytique` et `pieces` de
  `compta`, `mailing` (« Envois groupés ») de `booking`, et `facturation`,
  `evenements`, `booking`, `fonds` du **`carnet`**.
- ⚠️ **`requires` a deux sens, et un seul concerne les droits.**
  1. L'**activation** : un module dont la dépendance est éteinte s'éteint aussi
     (`set_modules_actifs()`). Vrai pour tous, sans exception.
  2. Le **plafond de droits** : un module ne dépasse pas le niveau de sa
     dépendance. Vrai seulement d'un **sous-module** — celui qui prolonge les
     DONNÉES de son parent : l'analytique ventile les écritures de la
     comptabilité, les envois groupés écrivent aux contacts du booking. Écrire
     chez l'enfant, c'est écrire chez le parent.
  Les modules qui ne font que **se servir** de leur dépendance portent
  `plafonne => false` : les pièces comptables et les quatre qui travaillent sur
  le carnet. Déposer un justificatif n'est pas tenir les comptes — c'est même
  toute la raison d'être du module —, et émettre une facture n'est pas écrire
  le carnet d'adresses.
  **Ce que cela corrige** : le clamp rabattait « `pieces` = écriture +
  `compta` = lecture » sur « `pieces` = lecture », en silence et à
  l'enregistrement. Le compte qui photographie ses tickets ne pouvait rien
  déposer, et le module séparé — dont c'est l'unique justification — était
  inconfigurable. `tests/permissions_test.php` l'interdit désormais.
- `analytique` dépend de `compta` (`requires` existant) : logiquement, un droit sur
  `analytique` ne devrait pas dépasser celui sur `compta` (ex. écriture analytique sans
  aucun accès compta n'a pas de sens) — à valider, voir §10.

## 3. Modèle de données

Pas de nouvelle colonne sur `utilisateurs` (pas de colonne `role` — le statut admin est
dérivé, voir §1). Une seule nouvelle table.

### `utilisateur_permissions`

| champ | notes |
|---|---|
| `id` | |
| `utilisateur_id` | FK → `utilisateurs`, `ON DELETE CASCADE` |
| `module` | identifiant texte : `coeur`, `carnet`, `salaires`, `compta`, `analytique`, `pieces`, `facturation`, `evenements`, `booking`, `fonds`, `mailing` |
| `niveau` | `lecture` / `ecriture` |
| | `UNIQUE(utilisateur_id, module)` |

Absence de ligne pour une paire (utilisateur, module) = aucun accès. Table volontairement
« creuse » (pas de ligne `aucun` explicite) : plus simple à raisonner et à faire évoluer
(ajouter un module = rien à backfiller pour les droits « aucun »).

## 4. Garde-fou « au moins un admin »

- **Premier compte** créé (`route_installation`, première installation) : reçoit
  automatiquement écriture sur `coeur` + sur tous les modules actifs — comportement
  identique à aujourd'hui, aucune régression pour une installation neuve.
- **Retrait de droits** : impossible de retirer l'écriture sur `coeur` au dernier
  utilisateur qui la détient (contrôle serveur : `COUNT(*)` d'utilisateurs actifs avec
  `coeur`/`ecriture` doit rester ≥ 1 après l'opération), qu'il s'agisse de se
  l'auto-retirer ou de modifier un autre compte.
- **Suppression de compte** : le garde-fou existant « impossible de supprimer le dernier
  compte » est étendu en « impossible de supprimer le dernier admin » — on peut
  désormais avoir plusieurs comptes non-admin, mais jamais zéro admin.
- **Gestion des comptes et des permissions** (créer/supprimer un compte, modifier les
  droits d'autrui) réservée à l'écriture sur `coeur` — même un Cœur en lecture (si ce
  niveau existe, voir §10) ne peut pas gérer les comptes.
- **« Mon compte »** (modifier son propre prénom/nom/e-mail/mot de passe) reste
  accessible à tout utilisateur connecté quel que soit son niveau sur `coeur` — ce n'est
  pas une action de gestion des comptes au sens large, juste du self-service.

## 5. Sémantique lecture / écriture

- **Lecture** : accès aux pages de consultation du module — listes, fiches, filtres,
  recherche, impression, exports (CSV/PDF). Aucune création/modification/suppression :
  les actions de mutation (formulaires POST, boutons d'action groupée, suppression)
  sont masquées côté vue **et** bloquées côté serveur (même en cas d'appel direct de
  l'URL POST).
- **Écriture** : accès complet, identique au comportement actuel du module — pas de
  sous-niveaux (ex. pas de distinction « écriture sans suppression » en v1).
- **Aucun droit** : le module est invisible (lien de sidebar masqué) et inaccessible
  (redirection/erreur si URL directe) — même expérience qu'un module désactivé
  globalement, mais individuelle.

## 6. Cas particulier — comptes bancaires (partagés compta/facturation)

`comptes_bancaires` est utilisé par `compta` et `facturation` (déjà routé sous condition
`module_actif('compta') || module_actif('facturation')` dans `index.php`). Règle
proposée : accessible dès que l'utilisateur a écriture sur **au moins un** des deux
modules — cohérent avec le gate global existant. À confirmer, voir §10.

## 6 bis. Cas particulier — les pièces comptables

`pieces` est un sous-module de `compta` (`requires`), et il existe pour UNE
raison : pouvoir donner le **dépôt** d'un justificatif sans la **lecture** des
comptes. Qui photographie ses tickets reçoit l'écriture sur `pieces` et rien
sur `compta`.

⚠️ **C'est le seul endroit où un onglet survit à son module parent.** Le
bandeau de la Comptabilité s'ouvre dès qu'on a `compta` **OU** `pieces`
(`nav_groupes()`, lib/modules.php) ; les autres onglets restent, eux, derrière
la lecture de `compta`. Sans cette exception, un compte qui n'a que le dépôt
n'aurait aucune porte vers son écran.

Une conséquence à connaître : `route_piece_rapprocher()` touche une écriture,
et le dispatcher ne vérifie que le module sous lequel la route est déclarée —
`pieces`. Elle revérifie donc `module_accessible('compta')` dans son corps.
C'est l'une des rares exceptions légitimes à « une route ne revérifie jamais
ses droits » : la route est déclarée sous un module et agit sur un autre.

## 6 ter. Les personnes et les structures — le carnet d'adresses

L'écran « Personnes » a d'abord vécu sous `coeur` (Paramètres → Application),
réservé aux administrateurs. Non par prudence, mais faute de propriétaire : une
personne **traverse** le booking, les salaires et les comptes, et la mettre sous
l'un d'eux aurait rendu un employé visible à qui n'a que le booking. Les
structures posaient le même problème sous une autre forme — routées
« `facturation` OU `booking` », elles étaient à deux modules et donc à aucun, et
les deux autres qui s'en servent (événements, recherche de fonds) affichaient un
onglet « Structures » que leurs comptes n'avaient pas le droit d'ouvrir.

**Le module `carnet` est la réponse** : l'identité a son propriétaire, et les
quatre modules qui s'en servent le `requires`. Trois conséquences à retenir :

1. `?p=structures`, `?p=structure`, `?p=personnes`, `?p=personne` et les fusions
   sont rattachées au seul `carnet`. Avoir le booking ne donne plus accès aux
   structures : il faut le carnet, que la dépendance garantit à l'activation et
   que `migration_102` a accordé à tous ceux qui l'avaient de fait.
2. Les écrans propres au **CRM** — contacts, notes, tags, lieux, messages,
   import — restent au `booking`. Ils parlent de démarchage, pas d'identité, et
   la fiche structure ne les montre qu'avec lui (`$avecAside`,
   `views/structure.php`). La recherche unifiée suit la même ligne : la source
   `structures` est sous `carnet`, la source `contacts` sous `booking`.
3. L'onglet **Employés** figure dans le carnet, mais derrière le droit
   `salaires` : la fiche d'un employé porte son n° AVS, son taux horaire et son
   impôt à la source. Déplacer un onglet ne doit jamais élargir qui lit ce qu'il
   ouvre — c'est une porte de plus vers le même écran, pas une porte plus large.

## 7. Impact sur l'architecture existante

- **Nouveaux helpers** (`lib/modules.php`, étendu plutôt qu'un nouveau fichier — la
  notion est proche des modules existants) :
  - `permission_utilisateur(int $utilisateurId, string $module): ?string`
  - `peut_lire(string $module): bool`, `peut_ecrire(string $module): bool` (utilisateur
    courant, via `current_user()`)
  - `require_lecture(string $module): void`, `require_ecriture(string $module): void` —
    même esprit que `require_login()` (redirection/403 si insuffisant)
  - `est_admin(): bool` = `peut_ecrire('coeur')`
- **Sidebar** (`views/layout.php`) : chaque condition `module_actif('xxx')` devient
  `module_actif('xxx') && peut_lire('xxx')`. Même chose pour les onglets Paramètres
  (`views/_param_tabs.php`) vis-à-vis de `coeur`.
- **Dispatch** (`index.php`) : ajouter une vérification `peut_lire($module)` avant
  d'appeler la route associée — bloque l'accès direct par URL à un module non autorisé,
  en plus du masquage sidebar. Un seul point de contrôle pour le *minimum* (lecture).
- **Actions d'écriture** : le contrôle fin doit rester **dans** chaque fonction
  `route_*` qui traite un POST (beaucoup de routes mélangent affichage GET et action(s)
  POST dans la même fonction, ex. `route_evenements()`) — appel à
  `require_ecriture('module')` juste avant `check_csrf()`, sur le modèle exact de
  `require_login()` aujourd'hui. C'est le chantier le plus long : env. 80 fonctions
  `route_*` à parcourir et modifier, module par module, testable indépendamment. C'est
  le point qui détermine le plus l'effort d'implémentation, voir §10.
- **Vues** : masquer/désactiver les boutons d'action (Nouveau, Modifier, Supprimer,
  actions groupées…) en lecture seule — même logique que les gates déjà utilisés pour
  `module_actif()` dans les vues existantes.

## 8. UI/UX

- Page **Comptes** (`views/utilisateurs.php`) : une ligne par compte, et les droits
  dans le panneau que le crayon ouvre.
  ⚠️ **Ce n'est plus une matrice.** Elle l'a été — une colonne par module, un
  sélecteur de trois icônes par cellule —, et elle a tenu jusqu'à huit modules.
  À onze, les onze colonnes de 70 px voyaient leurs en-têtes se chevaucher dès
  qu'on n'était pas en plein écran, pour deux comptes qu'on règle deux fois
  l'an. La liste ne porte donc plus qu'un **résumé** (« Administration », ou
  « N modules » avec le détail en infobulle), et le panneau d'édition pose
  **une ligne par module** : son nom en toutes lettres, sa description, et les
  trois niveaux écrits. Cette forme-là ne bougera pas au douzième module.
- Un raccourci **« Tout mettre sur »** (aucun accès / lecture / écriture) en tête
  des droits : onze modules au même niveau, c'est onze clics pour un geste qui
  n'en vaut qu'un. Il ne fait que poser les boutons — rien n'est enregistré
  avant « Enregistrer », comme pour un clic module par module.
- Badge **« Administration »** dans la colonne Droits si écriture sur `coeur` (en
  plus du badge « vous » existant).
- Message d'erreur explicite si tentative de retirer le dernier accès admin — même
  registre que les erreurs `err=self` / `err=last` déjà en place sur la suppression de
  compte.

## 9. Migration & compatibilité

- `migration_34` (prochain numéro libre) : création de `utilisateur_permissions`.
- **Backfill dans la même migration** : tous les comptes existants reçoivent écriture
  sur `coeur` + sur tous les modules actuellement actifs (`modules_actifs()`) — préserve
  exactement le comportement actuel, aucune régression surprise lors du déploiement.
- Après coup, un **nouveau** compte créé par un admin démarre **sans aucune
  permission** — c'est un changement de comportement par rapport à aujourd'hui (où un
  nouveau compte a tout par défaut) : l'admin doit explicitement lui attribuer des
  droits à la création. Cohérent avec l'esprit du principe du moindre privilège.

## 10. Points ouverts à trancher avant/pendant l'implémentation

1. **Cœur « lecture »** — a-t-il un usage réel (consulter les paramètres sans les
   modifier, sans accès à la gestion des comptes) ? Ou faut-il simplifier Cœur en
   binaire (admin / pas d'accès aux réglages), et garder lecture/écriture uniquement
   pour les cinq modules métier ?
2. **Comptes bancaires partagés** : écriture sur `compta` OU `facturation` suffit
   (§6) — à confirmer, ou préférer l'exigence des deux.
3. **Dépendance `analytique` → `compta`** (§2) : faut-il *forcer* le niveau
   `analytique` à ne jamais dépasser celui de `compta` (comme le fait déjà
   `set_modules_actifs()` pour l'activation globale), ou laisser les deux droits
   totalement indépendants pour plus de souplesse ?
4. **Effort d'implémentation** (§7) : valider l'approche « contrôle grossier en lecture
   au dispatch + contrôle fin en écriture dans chaque route POST » plutôt qu'une
   alternative plus centralisée mais plus lourde à mettre en place vu la structure
   actuelle du code (fonctions `route_*` mixant GET/POST).
5. Faut-il documenter dans le `CHANGELOG.md` le changement de comportement pour les
   nouveaux comptes (plus aucun droit par défaut, §9), pour que l'utilisateur qui
   administre l'appli ne soit pas surpris ?

## 11. Hors périmètre v1 (explicitement écarté ou différé)

- Pas de rôles nommés personnalisés au-delà de la distinction implicite admin (écriture
  `coeur`) / non-admin — uniquement une matrice module × niveau par utilisateur, pas de
  système de rôles à créer/nommer/dupliquer.
- Pas de permissions au niveau de l'enregistrement (ex. restreindre un employé à la
  consultation de ses seules fiches).
- Pas de journal d'audit des changements de permissions (qui a changé quoi, quand).
- Pas d'invitation par e-mail — création de compte manuelle par un admin, mot de passe
  défini directement (comme aujourd'hui).
- Pas de sous-niveaux dans « écriture » (ex. écriture sans suppression).

## 12. Structure de code envisagée (à l'image des modules existants)

- `lib/modules.php` — étendu avec les helpers de permission (§7), plutôt qu'un nouveau
  fichier séparé (évite de dupliquer la liste des modules).
- Migration : nouvelle entrée `34 => 'migration_34'` dans `lib/db.php`.
- `views/utilisateurs.php` — matrice de permissions par utilisateur.
- `lib/routes.php` — nouvelle route POST dédiée (ex. `route_compte_permissions`) pour la
  mise à jour de la matrice, à côté de `route_utilisateurs()`/`route_utilisateur_supprimer()`.
- `require_ecriture('module')` ajouté en tête de chaque bloc de mutation dans les
  fichiers `lib/routes*.php` existants, module par module.
- Tests : `tests/permissions_test.php` — garde-fou dernier admin, résolution
  lecture/écriture, comportement de `require_lecture()`/`require_ecriture()`.
