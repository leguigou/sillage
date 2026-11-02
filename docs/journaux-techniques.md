# Journaux techniques

Dans **Paramètres → Journaux techniques**, les administrateurs consultent les échanges de l’installation. Le panneau se charge à l’ouverture ; les filtres, l’actualisation et les pages suivantes utilisent AJAX sans recharger les paramètres ni déplacer la page.

## Couverture

- **API / AJAX entrants** : requêtes demandant ou produisant du JSON, requêtes XMLHttpRequest et chemins `/api/`. Les pages HTML ordinaires, fichiers statiques et pièces jointes ne sont pas des appels API et ne sont pas journalisés.
- **MCP entrant** : transport `/mcp`, y compris refus avant authentification, négociation, méthodes JSON-RPC et outils autorisés exécutés. Une erreur JSON-RPC ou `isError` est marquée en erreur même lorsque HTTP répond 200. Une requête/réponse constitue une ligne ; Sillage est ici un serveur MCP et n’appelle pas d’autres serveurs MCP.
- **OAuth MCP** : découverte, consentement, échanges et révocations, sans code d’autorisation, rappel, état, secret client ni jeton.
- **Développement Git** : webhooks GitHub et Bitbucket, signatures refusées, découverte des dépôts, vérification d’accès et synchronisation des branches/commits/pull requests. Les tentatives sortantes qui atteignent le transport HTTP sont enregistrées ; une saisie refusée avant tout appel ne constitue pas un transfert réseau.
- **IA sortante** : catalogues et générations des fournisseurs configurés, y compris refus de configuration/réseau dans le client, réponses HTTP et réponses JSON invalides.

Le journal commence à cette version. Il ne reconstitue pas les appels passés. Les e-mails/SMTP, journaux Apache/PHP et opérations métier détaillées restent dans leurs modules respectifs. Un traitement Git reçu avec succès peut ignorer un événement non pertinent : un HTTP 200 n’atteste pas qu’un ticket a changé.

## Informations et filtres

Date UTC conservée en base et affichée dans le fuseau du navigateur, sens, service/fournisseur, méthode, chemin sans paramètres, opération MCP/événement Git connu, résultat, statut HTTP, durée en millisecondes, compte authentifié, identifiant de clé MCP ou de connexion Git lorsque connus. Les tailles des corps sont indiquées lorsqu’elles sont mesurées ; elles ne sont pas assimilées au trafic réseau complet.

Le serveur génère un identifiant de requête indépendant des en-têtes reçus et le renvoie dans `X-Request-ID`. **Voir les appels liés** retrouve les appels entrants et sortants qui partagent cet identifiant. Le bouton de copie permet de le transmettre pour un diagnostic sans envoyer un jeton d’accès.

Les filtres combinent sens, service, fournisseur, résultat, plage de dates UTC et identifiant exact. La pagination par curseur charge 50 lignes, en ordre décroissant ; l’arrivée de nouveaux appels ne décale pas les pages déjà parcourues. Le panneau n’interroge pas le serveur en boucle : utiliser **Actualiser** pour voir les nouveaux appels. Sa propre lecture JSON est un appel API et apparaît à la prochaine actualisation.

## Données exclues

Aucun corps de requête/réponse, contenu de ticket, prompt, génération, en-tête HTTP, cookie, adresse IP, clé brute, signature Git ou message d’exception brut n’est conservé. Les paramètres et fragments d’URL ainsi que les identifiants intégrés à une URL sont retirés. Les routes entrantes proviennent du routeur ; les noms de méthodes/outils et événements enregistrés sont contrôlés. Les erreurs sont décrites par un code technique limité, sans recopier le message d’un service distant.

Les identifiants numériques de clés/connexions sont des références, sans secret associé. L’endpoint JSON est réservé aux administrateurs, avec réponse `no-store` ; masquer l’onglet ne constitue pas le contrôle d’accès.

## Installation, conservation et exploitation

Appliquer la migration additive `020_technical_logs.sql` avec `php bin/console.php migrate` après déploiement. Elle n’altère pas les tickets existants. Une connexion MySQL autocommit distincte utilise les mêmes variables `DB_*` et la même vérification TLS que l’application. Les traces survivent donc à l’annulation d’une transaction métier. Aucune clé étrangère ne peut bloquer l’enregistrement sur une ligne métier verrouillée.

```dotenv
TECHNICAL_LOG_RETENTION_DAYS=30
```

Valeur de 1 à 365 jours ; une valeur absente ou invalide revient à 30. Les consultations excluent immédiatement les appels expirés. Chaque requête qui enregistre des traces purge au plus 1 000 lignes expirées, une seule fois, pour limiter le travail. Une purge planifiée quotidienne complète ce nettoyage sur les installations peu actives ou après une réduction de durée :

```sh
php bin/console.php logs:prune
```

La commande retire au plus 10 000 lignes par exécution. La relancer si elle en retire exactement 10 000. Elle concerne uniquement les traces expirées selon la politique configurée. Sauvegardes MySQL et copies externes suivent leur propre politique de conservation.

Si MySQL ou la table des journaux est indisponible, l’action métier conserve son résultat et une alerte fixe est écrite dans le journal PHP. Le panneau peut alors échouer à se charger ; vérifier les migrations et l’accès à MySQL. Ce journal est un outil de diagnostic, sans garantie d’audit inviolable ni de conservation des appels interrompus avant l’exécution PHP ou lors d’un arrêt brutal du processus.
