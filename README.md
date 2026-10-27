# Sillage — l’espace de travail de votre société

Application de ticketing en **PHP natif MVC**, sans framework, **MySQL 8.4** et **Apache 2.4**. Interface française, menu à gauche, thèmes clair et sombre. Une installation indépendante par société, avec nom et domaines d’inscription configurables. Pas de plateforme mutualisée ni de facturation SaaS dans cette version.

## Personnaliser pour une société

Dans `.env`, renseigner par exemple :

```dotenv
COMPANY_NAME="Atelier Horizon"
ALLOWED_DOMAINS=atelier-horizon.fr,horizon-services.fr
EMAIL_DOMAIN_RESTRICTION=true
```

Le nom est utilisé dans les pages de connexion, le menu, les paramètres, les formulaires externes et les e-mails.
Les domaines sont comparés exactement lors d’une nouvelle inscription. Une liste invalide ou vide en mode restreint est refusée.
Pour accepter toute adresse valide à l’inscription, définir explicitement `EMAIL_DOMAIN_RESTRICTION=false`.
Les comptes existants gardent leur accès ; leur désactivation se gère dans les paramètres utilisateurs.
Chaque société utilise une base, un stockage, une URL et une clé distincts. Voir [le guide de personnalisation](docs/personnalisation.md).

## Essayer sur cette machine

Ouvrir **http://127.0.0.1:8088**. Si les services sont arrêtés, double-cliquer sur `DEMARRER-SILLAGE.cmd`.

Les identifiants de démonstration sont dans **`.runtime/credentials.txt`**, un fichier local exclu de Git. Les données de démonstration sont fictives et ne doivent jamais être installées en production.

Le lanceur Windows utilise le PHP Thread Safe déjà installé. Il prépare uniquement les dépendances du projet dans `.runtime/` : MySQL Community 8.4.13 et Apache Lounge 2.4.69, avec contrôle des archives. Il ne modifie pas les installations globales ni le pare-feu. Les services écoutent uniquement sur `127.0.0.1`, ports **8088** et **3338**.

```powershell
powershell -NoProfile -File scripts/Start-Local.ps1
powershell -NoProfile -File scripts/Stop-Local.ps1
```

Le lancement est réutilisable : `.env` et les données existantes sont conservés, les migrations ne sont appliquées qu’une fois, et la démonstration ne s’ajoute qu’à une base vide. Les services créés par le lanceur sont enregistrés avec leur identité ; l’arrêt ne cible que ces processus. Si le port est occupé par un service non créé par le lanceur, celui-ci ne l’arrête pas.

Sur Windows, utiliser le fichier PHP INI local pour les commandes :

```powershell
php -c .runtime/php-config/php.ini bin/console.php health
php -c .runtime/php-config/php.ini bin/console.php migrate
```

## Comptes et accès

Dans **Paramètres → Équipes**, un administrateur crée une équipe puis choisit les personnes à ajouter.
La personne doit d’abord s’inscrire avec une adresse autorisée par la configuration de sa société et valider son e-mail.
Une personne peut appartenir à plusieurs équipes. Le bouton **Retirer** enlève uniquement son appartenance à l’équipe.
Dans **Paramètres → Utilisateurs et accès**, l’administrateur change le rôle ou désactive/réactive le compte.
Chaque changement d’accès invalide les sessions concernées. Le dernier administrateur actif et vérifié est protégé.
Les équipes organisent les personnes ; elles ne créent pas de restriction d’accès par projet.

- Inscription selon `ALLOWED_DOMAINS` et `EMAIL_DOMAIN_RESTRICTION`, sans autoriser implicitement les sous-domaines ou domaines ressemblants.
- E-mail + mot de passe ; nom prérempli depuis les parties séparées par `.`, `_`, `-` ou `+`, puis modifiable.
- Validation de possession de l’adresse avant connexion ; lien à usage unique valable 24 heures.
- Réinitialisation par lien à usage unique valable une heure ; changement du mot de passe actuel depuis le profil. Le changement invalide les autres sessions.
- Rôles **administrateur**, **membre**, **lecture seule**. Les comptes inscrits par le web deviennent membres, jamais administrateurs.
- Tous les utilisateurs internes autorisés voient les projets de leur installation. Il n’existe pas de cloisonnement privé par projet dans cette version.

