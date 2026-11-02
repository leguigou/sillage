# Saisies en modale et actions de la liste

## Liste des tickets

L’échéance et l’estimation sont affichées en lecture seule, avec dates françaises et heures formatées. Elles restent triables et masquables comme les autres colonnes. Leur modification se fait dans **Modifier le ticket** ; la barre d’avancement et le reste à faire utilisent toujours l’estimation totale et le pourcentage d’avancement.

Les trois points ouvrent un menu : ouvrir, modifier, commenter, copier le lien, historique et développement. Les liens conservent la vue d’origine, les filtres et le tri. Modifier et commenter ouvrent la fiche et sa modale existante ; aucune seconde copie du formulaire métier n’est créée dans la liste. Les comptes en lecture seule ne reçoivent pas les actions d’édition ou de commentaire ; les contrôles serveur restent inchangés.

Le menu reste visible hors du conteneur défilant grâce à la couche supérieure des popovers, avec position bornée à la fenêtre. Flèches, Début/Fin et Échap permettent de l’utiliser au clavier. La copie utilise le lien canonique, sans y ajouter les filtres de la liste.

## Fermeture des modales

À l’ouverture, `public/assets/dialogs.js` prend un instantané des champs éditables, cases, choix, fichiers et contenus enrichis. Il compare les valeurs actuelles au moment de fermer : revenir aux valeurs initiales supprime donc l’avertissement. Les valeurs cachées de transport, champs en lecture seule et états d’affichage ne déclenchent pas de confirmation.

- Sans changement : clic extérieur, croix ou Échap ferme directement.
- Avec une saisie non enregistrée : **Continuer l’édition** conserve les valeurs et **Abandonner les modifications** les rétablit avant de fermer.
- Fermer la confirmation ou utiliser Échap équivaut à continuer l’édition.
- Un glissement commencé dans la modale et terminé à l’extérieur n’est pas un clic d’abandon.
- Pendant une opération signalée occupée, la fermeture est bloquée. Les succès AJAX ferment explicitement sans avertissement ; une erreur conserve la saisie.

Les changements de préférences enregistrés immédiatement mettent à jour leur référence : fermer le panneau ne demande pas d’abandonner une préférence déjà conservée. L’abandon ne revient pas sur une opération déjà enregistrée au serveur, comme un fichier envoyé ou un dépôt connecté. Il protège les brouillons locaux ; il ne remplace pas une restauration métier.

Les confirmations d’actions et les anciennes saisies `prompt` utilisent des modales du site, y compris l’édition rapide du résumé et l’ajout d’un lien dans une conversation. Le choix d’une action destructive reste soumis à sa confirmation. Les contrôles de validation HTML, le sélecteur de fichiers du système et la navigation du navigateur gardent leur fonctionnement normal. Recharger/fermer l’onglet ou quitter via les commandes du navigateur n’est pas une fermeture de modale gérée par ce composant.

## Intégration

Utiliser `window.SillageDialogs` :

- `show(dialog)` après avoir rempli les valeurs initiales ; il capture la référence et place le focus dans le formulaire.
- `requestClose(dialog)` pour une demande utilisateur de fermeture.
- `complete(dialog)` uniquement après une sauvegarde ou une insertion réussie.
- `commit(dialog)` pour des champs enregistrés immédiatement dans une modale qui reste ouverte.
- `confirm(message)` et `prompt(options)` renvoient une promesse : attendre le résultat avant d’exécuter l’action.

Les attributs `data-close` et `data-confirm` sont traités centralement, y compris pour les formulaires insérés à la demande. Après un abandon, `sillage:dialog-discarded` permet au producteur de réconcilier ses aperçus et sélections internes. Les brouillons restent uniquement en mémoire dans l’onglet ; ils ne sont ni journalisés ni copiés dans le stockage du navigateur.
