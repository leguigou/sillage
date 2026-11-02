# Saisies en modale et actions de la liste

## Liste des tickets

L’échéance et l’estimation sont affichées en lecture seule, avec dates françaises et heures formatées. Elles restent triables et masquables comme les autres colonnes. Leur modification se fait dans **Modifier le ticket** ; la barre d’avancement et le reste à faire utilisent toujours l’estimation totale et le pourcentage d’avancement.

Les trois points ouvrent un menu : ouvrir, modifier, commenter, copier le lien, historique et développement. Les liens conservent la vue d’origine, les filtres et le tri. Modifier et commenter ouvrent la fiche et sa modale existante ; aucune seconde copie du formulaire métier n’est créée dans la liste. Les comptes en lecture seule ne reçoivent pas les actions d’édition ou de commentaire ; les contrôles serveur restent inchangés.

Le menu reste visible hors du conteneur défilant grâce à la couche supérieure des popovers, avec position bornée à la fenêtre. Flèches, Début/Fin et Échap permettent de l’utiliser au clavier. La copie utilise le lien canonique, sans y ajouter les filtres de la liste.

La colonne **Création** permet le tri croissant ou décroissant ; le sens actif est annoncé par `aria-sort`. Les modifications groupées proposent une liste selon le champ : états communs autorisés par les tickets sélectionnés, priorités, noms des personnes avec « Non assigné », ou blocage oui/non. L’édition rapide réactualise les choix d’état utilisés par la sélection. Le serveur garde la validation de chaque ticket et l’écriture groupée atomique. Les filtres et le tri sont conservés au retour.

## Rapports personnels

**Mes rapports → Créer un rapport** conserve les filtres appliqués, le tri et son sens, le regroupement, la hiérarchie, les colonnes cochées et leur ordre. Le résumé reste obligatoire ; l’avancement et le reste à faire peuvent être affichés sous celui-ci. Les flèches déplacent les colonnes, sans glisser-déposer obligatoire. Le sélecteur de colonnes possède son propre défilement ; sur petit écran, la configuration passe sur une colonne.

Les rapports sont enregistrés dans `saved_views`, liés au compte et au projet, et disponibles sur les autres navigateurs après connexion. Cliquer un rapport applique sa présentation ; **Modifier mon rapport** permet de le renommer et de mettre à jour ses options. **Créer une copie** enregistre un rapport séparé. Sauvegarde, copie et suppression utilisent AJAX, sans déplacement de la page ; **Afficher ce rapport** applique explicitement le rapport enregistré. La suppression ne touche pas les tickets.

Les filtres se modifient depuis **Filtrer**, avant l’enregistrement. Les options d’une vue du navigateur restent disponibles, mais la configuration d’un rapport ouvert est prioritaire sur les anciennes préférences locales. Les filtres, la recherche, le tri et la navigation conservent le contexte du rapport. La configuration est validée par `ListLayout` et ne conserve ni pagination, ni paramètres de transport. L’API existante de vues reste compatible. Aucun nouvel objet de base de données n’est nécessaire.

Un rapport d’un autre compte ne peut être chargé, modifié ou supprimé. Une empreinte de révision protège l’édition depuis plusieurs onglets. L’ordre de colonnes est une valeur du formulaire suivie par le gestionnaire de modales : abandonner restaure l’ordre et les cases ; une sauvegarde réussie actualise le brouillon de référence. Les erreurs conservent la saisie.

## Durées

`Duration::label` possède la présentation PHP des durées ; `View::hours` la réutilise dans les vues. Les nouvelles légendes d’historique utilisent la même présentation. Moins d’une heure : minutes ; au-delà : heures et minutes, par exemple `0,70 h → 42 min`, `1,7 h → 1 h 42 min`. Les minutes sont arrondies à la minute la plus proche ; une durée positive arrondie à zéro reste « < 1 min ». Zéro et absence restent distincts. La mise à jour rapide de la liste applique la même convention côté navigateur.

Stockage, saisie, exports et API numériques restent en heures décimales. Le reste à faire reste calculé sur l’estimation totale multipliée par la part d’avancement restante ; les temps saisis n’en sont pas déduits. Les anciennes légendes immuables de l’historique sont conservées.

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