En local, les e-mails sont des fichiers privés dans **`storage/mail/`**. Pour valider une inscription de test, ouvrir le fichier correspondant et suivre son lien. En production, utiliser `MAIL_TRANSPORT=mail` et configurer le relais de messagerie du serveur PHP ; les e-mails de production ne sont jamais stockés dans ce dossier.

## Fonctions

Les tickets possèdent un **temps total estimé en heures**, facultatif : `2,5` ou `2.5` pour deux heures et demie.
Ce champ est disponible à la création, dans la fiche et directement dans la liste ; il peut être trié, importé et exporté.
Il s’agit de l’estimation de la tâche, sans saisie du temps réellement passé ni addition automatique des parents et enfants.

## Base MySQL externe

Les paramètres `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` et le certificat facultatif `DB_SSL_CA`
sont configurés dans **`.env`**. Aucun identifiant n’est codé en dur dans l’application.
Pour utiliser une autre base depuis ce poste, suivre [le guide de connexion externe](docs/base-externe.md)
puis utiliser **`DEMARRER-BASE-EXTERNE.cmd`**. Ce mode ne lance ni serveur MySQL, ni migration, ni démonstration automatiquement.

## Modules disponibles

| Module               | Comportement                                                                                                                                                                                                                                                         |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Projets et tickets   | Plusieurs projets, clés et numéros atomiques, tâche/story/bug/sous-tâche/Epic, résumé, description, état, priorité, assignation, parent, dates, étiquettes et blocage.                                                                                               |
| Fiche ticket         | Modification protégée contre les écritures concurrentes, commentaires, pièces jointes privées, tickets enfants et relations associé/bloque/doublon. Archivage et restauration.                                                                                       |
| Liste                | Recherche, tri, colonnes visibles, regroupements, hiérarchie, modification directe, sélection et modification groupée.                                                                                                                                               |
| Filtres              | Modes simple et avancé, expressions inspirées de JQL, vues personnelles enregistrées. Les expressions non prises en charge sont explicitement refusées.                                                                                                              |
| Tableau              | Quatre étapes : À faire, En cours, En revue, Terminé. Déplacement des cartes entre colonnes et réordonnancement, création dans la colonne, regroupement, largeur et défilement configurables.                                                                        |
| Dashboard            | Tickets créés/mis à jour/terminés, prochaines échéances, états, priorités, types, répartition par personne, activité et progression des Epics. Liens vers les listes concernées.                                                                                     |
| Rapports             | Quatre graphiques calculés sur l’ensemble des résultats filtrés, indépendamment de la pagination.                                                                                                                                                                    |
| Chronologie          | Semaines/mois/trimestres, périodes précédente/suivante, planification, déplacement des barres, poignée pour ajuster l’échéance, dépendances visuelles et modification depuis la fiche.                                                                               |
| Formulaires externes | Titre, instructions, type créé, champs sélectionnés, libellés, champs requis et conditions sur la priorité. Lien de collecte opaque, désactivation et renouvellement du lien. Le visiteur externe fournit son e-mail et crée un ticket sans compte ni accès interne. |
| CSV                  | Aperçu et association des colonnes avant import transactionnel. Export CSV avec protection contre les formules de tableur ; export JSON.                                                                                                                             |
| Mise en forme        | Règles par état, priorité, type ou blocage, repère coloré sur les lignes et cartes.                                                                                                                                                                                  |
| Développement        | Connexion d’un dépôt GitHub ou Bitbucket Cloud par jeton chiffré, synchronisation de pull requests et réception de webhooks HMAC signés pour commits/PR. Association aux clés de tickets.                                                                            |

