# Notifications e-mail par projet

Dans **Paramètres → Notifications e-mail**, choisir le projet puis **Afficher ce projet**. Le lien **Configurer les notifications e-mail du projet** est aussi disponible dans les paramètres du projet. Seuls les administrateurs modifient cette politique.

Les notifications restent désactivées pour un projet sans configuration explicite. Activer le projet, cocher les destinataires de chaque événement et enregistrer. Une ligne vide désactive l’événement. Les choix recommandés peuvent être rétablis avant enregistrement :

| Événement | Destinataires recommandés |
| --- | --- |
| Création | Responsable individuel et équipe responsable |
| Modification du contenu, des dates, de la priorité, du type, des étiquettes, du parent, du blocage, des heures ou de l’avancement | Aucun par défaut, pour limiter le bruit |
| Changement de responsable ou d’équipe | Responsable individuel et équipe responsable |
| Changement d’état, hors passage à Terminé | Demandeur interne et responsable |
| Ticket terminé | Demandeur interne, responsable et équipe responsable |
| Commentaire publié | Demandeur interne et responsable |
| Archivage ou restauration | Aucun par défaut |

Chaque événement peut cibler le demandeur interne (compte rapporteur), le responsable individuel, les membres de l’équipe responsable, tous les membres des équipes du projet, les administrateurs de l’espace ou des personnes explicitement choisies. Ces dernières ne reçoivent un événement que si sa colonne **Personnes choisies** est cochée. Lors d’une réaffectation, la colonne du responsable inclut aussi l’ancien responsable. L’équipe responsable désigne l’équipe actuelle du ticket.

Les comptes destinataires doivent être actifs et vérifiés. L’auteur de chaque action est exclu par défaut ; une case du projet permet de l’inclure s’il correspond aux destinataires choisis. Les adresses externes saisies dans les formulaires publics ne reçoivent pas les notifications internes. Le compte rapporteur de ces formulaires reste un destinataire interne possible.

Un passage à Terminé utilise l’événement dédié ; les autres transitions utilisent **Changement d’état**. Un même changement peut aussi contenir une modification de contenu ou une réaffectation : leurs destinataires sont réunis, sans doublon pour la même personne et le même ticket dans une requête. Les automations à la création sont consolidées dans la notification de création, avec l’état final du ticket. Un changement de représentation de `2,5` à `2.50` heures ne constitue pas une modification. Le simple reclassement des cartes, les collages ou chargements de fichiers non publiés et l’édition des liens ne créent pas d’événement distinct.

## Préférences personnelles

Dans **Paramètres → Mes notifications**, chaque utilisateur peut décocher **Recevoir les notifications des projets qui me concernent**. Cela coupe les notifications de tickets de tous ses projets, y compris les envois encore en attente. L’inscription, la validation d’adresse et la récupération du mot de passe continuent de fonctionner. Un administrateur ne force pas la réception d’un compte désabonné via une politique de projet.

## Contenu et suivi

Les e-mails contiennent la clé et le résumé du ticket, son projet, les événements et champs concernés, l’état, le responsable, l’équipe et un lien vers la fiche. Ils ne recopient ni la description, ni les commentaires, ni les pièces jointes. Le lien ne confère aucun accès supplémentaire : une connexion reste nécessaire.

**Suivi des envois de ce projet** affiche les compteurs et les vingt messages les plus récents : en attente, en cours, acceptés par le transport, annulés ou en échec. L’acceptation par le transport ne prouve pas la réception dans une boîte aux lettres. Le bouton de réessai remet les échecs en attente. Désactiver les notifications du projet annule ses messages en attente ou en échec ; un message déjà transmis ne peut pas être rappelé.

## Transport et production

Le transport reste une configuration serveur dans `.env` :

```dotenv
MAIL_TRANSPORT=mail
MAIL_FROM=sillage@example.com
```

`mail` utilise la messagerie PHP du serveur et son relais configuré par l’hébergeur. Il ne configure pas lui-même un compte SMTP ni un serveur de messagerie. Utiliser une adresse d’expédition autorisée par ce relais. **Envoyer un test à mon adresse** adresse un message uniquement à l’administrateur connecté, avec une limite de trois tests par dix minutes.

En local, `APP_ENV=local` avec `MAIL_TRANSPORT=log` écrit les messages dans `storage/mail`, sans envoi réseau. Les requêtes web traitent automatiquement jusqu’à vingt notifications en fin de requête. Ce mode est refusé comme transport en production pour éviter un faux succès silencieux.

En production, planifier le worker CLI chaque minute, depuis l’environnement de l’installation :

```cron
* * * * * /usr/bin/php /var/www/sillage/bin/console.php notifications:send >> /var/log/sillage-notifications.log 2>&1
```

Adapter les chemins à l’hébergement et employer le même `.env` / la même base que l’application. Le worker charge au maximum cent messages par exécution et renvoie les nombres acceptés, en échec et annulés. Le déploiement GitHub ne crée pas automatiquement cette tâche système. Sans worker, les changements de tickets continuent de s’enregistrer et les messages restent en attente.

Les envois suivent un outbox transactionnel MySQL : une annulation métier annule aussi ses notifications. Les workers prennent des baux exclusifs et ne gardent pas de transaction SQL ouverte pendant un appel de messagerie. Un bail interrompu est récupérable après dix minutes. Les erreurs sont réessayées au plus cinq fois, avec délai croissant, puis deviennent visibles comme échecs. Un compte désactivé, non vérifié, désabonné ou dont l’adresse a changé n’est plus servi ; la politique du projet est revérifiée avant envoi.

La livraison suit une sémantique « au moins une fois » : si un processus s’arrête après acceptation du message mais avant son enregistrement comme envoyé, une reprise peut produire un doublon. Les e-mails de notification ne conditionnent pas le succès de la modification du ticket.

## MCP et migration

`project_notifications_get` lit la politique d’un projet. `project_notifications_save` la remplace avec contrôle de version (0 pour la première configuration), les booléens `enabled` / `notify_actor`, `rules` (événements vers rôles destinataires) et la liste `recipients` (identifiants de comptes choisis). Les mêmes validations, préférences et événements métier s’appliquent aux tickets modifiés depuis MCP, les automatisations et les webhooks Git.

La migration additive `013_email_notifications.sql` crée les politiques et l’outbox, puis ajoute la préférence personnelle. Elle n’active aucun projet automatiquement. Les tests utilisent des comptes fictifs et le journal local ; l’envoi réel, la réception, le relais et la tâche planifiée restent à valider sur l’hébergement de production.
