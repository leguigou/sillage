# Filtres conservés

Les vues Vue d’ensemble, Liste, Tableau, Chronologie et Graphiques conservent les critères et options de présentation par utilisateur et projet. La vue Tous les tickets possède sa mémoire distincte. Un lien contenant des critères explicites ou un rapport personnel est prioritaire sur les préférences mémorisées.

Les préférences sont gardées dans la session (vingt contextes) et un cookie AES-GCM chiffré, HttpOnly et SameSite=Lax valable trente jours. Le cookie conserve jusqu’à cinq contextes, dans une taille bornée ; les recherches trop longues restent uniquement dans la session. Chaque utilisateur possède son propre cookie. Les données sont revalidées avant réutilisation et ne confèrent aucun droit d’accès. Les dates de période de chronologie et la pagination ne sont pas mémorisées comme critères.

Réinitialiser retire les critères du contexte courant ; les changements de vue suivants respectent cette remise à zéro. Une nouvelle connexion du même compte peut restaurer les critères depuis le cookie, sans dépendre de l’ancienne session PHP.

## Diagnostic Bitbucket

Un HTTP 403 sur Pipelines ou Deployments demande de vérifier le droit Pipelines en lecture : `read:pipeline:bitbucket` pour les jetons API ou `pipeline` pour OAuth/jetons de dépôt. Cela ne prouve pas que le jeton est expiré et ne bloque pas les tâches indépendantes de branches/PR. [Permissions officielles](https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/).

Un HTTP 400 reste une requête refusée, pas un défaut de mot de passe établi. Le message indique désormais la famille de ressource : conflits, statistiques ou commits. Ouvrir Voir les appels pour identifier le chemin et le statut. Aucun corps de réponse ni jeton n’est journalisé. Le cas signalé en production n’est pas reproduit dans la base locale ; sa résolution exige le détail de cet appel. Le redéploiement doit aussi redémarrer le worker CLI pour qu’il charge le code actualisé.