Les préférences d’affichage et de thème restent dans le navigateur. Les tickets, utilisateurs, formulaires, règles, vues enregistrées, commentaires, fichiers et événements de développement sont persistés côté serveur.

La chronologie s’ouvre sur six semaines, du lundi au dimanche, avec numéros ISO, jours, week-ends et repère du jour courant.
Les vues Mois et Trimestres couvrent respectivement 16 et 52 semaines ; les en-têtes utilisent les véritables limites des mois et trimestres.
Les titres des tickets et les dates restent visibles pendant le défilement. Les ressources CSS/JS sont versionnées pour éviter les anciennes présentations en cache.

La commande « Reprendre ma vue précédente » reprend les paramètres Sillage de ce navigateur ; elle n’importe pas automatiquement les réglages d’une ancienne instance Jira.

### Limites explicites

- Le langage de recherche **n’est pas le JQL complet**. Champs documentés dans le dialogue ; `AND`, `OR`, comparaisons, `IN`, `IS EMPTY`, `~` et parenthèses. Pas de fonctions Jira ni de `ORDER BY` dans l’expression : le tri utilise les en-têtes.
- Le workflow comporte quatre états fixes. La configuration de nouveaux états, types et champs arbitraires, le designer libre de widgets, la gestion fine des capacités, les sprints, GitLab, les agents IA, scans de vulnérabilités et métriques DORA ne sont pas implémentés.
- La liste affiche 50 tickets par page. Les regroupements et la hiérarchie portent sur la page examinée. Le tableau et la chronologie affichent au maximum 1 000 tickets avec un message de couverture ; les statistiques agrègent tous les tickets filtrés.
- L’import est limité à 500 lignes / 2 Mo, l’export à 10 000 tickets et une pièce jointe à 10 Mo. Les fichiers restent hors du dossier public et sont téléchargés via un contrôleur authentifié.
- La synchronisation de développement examine la première page de 50 PR. Les webhooks reçus complètent l’historique ; cette vue ne garantit pas la récupération rétroactive de tous les événements.
- Les connecteurs sont testés au niveau de leurs contrats et signatures avec des fixtures. Aucune connexion réelle à un compte GitHub ou Bitbucket n’est revendiquée sans les jetons du propriétaire.

## MCP et automatisations

Le serveur MCP est disponible à **`/mcp`** avec 45 outils : projets, tickets, commentaires, relations,
formulaires, règles de mise en forme, automatisations, équipes, accès utilisateurs, vues et statistiques.
Les métadonnées des pièces jointes et les événements de développement sont également consultables.

Dans **Paramètres → MCP et clés d’accès**, chaque utilisateur génère une clé personnelle,
choisit sa durée en minutes/heures/jours ou **Sans expiration**, limite ses droits,
consulte le dernier accès et peut supprimer la clé. Le secret complet est affiché une seule fois.
Une clé ne peut jamais disposer de plus de droits que son propriétaire. HTTPS est requis en production.

Le menu **Automatisations** permet de créer, modifier, suspendre ou supprimer des règles par projet,
avec déclenchement à la création/modification d’un ticket ou au changement d’état, conditions et journal d’exécution.
Ces règles sont exécutées par les modèles métier, que le ticket provienne du site, d’un formulaire, d’un CSV ou du MCP.

Voir [le guide MCP, ses droits et son catalogue](docs/mcp.md).

## Structure MVC

```text
app/Core/          Routeur, PDO, sessions, authentification, rendu, migrations, e-mails
app/Models/        Contrats métier des tickets et projets
app/Services/      Filtres, requêtes, CSV, adaptateurs de développement
app/Controllers/   Actions HTTP, validation d’accès et orchestration
app/Views/         Vues PHP échappées, composants et écrans
public/            Seul DocumentRoot exposé : index.php et assets
database/          Migrations SQL versionnées et démonstration locale
storage/           Pièces jointes, e-mails locaux, journaux et sessions — ignorés par Git
bin/               Maintenance CLI, sauvegarde et préparation locale
deploy/            Configuration Apache/PHP et activation des versions
tests/             Contrats MySQL et parcours HTTP isolés
```

