# Saisies en modale et actions de la liste

## Ordre manuel du tableau

Le tableau utilise toujours le rang manuel des tickets, indépendamment des tris mémorisés ou transmis par la liste et les rapports. Les filtres continuent de s’appliquer. Une navigation vers le tableau ne réécrit pas la préférence de tri de la liste.

Pendant un glisser-déposer, un emplacement de la hauteur de la carte s’insère avant ou après les cartes survolées, selon le milieu de chaque carte. Les autres cartes laissent cette place ; la requête utilise le voisin suivant cet emplacement, et non la carte sous le pointeur au relâchement. Le déplacement peut changer la colonne d’état à l’intérieur d’un même groupe. Les colonnes repliées et les autres groupes ne sont pas des cibles. Les bords de la zone des cartes permettent son défilement pendant le déplacement.

Un abandon restaure l’affichage ; une sauvegarde refusée remet la carte à sa place. Une position inchangée n’envoie aucune modification. L’enregistrement conserve les contrôles existants de version, CSRF, projet et état. Le gestionnaire dédié est `public/assets/board-drag.js`.

`SillageDialogs.commit(dialog)` valide la référence de toute la modale ; `commit(form)` valide seulement les contrôles du formulaire sauvegardé. Utiliser cette seconde forme lorsque plusieurs connexions ou réglages indépendants partagent une modale, afin de conserver la protection des brouillons voisins.

## Liste des tickets

**Votre espace, votre lecture** permet de modifier l’ordre des colonnes avec les flèches Monter/Descendre. Les en-têtes et les cellules sont réagencés ensemble ; sélection et actions restent aux extrémités. Le résumé peut être déplacé mais reste toujours visible ; l’avancement demeure sous le résumé. Le sélecteur possède son propre défilement et indique aussi les colonnes masquées.

L’ordre est mémorisé dans le navigateur par utilisateur, projet et rapport. La révision d’un rapport fait partie du contexte : sa nouvelle définition enregistrée est donc reprise au prochain chargement. Créer ou modifier un rapport depuis la vue réagencée reprend l’ordre affiché dans son formulaire, puis dans les paramètres enregistrés.

**Date de début** et **Échéance** sont proposées dans les informations affichées et dans les colonnes des rapports. Les deux dates sont en lecture seule, au format jour/mois/année ; elles restent modifiables depuis le ticket. Le tri utilise les valeurs de date, avec un tiret pour une date absente. Les rapports déjà enregistrés conservent leur sélection de colonnes.

Les sélecteurs de responsable et de priorité utilisent le même traitement compact et arrondi que l’état. Les options natives possèdent explicitement les couleurs de texte et de fond du thème pour rester lisibles en clair comme en sombre.

Une icône GitHub ou Bitbucket à côté du résumé indique une branche liée manuellement ou une branche active reconnue automatiquement. Chaque fournisseur apparaît une fois ; les deux peuvent être affichés. Cliquer ouvre directement l’onglet Développement du ticket, avec le contexte de retour à la liste. Une connexion du projet sans branche associée ne suffit pas à afficher l’icône ; les dépôts détachés sont exclus.

La colonne **Modification** affiche la dernière modification du ticket. Dans le fuseau du navigateur : « Aujourd’hui », « Hier », puis « Il y a 2 jours » jusqu’à six jours ; à partir de sept jours, la date complète est affichée. Le survol précise la date et l’heure. Le tri utilise l’horodatage réel et les modifications rapides actualisent le libellé. La colonne est disponible dans les rapports personnels et les options de colonnes ; les rapports déjà enregistrés conservent leur sélection de colonnes.

À droite de **Résumé**, une seule flèche **Avancement** indique le sens du tri actif ; cliquer l’inverse. Les filtres, le projet et le rapport sont conservés et la pagination revient à la première page. Ce tri est également proposé dans les rapports personnels et mémorisé avec les préférences de filtres. Les regroupements et la hiérarchie conservent leur structure.

