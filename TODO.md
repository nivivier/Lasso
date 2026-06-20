# Correction UI ✅
- [x] améliorer l’apparence des champs dans la page de taux (suffixe « % », champs cadrés à droite, largeur réduite)
- [x] dans la liste des fiches de salaire, mettre la colonne brut en mauve, retirer la colonne « déductions » et ajouter la colonne « coût employeur », en gras en noir à droite

# Fonction email ✅ (Lot O)
- [x] Depuis la vue Fiche de salaire, bouton pour envoyer par email une fiche de salaire (format HTML), il faut pouvoir l’envoyer par email à l’email de l’employé (fonction mail(), utilisation de l’« email d’expéditeur pour les envois automatiques » entré dans les paramètres)
- [x] Dans la liste des fiches de salaire (vue principale ou vue dans la fiche employée), on rajoute une colonne « Envoyée », qui comporte une checkmark quand l’envoi a déjà eu lieu au moins une fois.
- En local (127.0.0.1 / localhost), l’envoi est journalisé dans `data/emails_envoyes.log` au lieu d’être envoyé ; en production, `mail()` envoie réellement.
- ⚠️ Avant mise en production : renseigner dans Paramètres → Employeur un « e-mail d’expéditeur » sur un domaine hébergé chez votre hébergeur (SPF/DKIM) pour éviter le spam.
