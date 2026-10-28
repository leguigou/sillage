# MCP Sillage : connexion, clés et outils

Le serveur MCP est intégré à PHP/Apache, sans processus Node ni dépendance Python en production.
Adresse locale : `http://127.0.0.1:8088/mcp`. En production : `https://votre-instance/mcp`.

## Créer et gérer une clé

Dans **Paramètres → MCP et clés d’accès** :

1. Donner un nom à la clé, par exemple « Assistant de mon équipe ».
2. Choisir une durée en minutes, heures ou jours, ou **Sans expiration**. La durée minimale est une minute, la maximale 100 ans.
3. Cocher les droits nécessaires et générer la clé.
4. Copier immédiatement la clé complète : elle n’est affichée qu’une fois.

La liste montre l’empreinte d’identification, les droits, l’expiration, le **dernier accès** et l’état.
Les dates sont affichées en UTC. Le dernier accès correspond à une requête POST authentifiée dont les en-têtes sont acceptés.
Le bouton **Supprimer** retire la clé de la base et refuse les nouvelles connexions. Un appel déjà en cours peut terminer avant la suppression.
La base conserve uniquement l’empreinte SHA-256 d’une clé aléatoire de 256 bits. La remise initiale utilise temporairement la session web, puis efface la valeur après affichage.

Un changement de mot de passe, de rôle ou d’état du compte invalide aussi ses clés existantes.
Une clé expirée, un compte désactivé ou une adresse non vérifiée ne permettent pas de se connecter.
Chaque personne gère ses propres clés ; le MCP ne permet pas d’en créer d’autres à sa place.

## Droits

| Droit de clé | Opérations                                                                          | Rôle du compte requis        |
| ------------ | ----------------------------------------------------------------------------------- | ---------------------------- |
| `read`       | Consultation, recherche, statistiques, membres, métadonnées                         | Tout compte vérifié et actif |
| `write`      | Tickets, commentaires, relations, archivage et vues personnelles                    | Membre ou administrateur     |
| `admin`      | Projets, formulaires, mise en forme, automatisations, équipes et accès utilisateurs | Administrateur               |

Les droits sont indépendants : cocher `read`, `write` et `admin` pour une gestion complète.
La case d’administration n’est pas cochée par défaut. Les droits effectifs sont réévalués à chaque requête.
Le catalogue ne présente que les outils autorisés ; appeler un outil masqué est également refusé côté serveur.
Tous les projets d’une installation restent visibles aux comptes internes autorisés, comme dans l’interface web.

## Configurer le client

Choisir un client prenant en charge **Streamable HTTP** et un en-tête Bearer personnalisé :

```text
URL : https://votre-instance/mcp
Authorization : Bearer <votre_clé>
```

Le format du fichier de configuration dépend du client. Pour ChatGPT et les clients utilisant OAuth, suivre [le guide OAuth](oauth.md). Les clés personnelles restent disponibles pour les clients acceptant un Bearer configuré manuellement.
Ne pas placer la clé dans l’URL, un fichier versionné ou une capture d’écran. HTTPS est requis ; HTTP est accepté uniquement en mode local sur une adresse de boucle locale.
Apache doit transmettre l’en-tête Authorization à PHP ; les configurations Apache livrées le prévoient.

Le transport suit les versions MCP **2025-11-25**, **2025-06-18** et **2025-03-26** :
une requête JSON-RPC par POST, négociation `initialize`, notification `notifications/initialized`, `ping`, `tools/list` et `tools/call`.
Le client indique `Accept: application/json, text/event-stream`, `Content-Type: application/json` et la version négociée dans `MCP-Protocol-Version`.
Le serveur utilise les réponses JSON sans session de protocole. GET et DELETE renvoient 405 ; aucun flux SSE persistant n’est ouvert.
Le comportement du transport est défini dans la [spécification MCP officielle](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).

Les requêtes sont limitées à **1 Mo**, avec **240 POST par minute et par clé/IP**.
Les en-têtes Host et Origin sont contrôlés. Les cookies de session du site ne remplacent jamais une clé MCP.

## Outils disponibles

Le catalogue complet et ses JSON Schemas se lisent avec `tools/list`, ou en CLI opérateur :

```powershell
php -c .runtime/php-config/php.ini bin/console.php mcp:tools
```

| Groupe                          | Outils                                                                                                                             |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Espace et dashboard             | `workspace_info`, `reports_summary`                                                                                                |
| Projets                         | `projects_list`, `projects_create`, `projects_update`, `projects_delete_empty`                                                     |
| Tickets                         | `tickets_list`, `tickets_get`, `tickets_create`, `tickets_update`, `tickets_pause`, `tickets_archive`, `tickets_bulk_update`                        |
| Commentaires et relations       | `tickets_comment`, `tickets_comments_list`, `tickets_link`, `tickets_links_list`, `tickets_unlink`                                 |
| Formulaires                     | `forms_list`, `forms_get`, `forms_save`, `forms_set_enabled`, `forms_rotate_link`, `forms_delete`                                  |
| Mise en forme                   | `formatting_list`, `formatting_create`, `formatting_delete`                                                                        |
| Automatisations                 | `automation_list`, `automation_get`, `automation_save`, `automation_set_enabled`, `automation_delete`, `automation_runs`           |
| Équipes et comptes              | `members_list`, `teams_list`, `teams_members_list`, `teams_save`, `teams_membership`, `teams_projects_list`, `teams_project_membership`, `teams_delete`, `users_list`, `users_update` |
| Vues personnelles               | `views_list`, `views_save`, `views_delete`                                                                                         |
| Pièces jointes et développement | `tickets_attachments`, `development_list`                                                                                          |

