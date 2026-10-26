# Connecter Sillage à une base MySQL externe

Les identifiants sont lus dans le fichier `.env` situé à la racine de l’application.
Le fichier `.env.example` sert de modèle. `.env` est exclu de Git et placé hors du dossier public Apache.

Sauvegardez votre `.env` existant, puis modifiez uniquement les paramètres de connexion :

```dotenv
DB_HOST=mysql.votre-hebergeur.fr
DB_PORT=3306
DB_DATABASE=sillage
DB_USERNAME=sillage_app
DB_PASSWORD="votre-mot-de-passe"
DB_SSL_CA=
```

Si l’hébergeur demande TLS, renseignez dans `DB_SSL_CA` le chemin absolu du certificat CA fourni.
Le certificat du serveur est alors vérifié. Le serveur doit accepter les connexions depuis votre poste ou votre serveur Apache.
Conservez `APP_KEY` lors d’un changement de base pour pouvoir déchiffrer les connexions GitHub/Bitbucket existantes.
Les variables définies directement dans l’environnement du serveur ont priorité sur `.env`.

## Depuis Windows en local

Gardez `APP_ENV=local`, `APP_URL=http://127.0.0.1:8088`, `SESSION_SECURE=false` et `MAIL_TRANSPORT=log` pour vos essais locaux.
Arrêtez les services avec `scripts/Stop-Local.ps1` avant de changer de configuration.

La base doit déjà exister, avec un compte disposant des droits nécessaires. Pour créer ou mettre à jour les tables :

```powershell
php -c .runtime/php-config/php.ini bin/console.php health
php -c .runtime/php-config/php.ini bin/console.php migrate
```

Lors d’une première installation, créez un administrateur avec `bin/console.php admin:create prenom.nom@cabesto.com`.
Pour conserver les données existantes, transférez une sauvegarde MySQL vers la nouvelle base avant de démarrer.
Changer `.env` ne transfère pas les données et ne copie pas les pièces jointes conservées dans `storage/uploads`.

Lancez ensuite `DEMARRER-BASE-EXTERNE.cmd`. Ce lanceur démarre Apache et vérifie la connexion configurée.
Il ne démarre pas MySQL localement, n’exécute pas de migration et n’insère pas de données de démonstration.
Les commandes de migration restent explicites. Le lanceur `DEMARRER-SILLAGE.cmd` reste dédié à la base locale portable sur le port 3338.

## En production

Utilisez le même `.env` sur le serveur Apache, avec `APP_ENV=production`, l’URL HTTPS réelle,
`SESSION_SECURE=true` et un transport de courrier opérationnel. Redémarrez les processus applicatifs après un changement.
Le déploiement GitHub lit la configuration partagée du serveur ; les secrets restent hors du dépôt.
Le fichier `compose.yaml` est une configuration de démonstration avec sa propre base MySQL : il fixe volontairement `DB_HOST=db`.
Pour une base externe en production, utilisez le déploiement Apache décrit dans le README ou adaptez explicitement la composition Docker.
