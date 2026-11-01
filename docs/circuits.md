# Circuits personnalisables par projet

Un administrateur ouvre **Paramètres → Circuit du projet**, ou le lien **Configurer les statuts, types et transitions** dans les paramètres du projet. La configuration concerne uniquement le projet sélectionné.

## Statuts et types

Créer un statut, choisir son libellé, sa couleur, son pictogramme et son ordre. L’ordre détermine les colonnes du tableau. Les types se configurent de la même manière. Chaque famille accepte jusqu’à 30 éléments pour garder les colonnes et la matrice utilisables.

Un statut appartient à une catégorie métier : **À faire**, **En cours**, **En revue** ou **Terminé**. Par exemple « En attente du client » peut être en cours et « Livré » terminé. Les dates de résolution, les rapports, les filtres masquant les tickets terminés et les notifications utilisent cette catégorie. Les types conservent une catégorie Tâche, Story, Bug, Sous-tâche ou Epic ; un type de catégorie Sous-tâche exige un parent, y compris depuis le MCP ou un import.

Les clés des éléments existants restent fixes. Les nouveaux éléments reçoivent une clé immuable disponible dans leur configuration et dans le MCP. Les libellés et couleurs sont modifiables sans changer les références des tickets, vues ou automatisations. La catégorie d’un élément standard ou déjà utilisé est protégée. Un élément utilisé, même par un ticket archivé, un formulaire ou une règle, ne peut pas être désactivé. Les clés `todo`, `task` et `paused` restent disponibles pour les valeurs initiales et la pause.

## Transitions

Par défaut, tous les passages restent possibles, comme avant cette migration. Pour les limiter, cocher **Autoriser uniquement les passages cochés ci-dessous**, puis les cases Depuis → Vers et enregistrer la matrice complète. Une ligne sans destination interdit toute sortie métier de cet état : vérifier les lignes avant d’activer la restriction. Rester dans le même état est toujours possible. La pause et la reprise restent disponibles et préservent le statut précédent, y compris personnalisé. Depuis une pause, les autres destinations restent soumises aux transitions de ce statut précédent : la pause ne permet pas de contourner le circuit. Un statut mémorisé par des tickets en pause reste protégé contre la désactivation.

Les passages sont contrôlés dans le modèle du ticket : la fiche, la liste, le glisser-déposer, les modifications groupées, les automatisations et le MCP ne peuvent pas contourner le circuit. Une automatisation incompatible suit le mécanisme d’échec transactionnel existant ; adapter ses actions lors d’un changement de circuit. Les options de la fiche et de la liste montrent les destinations autorisées ; le tableau peut afficher une erreur si une carte est déplacée vers une colonne interdite.

Les types et états sont repris dans les filtres simples et avancés, les formulaires externes, les imports CSV et les intégrations. Pour les vues portant sur tous les projets, les nouveaux éléments sont préfixés par la clé du projet ; les anciennes clés communes gardent leur libellé générique. Les recherches avancées sur un projet reconnaissent ses libellés et ses clés.

## MCP et déploiement

`project_workflow_get(project_id)` expose la version et les éléments du circuit. `project_workflow_save` exige le droit `admin` et cette version : actions `add_item`, `save_item` ou `transitions`. Pour cette dernière action, fournir `restricted` et `edges`, liste complète d’objets `{from,to}` ; une liste vide avec restriction bloque toutes les sorties métier. Les clés de statut/type des tickets sont validées par le projet au moment de chaque mutation, plutôt que figées dans un enum du catalogue MCP.

La migration additive `018_workflows_notifications.sql` crée les tables de configuration et de suivi. Lancer `php bin/console.php migrate` après le déploiement. Les projets sans configuration utilisent le circuit et les types historiques ; aucun ticket existant n’est réaffecté. Les modifications nécessitent une session administrateur, un jeton CSRF et la version actuelle. Les formulaires se sauvegardent par AJAX sans rechargement global.