Les listes de tickets, projets, personnes, équipes, comptes et journaux utilisent `offset` et `limit` lorsque présents dans leur schéma, avec 100 éléments maximum par page et un total.
Les descriptions longues se lisent avec `tickets_get`. Les listes de formulaires n’exposent pas les empreintes de liens.
Les mises à jour, archivages de tickets et modifications de règles exigent leur **version actuelle**.
Une modification groupée de 100 tickets maximum est entièrement annulée en cas de conflit.
Les clés de projets sont immuables. Seuls les projets sans données associées peuvent être supprimés.

Les outils réutilisent les modèles métier du site. Les appels acceptés sont consignés avec le nom de l’outil, la date et le résultat,
sans conserver leurs arguments, leurs résultats ou la clé secrète dans le journal MCP.
Les erreurs métier sont rendues comme résultats `isError: true` ; les erreurs de protocole utilisent JSON-RPC.

### Exemple : créer un ticket

Après initialisation du client :

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "tickets_create",
    "arguments": {
      "project_id": 1,
      "fields": {
        "title": "Corriger le formulaire de réservation",
        "type": "bug",
        "estimated_hours": "2.5"
      }
    }
  }
}
```

## Règles automatiques

Les automatisations se créent dans **Automatisations** pour le projet sélectionné, ou avec `automation_save`.
Elles sont distinctes des règles de couleur.

```json
{
  "project_id": 1,
  "name": "Orienter les bugs",
  "event": "ticket.created",
  "conditions": { "type": "bug" },
  "actions": {
    "priority": "high",
    "estimated_hours": "1.5",
    "comment": "À examiner par l’équipe support."
  },
  "enabled": true
}
```

- Déclencheurs : `ticket.created`, `ticket.updated`, `ticket.status_changed`.
- Conditions en ET : type, état, priorité, personne assignée ou blocage. Sans condition, la règle s’applique à tous les tickets correspondant au déclencheur.
- Actions : état, priorité, personne assignée, blocage, remplacement des étiquettes, estimation horaire et commentaire.
- Exécution dans l’ordre de création, au plus 200 règles par projet. Chaque règle est exécutée au plus une fois pour l’événement initial : les actions automatiques ne déclenchent pas d’autres règles.
- Une erreur métier annule les actions de la règle en cause et s’inscrit dans le journal ; les autres règles et le ticket restent valides. Une erreur technique de base de données annule la transaction.
- La suspension prend effet pour les prochains événements. Les journaux sont conservés après suppression d’une règle.

Les événements issus du site, du MCP, de l’import CSV et des formulaires externes suivent la même logique.
Cette version ne comporte pas de déclencheur périodique, d’exécution de code arbitraire, ni d’envoi de messages externes par automatisation.

## Vérification et périmètre

Les tests utilisent MySQL, Apache, des requêtes HTTP réelles et le **SDK Python MCP officiel 1.30.0** pour initialisation, découverte, validation JSON Schema 2020-12 et appel.
Python et le SDK servent uniquement aux tests ; PHP/Apache suffit pour l’exploitation.
Le téléversement/téléchargement binaire des pièces jointes et la gestion des secrets GitHub/Bitbucket restent dans l’interface web.
Le MCP expose les métadonnées des fichiers et les événements de développement sans les jetons fournisseurs.


Les [équipes](equipes.md) se lient à plusieurs projets. Les tickets acceptent `team_id`, indépendant de `assignee_id`, et les filtres acceptent `team` (identifiant ou `"none"`). `reports_summary` expose les agrégats d’équipe.


`project_notifications_get` et `project_notifications_save` gèrent la politique e-mail de chaque projet avec contrôle de version. La modification des tickets par MCP passe par la même file de notifications que le site. Voir [les événements et la livraison](notifications.md).

## Tickets en pause et tickets bloqués

`flagged: true` indique un obstacle et conserve le ticket dans les vues actives. `status: "paused"` le met de côté. Les recherches, statistiques et exports basés sur les filtres excluent les pauses par défaut. Dans `tickets_list`, `filters.pause` accepte `active`, `only` (pauses seules) et `all` ; le filtre d’état `paused` ou une requête avancée mentionnant cet état les inclut aussi. Les vues enregistrées conservent ce filtre.

`tickets_pause` reçoit `id`, `version`, `paused` (booléen). `true` mémorise l’état courant puis met en pause ; `false` reprend cet état. Les autres champs sont conservés. Un ticket créé ou importé directement en pause reprend en « À faire ». `tickets_update` peut aussi sélectionner l’état `paused` ou un autre état explicitement. La pause conserve le contrôle de version, les règles et les notifications configurées pour les changements d’état. Actualiser les outils de l’intégration pour découvrir `tickets_pause`.
