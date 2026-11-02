# Relier les tickets au code

Un administrateur connecte un dépôt dans **Développement**. Le même dépôt peut servir à plusieurs projets : sa connexion, son jeton chiffré et son webhook sont partagés. Remplacer son jeton dans **Configurer** affecte donc tous ces projets.

Dans un projet, ouvrir le bouton **Paramètres du projet** près du titre, puis choisir **Dépôt Git principal**. Le catalogue contient les connexions existantes de l’installation. Ce choix lie le dépôt au projet sans recopier son secret. Il est aussi disponible à la création du projet et dans les outils MCP `projects_create` / `projects_update` avec `repository_connection_id`.

Plusieurs dépôts peuvent rester liés à un projet. Choisir un nouveau dépôt principal conserve les autres liens. Choisir « Aucun dépôt principal » retire seulement cette préférence. **Développement → Détacher** retire le lien au projet ; les autres projets conservent leur connexion. Le dernier détachement supprime la connexion et son historique après confirmation.

## Branches, commits et pull requests

Mentionner la clé du ticket, par exemple `WEB-125`, dans le nom d’une branche, le message d’un commit ou le titre d’une pull request. Un événement peut concerner plusieurs tickets, y compris dans plusieurs projets liés au dépôt. Une pull request peut aussi être rapprochée des tickets déjà associés à son commit. Une branche générique n’est pas liée uniquement parce qu’elle pointe sur le même commit.

La fiche ticket affiche trois compteurs et des détails déroulants : dépôt, branche, identifiant du commit, état de la pull request et lien vers le fournisseur. Le bouton de copie propose un nom de branche ; il ne crée pas de branche distante. Les dates absentes restent signalées comme inconnues.

## Lier une branche depuis un ticket

Dans **Ticket → Développement → Lier une branche existante**, sélectionner le dépôt du projet. Le dépôt principal est choisi par défaut ; avec un seul dépôt, il est proposé automatiquement. Avec plusieurs dépôts et aucun principal, le choix est explicite. GitHub et Bitbucket restent tous deux disponibles lorsque le projet utilise les deux ; aucune connexion étrangère au projet n’est proposée ni autorisée par l’API.

Cliquer **Charger les branches**, puis choisir une suggestion ou saisir le nom exact, sans URL ni `refs/heads/`. Les suggestions montrent une page de 50 branches, avec boutons de pagination. Le nom saisi est vérifié à nouveau auprès du fournisseur lors de **Vérifier et lier la branche** : une branche absente, une réponse correspondant à un autre nom ou une connexion refusée ne crée aucun lien. Le formulaire ne crée ni branche ni commit distant et conserve son brouillon après une erreur.

Le lien manuel permet de rattacher une branche qui ne contient pas la clé du ticket. Il est stocké séparément des observations : synchroniser ou recevoir un webhook ne l’efface pas. Les commits déjà reçus avec cette branche et les pull requests dont le dépôt source est identifié sont rapprochés à la liaison, sans rejouer d’automatisation. Les prochains push et pull requests alimentent le ticket. Le même nom dans un fork ne suffit pas à attribuer une pull request au dépôt choisi ; un commit effectivement commun peut encore servir de preuve de rapprochement.

**Retirer le lien** enlève cette règle manuelle après confirmation. Les observations de commits/pull requests déjà reçues restent dans l’historique et une branche dont le nom contient la clé reste liée automatiquement. Un dépôt retiré du projet n’est plus présenté sur ce ticket. Les liens partagés des autres projets sont conservés.

Les actions de liaison sont disponibles aux collaborateurs pouvant modifier les tickets. Les comptes en lecture seule consultent les données reçues ; la configuration des connexions et du dépôt principal reste réservée aux administrateurs. Le panneau s’actualise par AJAX après liaison/retrait ; **Actualiser ce panneau** relit les observations Sillage, sans lancer de synchronisation réseau implicite.

Les API de lecture de branches sont documentées par [GitHub](https://docs.github.com/en/rest/branches/branches#get-a-branch) et [Bitbucket Cloud](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-refs/#api-repositories-workspace-repo-slug-refs-branches-name-get). Elles réutilisent les jetons chiffrés existants. Les appels sont visibles dans les journaux techniques, sans jeton affiché dans la fiche.

## Quand les informations arrivent-elles ?

Pour `WEB-125`, créer par exemple `feature/WEB-125-correction-sitemap` puis pousser la branche. Un webhook signé accessible depuis Internet transmet les événements au serveur Sillage ; les clés sont reconnues à réception. Une branche uniquement locale n’est pas visible par l’API du fournisseur. Sans webhook, utiliser la synchronisation paginée dans **Développement** pour importer les observations existantes. Aucune interrogation permanente du fournisseur n’est lancée en ouvrant un ticket.

Cette intégration suit branches, commits et pull requests. Les tickets « Issues » créés dans GitHub ou Bitbucket ne sont pas importés comme tickets Sillage.

**Synchroniser** importe une page de 50 branches, commits ou pull requests. Choisir le type et le numéro de page pour remonter l’historique. Les vues affichent les 100 événements les plus récents ; les compteurs de la fiche portent sur les événements enregistrés. Une synchronisation ne constitue pas une récupération exhaustive de tout l’historique Git.

Pour GitHub, le jeton finement paramétré doit autoriser **Metadata**, **Pull requests** et **Contents** en lecture sur les dépôts concernés. Pour Bitbucket Cloud, autoriser les dépôts et pull requests en lecture. Les instructions du fournisseur s’affichent dans le formulaire de connexion.

## Webhooks et automatisations

Configurer l’URL et le secret affichés lors de la connexion. GitHub : événements push, création/suppression de branche et pull request. Bitbucket : push et événements de pull request. Les livraisons doivent porter une signature HMAC SHA-256 valide et correspondre au dépôt connecté.

Pour un dépôt déjà configuré, ouvrir **Développement → Configurer GitHub/Bitbucket → Configurer le webhook et les mises à jour automatiques**. L’URL possède un bouton de copie. Un administrateur peut préparer le secret existant pour la copie, après une requête protégée par CSRF ; il n’est pas renouvelé. Le champ reste masqué et est vidé à la fermeture de la modale. La réponse est `no-store` et son contenu ne figure pas dans les journaux techniques. Ce secret se colle dans la configuration du webhook du fournisseur, séparément du jeton d’accès API.

Dans **Automatisations**, un administrateur peut créer une règle sur la création d’une branche, un commit poussé, l’ouverture ou la fusion d’une pull request. La condition facultative de dépôt limite la règle à un repository. Les actions existantes permettent notamment de passer les tickets en cours ou en revue. Chaque règle reste propre à son projet.

Les imports manuels n’exécutent pas ces règles. Les notifications répétées sans changement d’état ne rejouent pas l’action ; une mise à jour datée plus ancienne ne remplace pas un état plus récent. Les builds et déploiements sont prévus pour une étape ultérieure.

Les migrations `008` à `010` reprennent les associations existantes et ajoutent les liens partagés. La colonne historique `connections.project_id` garde uniquement la provenance de création ; les appartenances actives sont dans `project_connections`.

La migration additive `021_ticket_branches.sql` introduit les liens manuels. Déployer le code avec `php bin/console.php migrate`. La liaison manuelle n’altère pas les champs/version métier du ticket. Les tests isolés emploient `tests/git_router.php` et un transport Git synthétique ; ce routeur ne doit jamais remplacer le front controller de production. Aucun hôte ou jeton distant n’est nécessaire pour ces contrats, qui ne prouvent pas un accès effectif à un dépôt privé réel.
