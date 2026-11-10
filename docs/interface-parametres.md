# Cohérence de l’interface des paramètres

La page conserve ses onglets et ses sauvegardes AJAX. Ses composants visuels communs sont définis dans `public/assets/settings.css`, chargé uniquement sur les paramètres, après la feuille globale. Les mises en page propres à un fournisseur ou à un tableau ne doivent pas redéfinir les boutons et pieds de formulaire.

- **Boutons** : hauteur minimale de 40 px, texte de 12 px, mêmes bordures et rayons. Le texte peut revenir à la ligne si l’écran est trop étroit. Les sélecteurs de thème et palettes restent des contrôles spécialisés.
- **Actions principales** : bouton rempli pour créer, générer ou enregistrer un formulaire. `.settings-actions` place les actions en bas à gauche, après un séparateur. Les actions secondaires utilisent `.button.subtle`, les suppressions/retraits/révocations `.button.settings-danger`, avec bordure rouge et fond transparent.
- **Formulaires** : `.settings-form` donne une largeur de saisie maximale de 760 px et un espacement régulier. `.settings-form-wide` permet aux matrices de notifications, transitions et listes de pictogrammes d’utiliser toute la carte. Les champs conservent leur largeur disponible dans les petites cartes et dialogues.
- **Listes et cartes** : `.settings-card` fournit le fond, la bordure, les angles et le remplissage communs aux équipes, fournisseurs et connexions. `.settings-row-actions` réunit les opérations propres à une ligne ou une carte, sans les étirer.
- **Ajouts et états** : `.settings-toolbar` place une action d’ajout sous l’introduction, à gauche. `.settings-empty` et `.settings-badges` présentent les états vides et les compteurs avec une même mise en forme.

Les libellés, aides, titres, champs et boutons de copie utilisent également les règles communes. Les variables `--settings-field-width` et `--settings-control-height` sont définies pour la page et ses dialogues. Éviter d’introduire de nouvelles classes propres à une fonctionnalité pour reproduire ces composants.

Dans l’onglet **Projets**, chaque ligne distingue l’ouverture du projet et son bouton **Modifier**, réservé aux administrateurs. Tous les accès à l’édition, y compris la roue du projet courant, utilisent un formulaire chargé à la demande par `GET /projects/{id}/settings`. Le serveur fournit les valeurs persistées du projet demandé ; un ancien formulaire préchargé ne doit pas servir à en éditer un autre. L’enregistrement AJAX actualise son nom, sa description et son identité dans l’annuaire, le menu et le fil d’Ariane concernés, en conservant le contexte de navigation.

### Archivage des projets

Un administrateur archive un projet avec le bouton **Archiver** de sa ligne (`.button.settings-danger` dans `.settings-row-actions`, confirmation par `data-confirm` posé sur le bouton, donc par `window.SillageDialogs`). Le formulaire envoie `POST /projects/{id}/archive` avec `action=archive` ou `action=restore` ; le routeur vérifie le jeton CSRF et `WorkspaceController::archiveProject()` exige le rôle administrateur. La colonne `projects.archived_at` (migration `032_project_archive.sql`) porte l’état.

- `Project::all()` ne renvoie que les projets actifs : le menu latéral (sur toutes les pages, y compris notifications, wiki et import), les sélecteurs des paramètres (notifications, équipes) et l’assistant ne proposent plus un projet archivé. Les notifications personnelles déjà reçues pour ses tickets restent dans la boîte de réception. `Filter::where()` exclut aussi ses tickets des listes, tableaux de bord et rapports « Tous les projets ».
- Les données (tickets, réglages, formulaires, connexions) sont conservées. Le projet reste consultable par son lien `/?project={id}` ; la mise en page affiche alors un bandeau. Les formulaires publics, l’API MCP (`projects_list` renvoie `archived_at`) et les tickets ouverts directement ne sont pas bloqués : l’archivage masque, il ne verrouille pas.
- Les projets archivés sont listés aux seuls administrateurs dans le bloc repliable **Projets archivés**, avec un bouton **Réactiver**.
- `Project::setArchived()` verrouille les lignes de projets et refuse d’archiver le dernier projet actif, pour que l’espace de travail ait toujours un projet à ouvrir.
