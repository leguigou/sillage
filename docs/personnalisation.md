# Une installation Sillage par société

Chaque installation utilise sa propre base MySQL, son propre dossier `storage`, une clé `APP_KEY` distincte,
sa configuration de courrier et son URL HTTPS. Le même code GitHub sert toutes les installations.
Il n’y a pas de société à choisir au moment de se connecter : l’URL identifie l’installation.
Les cookies de session sont propres à l’URL et à la clé de chaque installation, y compris pour deux ports locaux.
Cette mise à jour du nom de cookie demande une reconnexion sur les installations existantes.

## Configuration dans `.env`

```dotenv
COMPANY_NAME="Atelier Horizon"
ALLOWED_DOMAINS=atelier-horizon.fr,horizon-services.fr
EMAIL_DOMAIN_RESTRICTION=true
APP_URL=https://tickets.atelier-horizon.fr
MAIL_FROM=sillage@atelier-horizon.fr
```

- `COMPANY_NAME` : nom initial affiché à la connexion, dans le menu, les paramètres, les formulaires externes et les e-mails.
- `ALLOWED_DOMAINS` : domaines exacts autorisés pour les nouveaux comptes, séparés par des virgules. Sans `@`, URL ni `*`. Majuscules et espaces sont normalisés. Les sous-domaines doivent être ajoutés explicitement. Pour un domaine internationalisé, utiliser sa forme ASCII/punycode.
- `EMAIL_DOMAIN_RESTRICTION=true` : limite les inscriptions aux domaines de la liste. Une liste vide ou invalide bloque le démarrage au lieu d’ouvrir les inscriptions.
- `EMAIL_DOMAIN_RESTRICTION=false` : autorise toute adresse e-mail valide à s’inscrire dans cette installation. La validation par e-mail reste obligatoire. Toutes ces personnes peuvent ensuite accéder aux projets de l’installation.

Le domaine doit être complet : `societe.fr` ne signifie ni toutes les adresses `.fr`, ni `societe.fr.autre-site.com`.
`ALLOWED_DOMAINS` prend priorité sur l’ancien `ALLOWED_DOMAIN`, encore reconnu lorsqu’il est seul pour les installations existantes.

Les changements de domaine concernent les **nouvelles inscriptions** et la création d’un administrateur en CLI.
Les comptes existants conservent leur connexion et leur récupération de mot de passe après un changement de domaine.
Pour supprimer leur accès, les désactiver dans **Paramètres → Utilisateurs et accès**.
Les formulaires de collecte externes restent ouverts à leurs visiteurs, indépendamment des domaines d’inscription.

Sauvegarder `.env` avant modification et conserver les clés/identifiants de l’installation existante.
Vérifier la configuration avec :

```powershell
php -c .runtime/php-config/php.ini bin/console.php company:check
```

Sous Linux, utiliser simplement `php bin/console.php company:check`.
Redémarrer l’application ou recréer le conteneur après modification. Les variables de l’environnement du serveur ont priorité sur `.env`.
La page **Paramètres → Configuration de la société**, visible aux administrateurs, affiche les valeurs réellement chargées.

## Identité modifiable depuis l’interface

Dans **Paramètres → Identité de l’entreprise**, un administrateur peut modifier le nom, le sous-titre du menu et l’initiale ou le symbole, et ajouter, remplacer ou retirer le logo. L’aperçu se met à jour avant enregistrement. Un logo accepte PNG, JPEG et WebP, jusqu’à 2 Mo et 4 096 × 4 096 pixels ; les SVG sont refusés. Sans logo, le symbole choisi est utilisé, ou la première lettre du nom si le champ est vide.

Le nom enregistré dans MySQL prend priorité sur `COMPANY_NAME` pour l’interface, les pages publiques, les e-mails et les informations MCP. Il reste propre à cette installation. Les domaines et leur restriction restent gérés par les variables `.env` décrites ci-dessus.

La migration additive `006_company_branding.sql` crée une table contenant les métadonnées et les octets du logo. Les sauvegardes SQL incluent donc le logo, sans fichier séparé à transporter ; le script de sauvegarde écrit les données binaires en hexadécimal. Le logo est volontairement public pour apparaître avant connexion et dans les formulaires externes, sans donner accès aux pièces jointes. Son URL contient une empreinte du contenu ; remplacer ou retirer le logo invalide l’ancienne URL. Les changements nécessitent un compte administrateur, une session valide, un jeton CSRF et la version courante des réglages.

Pour une autre société, démarrer avec une base et un stockage vides. Changer le nom d’une installation existante ne retire pas ses données,
ses utilisateurs ou ses pièces jointes. Le nom affiché est une personnalisation, pas une frontière entre sociétés.

## Périmètre SaaS

Ce modèle permet d’exploiter plusieurs installations isolées avec le même produit.
Une plateforme mutualisée avec onboarding de sociétés, abonnements, facturation et console d’exploitation reste une évolution distincte.
Voir aussi [la configuration MySQL externe](base-externe.md) et le déploiement Apache/GitHub du README.