Les requêtes utilisent PDO avec paramètres liés ; les tris et champs sont sélectionnés dans des contrats nommés. Les mutations requièrent session et CSRF, sauf les webhooks qui requièrent une signature valide et le dépôt attendu. Le rapporteur d’une demande externe est défini par l’administrateur du formulaire. Les secrets des connecteurs sont chiffrés en AES-256-GCM avec `APP_KEY`.

## Installation avec Docker

PHP, Apache et MySQL sont également décrits dans `Dockerfile` et `compose.yaml`. Docker n’a pas été installé sur la machine et ce mode n’a pas été exécuté localement.

1. Copier `.env.example` vers `.env`.
2. Pour une démonstration, définir `APP_ENV=local`, `APP_URL=http://127.0.0.1:8088`, `SESSION_SECURE=false`, `MAIL_TRANSPORT=log`.
3. Générer une clé de 64 caractères hexadécimaux avec `php bin/console.php key:generate`. Définir deux mots de passe différents pour `DB_PASSWORD` et `MYSQL_ROOT_PASSWORD` (ajouter cette dernière variable à `.env`).
4. Exécuter :

```sh
docker compose up -d --build
docker compose exec app php bin/console.php migrate
docker compose exec app php bin/console.php seed
```

Les données MySQL et les fichiers utilisent des volumes persistants. Ne pas exécuter `docker compose down -v` pour arrêter une instance dont les données doivent être conservées.

## Déploiement Apache / MySQL depuis GitHub

Le workflow `.github/workflows/ci.yml` vérifie PHP et les parcours HTTP avec MySQL 8.4. Le workflow **Déployer Sillage** est manuel, attend les vérifications et utilise l’environnement GitHub `production`.

Préparer un serveur Linux avec Apache 2.4 + PHP 8.3 minimum (8.5 conseillé), `pdo_mysql`, `mbstring`, `curl`, `openssl`, `fileinfo`, `dom`, et MySQL 8.4. Installer le client `mysqldump` pour les sauvegardes. Pointer Apache **uniquement vers `public/`**, jamais vers la racine du dépôt. Un exemple est fourni dans `deploy/apache-vhost.conf` ; configurer HTTPS auprès de l’hébergeur.

```text
/var/www/sillage/
  releases/<commit>/
  current -> releases/<commit>
  shared/.env
  shared/storage/
  shared/backups/
```

- Créer une base vide et un compte MySQL dédié. Utiliser un compte de migration disposant des droits nécessaires ; le compte de service peut être restreint après la migration selon l’exploitation choisie.
- Créer `shared/.env` depuis `.env.example` avec `APP_ENV=production`, l’URL HTTPS réelle, une clé unique, `SESSION_SECURE=true`, la base et le relais mail. Ne pas utiliser `MAIL_TRANSPORT=log` en production.
- Créer `shared/storage` et `shared/backups`, accessibles en écriture au compte de déploiement ; le processus web doit accéder à `shared/storage`. Conserver ces dossiers et les sauvegardes hors du DocumentRoot.
- Dans GitHub, définir `DEPLOY_HOST`, `DEPLOY_USER`, `SSH_PRIVATE_KEY` et `SSH_KNOWN_HOSTS` dans les secrets de l’environnement `production`. Ajouter la clé SSH publique correspondante au compte de déploiement et vérifier l’empreinte du serveur par un canal fiable.
- Définir les règles de protection de l’environnement `production` (branche autorisée et approbateur si souhaité).
- Lancer le workflow manuel. Il transfère une nouvelle version, conserve les secrets/fichiers partagés, effectue une sauvegarde SQL, applique les migrations, vérifie MySQL et bascule le lien `current`.
- Pour le premier accès, l’opérateur crée un administrateur avec une adresse du domaine configuré : `php bin/console.php admin:create prenom.nom@votre-societe.fr`. Le mot de passe initial est affiché une fois ; il doit être communiqué de manière sûre puis changé. Le premier projet est créé dans l’interface.
- **Ne jamais lancer `seed` en production** : la commande le refuse elle-même.

