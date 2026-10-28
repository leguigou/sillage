# Équipes, projets et tickets

Un administrateur crée une équipe dans **Paramètres → Équipes**, puis ajoute les collaborateurs inscrits, actifs et dont l’adresse est vérifiée. Une personne peut appartenir à plusieurs équipes. Retirer un membre conserve son compte et ses affectations individuelles aux tickets.

Chaque carte d’équipe présente ses membres, ses projets et un lien vers tous ses tickets, tous projets confondus. Le formulaire **Associer un projet** permet de partager un projet entre plusieurs équipes. L’association se fait aussi à la création d’un projet ou dans **Paramètres du projet → Équipes du projet**. Un projet peut avoir plusieurs équipes et une équipe plusieurs projets.

La création et l’édition d’un ticket proposent **Équipe responsable**, avec les seules équipes associées à son projet. Ce champ est facultatif et indépendant de **Personne assignée** : on peut confier un ticket au Support tout en désignant une personne, membre ou non du Support, comme responsable individuel. Supprimer l’une des affectations conserve l’autre. Les tickets existants restent sans équipe jusqu’à leur affectation explicite.

## Suivre le travail

- **Filtrer → Équipe** : une équipe donnée ou les tickets sans équipe, combinable avec les autres critères sur le tableau, la liste, la chronologie et les rapports.
- **Regrouper → Équipe** : sections par équipe dans le tableau et dans la liste. Les cartes et la colonne de liste affichent l’équipe responsable.
- **Rapports** et **Vue d’ensemble** : volumes par équipe, nombre de tickets terminés, avancement manuel moyen et total des heures estimées renseignées. Les agrégats portent sur tous les tickets correspondant aux filtres, indépendamment de la pagination.
- Les vues personnelles enregistrent le filtre d’équipe. Les exports CSV/JSON conservent l’identifiant et le nom de l’équipe. La recherche avancée accepte par exemple `team = "Support"` ou `team IS EMPTY`.

Les équipes structurent le travail. Les permissions restent celles des rôles globaux administrateur, membre et lecture seule ; l’appartenance à une équipe ne crée pas de restriction d’accès aux projets.

## Retrait et suppression

Retirer une équipe d’un projet exige d’abord de réaffecter ou de libérer tous ses tickets dans ce projet, y compris archivés. Une tentative incompatible échoue sans enregistrer partiellement les paramètres du projet. Les liens aux autres projets restent conservés.

Supprimer une équipe, après confirmation, conserve les comptes et les tickets mais retire leur équipe responsable ainsi que les associations de l’équipe aux projets. Les responsables individuels restent inchangés. Les versions des tickets concernés sont incrémentées pour refuser les anciennes éditions ouvertes avant cette suppression.

## MCP et base de données

La migration additive `011_team_projects.sql` crée `team_projects` et ajoute `tickets.team_id`. Une clé étrangère composite garantit qu’un ticket ne peut référencer qu’une équipe liée à son propre projet.

Le MCP expose les mêmes règles :

- `teams_project_membership` associe ou retire une équipe d’un projet ; `teams_projects_list` liste ses projets.
- `projects_create` / `projects_update` acceptent `team_ids`. Champ omis : liens conservés ; tableau vide : retirer les liens si aucun ticket ne l’empêche.
- `teams_list` accepte `project_id` et indique les nombres de membres, projets et tickets actifs.
- `tickets_create` / `tickets_update` acceptent `team_id` (`null` pour libérer l’affectation).
- `tickets_list` et `reports_summary` acceptent `filters.team` (identifiant ou `"none"`). Le rapport expose aussi la charge par équipe.
