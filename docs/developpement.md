# Relier les tickets au code

Un administrateur connecte un dépôt dans **Développement**. Le même dépôt peut servir à plusieurs projets : sa connexion, son jeton chiffré et son webhook sont partagés. Remplacer son jeton dans **Configurer** affecte donc tous ces projets.

Dans un projet, ouvrir le bouton **Paramètres du projet** près du titre, puis choisir **Dépôt Git principal**. Le catalogue contient les connexions existantes de l’installation. Ce choix lie le dépôt au projet sans recopier son secret. Il est aussi disponible à la création du projet et dans les outils MCP `projects_create` / `projects_update` avec `repository_connection_id`.

Plusieurs dépôts peuvent rester liés à un projet. Choisir un nouveau dépôt principal conserve les autres liens. Choisir « Aucun dépôt principal » retire seulement cette préférence. **Développement → Détacher** retire le lien au projet ; les autres projets conservent leur connexion. Le dernier détachement supprime la connexion et son historique après confirmation.

## Branches, commits et pull requests

Mentionner la clé du ticket, par exemple `WEB-125`, dans le nom d’une branche, le message d’un commit ou le titre d’une pull request. Un événement peut concerner plusieurs tickets, y compris dans plusieurs projets liés au dépôt. Une pull request peut aussi être rapprochée des tickets déjà associés à son commit. Une branche générique n’est pas liée uniquement parce qu’elle pointe sur le même commit.

La fiche ticket affiche trois compteurs et des détails déroulants : dépôt, branche, identifiant du commit, état de la pull request et lien vers le fournisseur. Le bouton de copie propose un nom de branche ; il ne crée pas de branche distante. Les dates absentes restent signalées comme inconnues.

**Synchroniser** importe une page de 50 branches, commits ou pull requests. Choisir le type et le numéro de page pour remonter l’historique. Les vues affichent les 100 événements les plus récents ; les compteurs de la fiche portent sur les événements enregistrés. Une synchronisation ne constitue pas une récupération exhaustive de tout l’historique Git.

Pour GitHub, le jeton finement paramétré doit autoriser **Metadata**, **Pull requests** et **Contents** en lecture sur les dépôts concernés. Pour Bitbucket Cloud, autoriser les dépôts et pull requests en lecture. Les instructions du fournisseur s’affichent dans le formulaire de connexion.

## Webhooks et automatisations

Configurer l’URL et le secret affichés lors de la connexion. GitHub : événements push, création/suppression de branche et pull request. Bitbucket : push et événements de pull request. Les livraisons doivent porter une signature HMAC SHA-256 valide et correspondre au dépôt connecté.

Dans **Automatisations**, un administrateur peut créer une règle sur la création d’une branche, un commit poussé, l’ouverture ou la fusion d’une pull request. La condition facultative de dépôt limite la règle à un repository. Les actions existantes permettent notamment de passer les tickets en cours ou en revue. Chaque règle reste propre à son projet.

Les imports manuels n’exécutent pas ces règles. Les notifications répétées sans changement d’état ne rejouent pas l’action ; une mise à jour datée plus ancienne ne remplace pas un état plus récent. Les builds et déploiements sont prévus pour une étape ultérieure.

Les migrations `008` à `010` reprennent les associations existantes et ajoutent les liens partagés. La colonne historique `connections.project_id` garde uniquement la provenance de création ; les appartenances actives sont dans `project_connections`.