Le serveur de production et son relais de messagerie n’ayant pas été fournis, aucune mise en production réelle n’a été effectuée. Les workflows sont livrés pour ce déploiement.

### Sauvegarde, transfert et retour arrière

```sh
php bin/backup.php /chemin/prive/backups
```

La sauvegarde MySQL utilise `--single-transaction`, n’inclut pas les fichiers joints et garde les identifiants hors des arguments du processus. Sauvegarder séparément **`storage/uploads` et `APP_KEY`**, indispensable au déchiffrement des jetons. Pour porter une base locale, restaurer son dump sur MySQL de production puis adapter `.env` ; utiliser des données nettoyées et retirer les comptes de démonstration avant toute exposition. Pour démarrer une nouvelle instance, préférer les migrations sur une base vide.

En cas d’échec avant activation, le lien `current` reste sur la version précédente. Pour un retour arrière, rétablir le lien vers une version précédente compatible avec le schéma ; restaurer la sauvegarde sous contrôle opérateur si une migration incompatible l’exige. Les versions et sauvegardes ne sont pas automatiquement supprimées.

## Tests et validation

```powershell
powershell -NoProfile -File scripts/Test-Local.ps1
```

Le script prépare la base **`sillage_test`** et son compte séparé, teste les contrats puis démarre temporairement un Apache sur `127.0.0.1:8089` pour les workflows HTTP. Le port 8089 doit être libre. Les données de l’application sur 8088 ne sont pas modifiées. Python 3 est nécessaire aux tests HTTP uniquement, pas à l’application.

Commandes utiles, une fois l’environnement de test préparé :

```powershell
php -c .runtime/php-config/php.ini tests/run.php
php -c .runtime/php-config/php.ini tests/run.php fixtures
python tests/http_workflows.py
```

Les fixtures contiennent des identifiants connus **uniquement dans la base de test**. Elles ne doivent pas être installées dans la base applicative. Le détail des validations et des limites est dans [docs/validation.md](docs/validation.md).


La fiche ticket propose un avancement manuel entier de 0 à 100 %, indépendant de son état (`progress_percent` dans le MCP et les imports/exports). Les tickets existants démarrent à 0 % ; aucune estimation historique n’est déduite de leur état.

La conversation dispose d’un éditeur avec gras, italique, souligné, listes et liens HTTP(S). Ctrl+V charge les images dans les pièces jointes privées et les insère à la position du curseur ; envoyez ensuite le commentaire pour publier son contenu. L’éditeur accepte uniquement le texte des collages provenant d’autres pages. Le serveur reconstruit une liste limitée de balises, retire scripts, styles et attributs actifs, et n’autorise que les images jointes au même ticket. Les anciens commentaires restent du texte brut. Le MCP accepte `body_format: "html"` en option pour `tickets_comment`, avec le même traitement.

La zone de dépôt accepte jusqu’à 20 fichiers par envoi, avec retrait individuel avant envoi. Le bouton apparaît uniquement lorsque la sélection contient des fichiers. En cas de rejet partiel, seuls les fichiers en erreur restent en attente. Les membres autorisés à modifier les tickets peuvent supprimer définitivement une pièce jointe après confirmation ; son image disparaît aussi des conversations. L’archivage du ticket demande une confirmation et reste réversible depuis sa fiche.


Les administrateurs peuvent modifier **Paramètres → Identité de l’entreprise** : nom, sous-titre, initiale/symbole et logo PNG/JPEG/WebP (2 Mo maximum), avec aperçu avant enregistrement. Ces informations et le logo sont conservés en MySQL et suivent les sauvegardes. Le nom enregistré prend priorité sur `COMPANY_NAME` ; la restriction des e-mails reste dans `.env`. Voir [la personnalisation par société](docs/personnalisation.md).
