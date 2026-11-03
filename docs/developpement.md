# Relier les tickets au code

Un administrateur connecte un dépôt dans **Développement**. Le même dépôt peut servir à plusieurs projets : sa connexion, son jeton chiffré et son webhook sont partagés. Remplacer son jeton dans **Configurer** affecte donc tous ces projets.

Dans un projet, ouvrir le bouton **Paramètres du projet** près du titre, puis choisir **Dépôt Git principal**. Le catalogue contient les connexions existantes de l’installation. Ce choix lie le dépôt au projet sans recopier son secret. Il est aussi disponible à la création du projet et dans les outils MCP `projects_create` / `projects_update` avec `repository_connection_id`.

Plusieurs dépôts peuvent rester liés à un projet. Choisir un nouveau dépôt principal conserve les autres liens. Choisir « Aucun dépôt principal » retire seulement cette préférence. **Développement → Détacher** retire le lien au projet ; les autres projets conservent leur connexion. Le dernier détachement supprime la connexion et son historique après confirmation.

## Branches, commits et pull requests

Depuis 1.7.2, la lecture des commits de PR Bitbucket commence sans `page=1`. Cet endpoint utilise une pagination par curseur : les pages suivantes réutilisent le `next` du fournisseur après validation stricte du schéma HTTPS, de l’hôte, du dépôt et de la PR. Le curseur reste opaque ; les boucles sont refusées. La pagination numérique GitHub reste indépendante. [Pagination officielle Bitbucket](https://developer.atlassian.com/cloud/bitbucket/rest/intro/).

Mentionner la clé du ticket, par exemple `WEB-125`, dans le nom d’une branche, le message d’un commit ou le titre d’une pull request. Un événement peut concerner plusieurs tickets, y compris dans plusieurs projets liés au dépôt. Une pull request peut aussi être rapprochée des tickets déjà associés à son commit. Une branche générique n’est pas liée uniquement parce qu’elle pointe sur le même commit.

La fiche ticket affiche trois compteurs et des détails déroulants : dépôt, branche, identifiant du commit, état de la pull request et lien vers le fournisseur. Le bouton de copie propose un nom de branche ; il ne crée pas de branche distante. Les dates absentes restent signalées comme inconnues.

## Lier une branche depuis un ticket

Dans **Ticket → Développement → Lier une branche existante**, sélectionner le dépôt du projet. Le dépôt principal est choisi par défaut ; avec un seul dépôt, il est proposé automatiquement. Avec plusieurs dépôts et aucun principal, le choix est explicite. GitHub et Bitbucket restent tous deux disponibles lorsque le projet utilise les deux ; aucune connexion étrangère au projet n’est proposée ni autorisée par l’API.

Les branches se chargent automatiquement à l’ouverture ou au changement de dépôt. Choisir dans la liste déroulante, ou rechercher un fragment du nom puis cliquer **Rechercher**. Elles sont classées par date du **dernier commit**, de la plus récente à la plus ancienne, avec la date affichée dans le fuseau du navigateur. Ce n’est pas la date de création de la branche. Les dates absentes ou invalides restent inconnues, en fin de liste. Les résultats sont paginés par 50 ; une sélection reste conservée lorsque l’on change de page ou de recherche.

Le classement porte sur toutes les branches correspondant à la recherche avant la pagination de l’interface. Bitbucket fournit `target.date` avec `sort=-target.date` ; GitHub fournit `target.committedDate` via son [API GraphQL des références](https://docs.github.com/en/graphql/reference/repos). L’ordre alphabétique de collecte GitHub n’est pas présenté comme un ordre chronologique. Le catalogue complet est ensuite trié par Sillage. Le transport garde les hôtes fixes, la vérification HTTPS et les journaux techniques sans corps ni jetons.

Un instantané privé en session est réutilisé pendant 120 secondes, avec au plus trois recherches conservées. Sa clé inclut dépôt, fournisseur, connexion, empreinte du secret chiffré et recherche : changer le jeton invalide la réutilisation. **Actualiser les branches** force une nouvelle lecture. La collecte est limitée à 2 000 branches, 100 pages fournisseur et environ 20 secondes de requêtes ; si elle ne peut pas être terminée, un message invite à préciser la recherche ou à saisir le nom exact. Aucun sous-ensemble incomplet n’est présenté comme un classement global.

**Saisir un nom de branche manuellement** permet toujours de renseigner le nom exact, sans URL ni `refs/heads/`. Le nom choisi ou saisi est vérifié à nouveau auprès du fournisseur lors de **Vérifier et lier la branche** : une branche absente, une réponse correspondant à un autre nom ou une connexion refusée ne crée aucun lien. Le formulaire ne crée ni branche ni commit distant et conserve son brouillon après une erreur. Après une liaison réussie, il est prêt pour une nouvelle sélection.

Le lien manuel permet de rattacher une branche qui ne contient pas la clé du ticket. Il est stocké séparément des observations : synchroniser ou recevoir un webhook ne l’efface pas. Les commits déjà reçus avec cette branche et les pull requests dont le dépôt source est identifié sont rapprochés à la liaison, sans rejouer d’automatisation. Les prochains push et pull requests alimentent le ticket. Le même nom dans un fork ne suffit pas à attribuer une pull request au dépôt choisi ; un commit effectivement commun peut encore servir de preuve de rapprochement.

**Retirer le lien** enlève cette règle manuelle après confirmation. Les observations de commits/pull requests déjà reçues restent dans l’historique et une branche dont le nom contient la clé reste liée automatiquement. Un dépôt retiré du projet n’est plus présenté sur ce ticket. Les liens partagés des autres projets sont conservés.

Les actions de liaison sont disponibles aux collaborateurs pouvant modifier les tickets. Les comptes en lecture seule consultent les données reçues ; la configuration des connexions et du dépôt principal reste réservée aux administrateurs. Le panneau s’actualise par AJAX après liaison/retrait ; **Actualiser ce panneau** relit les observations Sillage, sans lancer de synchronisation réseau implicite.

Les API de lecture de branches sont documentées par [GitHub](https://docs.github.com/en/rest/branches/branches#get-a-branch) et [Bitbucket Cloud](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-refs/#api-repositories-workspace-repo-slug-refs-branches-name-get). Elles réutilisent les jetons chiffrés existants. Les appels sont visibles dans les journaux techniques, sans jeton affiché dans la fiche.

## Retrouver une PR déjà fusionnée et ses fichiers

Après une liaison depuis le navigateur, Sillage lance une récupération de l’historique. Pour une branche déjà liée, cliquer **Synchroniser l’historique** dans le ticket. Les requêtes incluent les PR ouvertes, fusionnées et fermées/refusées, filtrées sur la branche source. Le dépôt source et la casse du nom sont revérifiés avant import : une branche homonyme d’un fork n’est pas une correspondance. Les vingt PR les plus récentes sont examinées ; **Charger les PR plus anciennes** poursuit la pagination. La branche reste liée si une récupération échoue.

Chaque PR correspondante reçoit ses propres commits et statistiques de fichiers par les API [GitHub Pull requests](https://docs.github.com/en/rest/pulls/pulls) et [Bitbucket Pull requests](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-pullrequests/). Les commits importés sont ceux de la PR, pas toute l’ascendance de la branche : une fusion vers une branche partagée ne doit pas attribuer tout son historique au ticket. Sans PR correspondante, les observations reçues par webhook restent disponibles ; les commits de toute la branche ne sont pas importés implicitement. La synchronisation historique ne rejoue aucune automatisation et ne change pas la version métier du ticket.

La fiche affiche les auteurs des commits, la destination de la PR et son état, puis le total de fichiers et de lignes ajoutées/supprimées. **Fichiers modifiés** ouvre une modale paginée par quarante fichiers, avec ancien chemin pour un renommage. Les nombres absents restent inconnus. Aucun patch, contenu de fichier ou extrait de code n’est conservé dans les statistiques, affiché ou journalisé. GitHub peut inclure un patch dans sa réponse HTTP ; le parseur ne conserve que chemins, statut et compteurs.

Bitbucket peut rediriger son endpoint de statistiques de PR. Le transport conserve `FOLLOWLOCATION=false` : une seule destination HTTPS vers `api.bitbucket.org`, dans le même dépôt et sous `diffstat/`, est explicitement validée avant un nouvel appel. La pagination est reconstruite par Sillage ; les liens de pagination distants ne sont pas suivis avec le jeton. Le paramètre `from_pullrequest_id` et les options de comparaison autorisées sont conservés. Les hôtes, dépôts, identifiants de transport et paramètres inattendus sont refusés.

Un relevé de détails est remplacé atomiquement après collecte, avec vérification de la révision avant/après lecture et avant écriture. Les webhooks préservent le dernier relevé ; un changement de révision le marque **à actualiser**. Chaque PR possède un bouton de récupération/actualisation indépendant. Une erreur conserve l’ancien relevé et les observations déjà reçues. Ces détails sont stockés dans les métadonnées existantes ; aucune migration supplémentaire n’est nécessaire. Le rendu principal exclut les listes de fichiers et d’empreintes du modèle de lecture ; les fichiers sont lus à la demande dans la modale.

Bornes par PR : dix pages de cent commits, dix pages de cent fichiers, environ vingt secondes de collecte, quatre Mio de données normalisées retenues. Une liste de fichiers incomplète n’écrase pas le dernier relevé complet. Une liste de commits limitée reste explicitement **partielle** ; notamment, l’API GitHub des commits de PR est plafonnée à 250 commits. Les erreurs et listes partielles invitent à consulter le fournisseur. Toutes les requêtes de synchronisation exigent les droits d’édition, CSRF, limitation de fréquence et appartenance au projet ; un lecteur peut consulter les statistiques enregistrées sans déclencher d’appel externe.

## Quand les informations arrivent-elles ?

Pour `WEB-125`, créer par exemple `feature/WEB-125-correction-sitemap` puis pousser la branche. Un webhook signé accessible depuis Internet transmet les événements au serveur Sillage ; les clés sont reconnues à réception. Une branche uniquement locale n’est pas visible par l’API du fournisseur. Sans webhook, le service de synchronisation ci-dessous rattrape les informations disponibles par API. La synchronisation paginée dans **Développement** reste disponible pour des observations ciblées. Ouvrir un ticket lit les données enregistrées, sans appeler le fournisseur.

Cette intégration suit branches, commits, pull requests, contrôles, pipelines et déploiements. Les tickets « Issues » créés dans GitHub ou Bitbucket ne sont pas importés comme tickets Sillage.

**Synchroniser** importe une page de 50 branches, commits ou pull requests. Choisir le type et le numéro de page pour remonter l’historique. Les vues affichent les 100 événements les plus récents ; les compteurs de la fiche portent sur les événements enregistrés. Une synchronisation ne constitue pas une récupération exhaustive de tout l’historique Git.

Pour GitHub, le jeton finement paramétré doit autoriser **Metadata**, **Pull requests** et **Contents** en lecture sur les dépôts concernés. Pour Bitbucket Cloud, autoriser les dépôts et pull requests en lecture. Les instructions du fournisseur s’affichent dans le formulaire de connexion.

## Webhooks et automatisations

Configurer l’URL et le secret affichés lors de la connexion. GitHub : événements push, création/suppression de branche et pull request. Bitbucket : push et événements de pull request. Les livraisons doivent porter une signature HMAC SHA-256 valide et correspondre au dépôt connecté.

Pour un dépôt déjà configuré, ouvrir **Développement → Configurer GitHub/Bitbucket → Configurer le webhook et les mises à jour automatiques**. L’URL possède un bouton de copie. Un administrateur peut préparer le secret existant pour la copie, après une requête protégée par CSRF ; il n’est pas renouvelé. Le champ reste masqué et est vidé à la fermeture de la modale. La réponse est `no-store` et son contenu ne figure pas dans les journaux techniques. Ce secret se colle dans la configuration du webhook du fournisseur, séparément du jeton d’accès API.

Dans **Automatisations**, un administrateur peut créer une règle sur la création d’une branche, un commit poussé, l’ouverture ou la fusion d’une pull request. La condition facultative de dépôt limite la règle à un repository. Les actions existantes permettent notamment de passer les tickets en cours ou en revue. Chaque règle reste propre à son projet.

Les imports et rattrapages n’exécutent pas ces règles. Les notifications répétées sans changement d’état ne rejouent pas l’action ; une mise à jour datée plus ancienne ne remplace pas un état plus récent. Les résultats de pipelines et déploiements sont consultables, sans nouvelle règle automatique de transition dans cette livraison.

Les migrations `008` à `010` reprennent les associations existantes et ajoutent les liens partagés. La colonne historique `connections.project_id` garde uniquement la provenance de création ; les appartenances actives sont dans `project_connections`.

La migration additive `021_ticket_branches.sql` introduit les liens manuels. Déployer le code avec `php bin/console.php migrate`. La liaison manuelle n’altère pas les champs/version métier du ticket. Les tests isolés emploient `tests/git_router.php` et un transport Git synthétique ; ce routeur ne doit jamais remplacer le front controller de production. Aucun hôte ou jeton distant n’est nécessaire pour ces contrats, qui ne prouvent pas un accès effectif à un dépôt privé réel.

## Synchronisation automatique et rattrapage

La version 1.7 utilise les migrations additives **022 à 025**. Exécuter `php bin/console.php migrate` avant de démarrer le worker. Les choix, délais, erreurs et curseurs sont persistants dans MySQL, séparément des observations et des liens manuels. Une seule configuration est partagée par les projets d’un même dépôt.

Dans **Développement → Configurer**, activer le suivi et choisir 1, 5, 15, 30 ou 60 minutes. Le réglage initial est activé avec cinq minutes. Le ticket et la page Développement affichent le dernier traitement réussi, la présence du service, les travaux en attente et leurs erreurs. **Rattraper maintenant** programme une récupération sans bloquer la page ; le panneau Développement du ticket ouvert se rafraîchit toutes les quinze secondes lorsqu’aucune modale n’est ouverte. Ce rafraîchissement consulte MySQL uniquement.

Le worker récupère les PR récentes, les PR des branches liées manuellement, leurs commits/fichiers, les contrôles, les pipelines et les déploiements. Le premier rattrapage des PR générales couvre trente jours ; les suivants reprennent le dernier parcours terminé avec dix minutes de recouvrement. Ce point n’avance qu’après le dernier lot. Les branches explicitement liées disposent du parcours historique existant, indépendamment de ces trente jours. Les pipelines et déploiements sont parcourus par pages ; chaque parcours est borné à cent pages. Une limite atteinte est signalée comme erreur, sans prétendre couvrir tout l’historique.

Les webhooks signés alimentent les observations et la file ; le parcours périodique compense des livraisons manquées. GitHub : ajouter `workflow_run`, `check_run`, `status` et `deployment_status` selon les besoins. Bitbucket Cloud : ajouter les statuts de commit créés/modifiés ; pipelines et déploiements sont aussi lus par API. Les charges OpenTelemetry `pipeline:span_created` ne sont pas interprétées comme des objets pipeline. Les doublons sont rapprochés par identité stable ; une ancienne tentative de workflow ne remplace pas une tentative plus récente.

Un échec est retenté après 1, 2, 4 puis 8 minutes, jusqu’à cinq tentatives par tâche. La relance manuelle reprend immédiatement les travaux en échec. Un prochain cycle périodique peut aussi reprendre les collectes générales. Un processus interrompu libère sa tâche après un bail de deux minutes ; les générations empêchent de perdre une demande arrivée pendant le traitement. Les messages contrôlés restent visibles, sans corps HTTP ni jetons. **Voir les appels** ouvre le journal avec l’identifiant de corrélation du traitement.

### Démarrer le service

- **Windows local** : `scripts/Start-Local.ps1` démarre aussi le worker caché. Pour une application déjà démarrée, `scripts/Start-GitWorker.ps1` est idempotent. `scripts/Stop-Local.ps1` arrête uniquement les processus dont l’identité a été vérifiée.
- **Docker/Dokploy** : reconstruire et démarrer le service **git-scheduler** des fichiers Compose mis à jour. Il est séparé du worker e-mail et doit recevoir les mêmes variables de base et `APP_KEY` que l’application. Les migrations restent exécutées par le service applicatif.
- **Autre serveur Apache** : superviser `php bin/console.php git:watch` avec le compte de l’application, ou planifier `php bin/console.php git:work` chaque minute. Le budget de chaque passage est borné et plusieurs workers partagent les réservations MySQL. `git:work` ne remplace pas le serveur HTTP.

Tous les appels fournisseur restent en lecture. Aucune fusion, exécution de pipeline ni livraison n’est déclenchée. Le worker recharge les secrets chiffrés depuis la base ; aucun secret n’est stocké dans la file. Une désactivation empêche de réserver de nouveaux travaux, sans annuler un appel déjà en cours.

## Tests, déploiements et conflits

Les contrôles sont associés par empreinte exacte au commit source ou de fusion connu du ticket. Les résultats d’Actions, de checks et de statuts sont distingués par leur identité, avec succès, échec, attente, exécution ou résultat inconnu. « Terminé » sans conclusion ne devient pas un succès. Les liens externes restent limités aux hôtes GitHub/Bitbucket ; aucun script, journal de build ni code source n’est affiché.

Les déploiements indiquent l’environnement fourni par l’API et le résultat réel. Un environnement nommé `staging` ou une PR fusionnée ne prouve pas une livraison. Le rapprochement utilise le commit exact ou une preuve d’ascendance du commit de fusion dans le commit déployé (compare GitHub, merge-base Bitbucket). Cette preuve est revalidée lors de l’écriture et conservée aux actualisations. Un déploiement en attente ou en échec reste distinct d’un déploiement réussi.

Une PR ouverte affiche un pictogramme orange **Conflit de fusion** seulement si le fournisseur confirme le conflit Git. Les tickets concernés portent aussi **Conflit Git** dans la liste. Un autre blocage de politique ou une réponse non calculée reste inconnu. Le contrôle vaut pour la révision source/cible reçue ; après quinze minutes sans nouveau contrôle, l’état redevient inconnu jusqu’à actualisation. Cela ne modifie pas le champ métier « Bloqué » du ticket.

Autorisations supplémentaires en lecture selon le fournisseur : **Actions**, **Checks** et **Deployments** sur GitHub, **Pipelines** et accès aux déploiements sur Bitbucket, en plus des dépôts et PR. Un jeton limité aux PR conserve les observations déjà reçues mais les rubriques supplémentaires peuvent afficher HTTP 403. Ajuster les droits chez le fournisseur puis relancer ; Sillage n’élargit pas lui-même les permissions.

Contrats des fournisseurs : [GitHub Actions](https://docs.github.com/en/rest/actions/workflow-runs), [checks](https://docs.github.com/en/rest/checks/runs), [déploiements GitHub](https://docs.github.com/en/rest/deployments/deployments), [PR Bitbucket et contrôles de fusion](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-pullrequests/), [déploiements Bitbucket](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-deployments/), [commits et merge-base Bitbucket](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-commits/).