Les rapports acceptent trois critères distincts, dans l’ordre : **Trier par**, **Puis par (2)** et **Puis par (3)**. Chaque critère possède son propre sens ; « Aucun critère supplémentaire » retire un niveau. Les critères suivants départagent les égalités, puis l’identifiant stabilise l’ordre. Modifier le critère principal depuis un autre en-tête de la liste repart sur un tri simple ; inverser le même critère conserve les tris secondaires. Les anciens rapports à un critère restent valides.

Les durées lisibles sont arrondies au palier supérieur de cinq minutes : 42 min → 45 min, 16 h 48 min → 16 h 50 min. Les valeurs stockées, calculs et API numériques restent précis. Les paliers exacts restent identiques et zéro reste zéro ; une durée positive très petite affiche 5 min.

L’échéance et l’estimation sont affichées en lecture seule, avec dates françaises et heures formatées. Elles restent triables et masquables comme les autres colonnes. Leur modification se fait dans **Modifier le ticket** ; la barre d’avancement et le reste à faire utilisent toujours l’estimation totale et le pourcentage d’avancement.

Les trois points ouvrent un menu : ouvrir, modifier, commenter, copier le lien, historique et développement. Les liens conservent la vue d’origine, les filtres et le tri. Modifier et commenter ouvrent la fiche et sa modale existante ; aucune seconde copie du formulaire métier n’est créée dans la liste. Les comptes en lecture seule ne reçoivent pas les actions d’édition ou de commentaire ; les contrôles serveur restent inchangés.

Le menu reste visible hors du conteneur défilant grâce à la couche supérieure des popovers, avec position bornée à la fenêtre. Flèches, Début/Fin et Échap permettent de l’utiliser au clavier. La copie utilise le lien canonique, sans y ajouter les filtres de la liste.

La colonne **Création** permet le tri croissant ou décroissant ; le sens actif est annoncé par `aria-sort`. Les modifications groupées proposent une liste selon le champ : états communs autorisés par les tickets sélectionnés, priorités, noms des personnes avec « Non assigné », ou blocage oui/non. L’édition rapide réactualise les choix d’état utilisés par la sélection. Le serveur garde la validation de chaque ticket et l’écriture groupée atomique. Les filtres et le tri sont conservés au retour.

## Fil des modifications

L’onglet Historique d’un ticket présente un fil chronologique, du plus récent au plus ancien, avec avatar, auteur, action et date. Les événements sont regroupés par jour dans le fuseau du navigateur et conservent les valeurs avant/après. Les descriptions longues restent dépliables. Les ajouts, corrections et suppressions de temps ainsi que validations restent visibles.

La création est exclue de ce fil avant le calcul du total et des pages. L’événement d’audit enregistré et la lecture MCP existante restent conservés. Les avatars sont préparés en lot et les dates sont réactualisées lors des sauvegardes AJAX du suivi.

## Identité du menu

Le bandeau supérieur reprend le nom et le logo ou symbole configurés de l’entreprise, dans le style de l’ancien mot-symbole Sillage. Le bloc entreprise séparé et son cadenas sont retirés. Le bouton de repli conserve le logo lorsque le nom est masqué. Les noms longs sont tronqués visuellement et restent accessibles dans le titre du lien. Une sauvegarde des paramètres d’identité actualise aussi ce bandeau sans rechargement.

## Rapports personnels

Le bouton **+** reste disponible pour créer un autre rapport. Le **stylo**, placé juste après le rapport actif, modifie uniquement celui-ci. Création et édition utilisent des instances indépendantes du même formulaire : le nouveau nom est vide, l’identité est remise à zéro à chaque nouvelle création et les brouillons restent séparés. Les filtres et colonnes courants sont repris pour préparer le nouveau rapport ; le rapport actif reste inchangé tant qu’il n’est pas explicitement modifié.

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
