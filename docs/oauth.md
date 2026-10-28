# Connecter ChatGPT à Sillage avec OAuth

Déployer cette version et appliquer les migrations (dont `015_mcp_oauth.sql`) avec la procédure habituelle. `APP_URL` doit être l’URL HTTPS publique exacte de l’installation. Apache et le proxy doivent transmettre Authorization ; les chemins `/.well-known/` doivent atteindre PHP.

## Créer le client

1. Dans ChatGPT, choisir OAuth et « Client OAuth défini par l’utilisateur », puis copier l’URL de rappel affichée.
2. Dans Sillage, en administrateur : **Paramètres → Connexions OAuth — ChatGPT**. Saisir le nom et cette URL exacte. Choisir `none` pour un client public protégé par PKCE.
3. Utiliser le bouton **Copier** de l’ID généré puis le coller dans ChatGPT, laisser le secret vide et choisir la méthode `none`. Pour un client confidentiel, sélectionner la même méthode `client_secret_post` ou `client_secret_basic` des deux côtés et copier aussi le secret affiché une seule fois.

Ne pas coller une clé personnelle MCP dans le champ ID ou secret OAuth : ce sont deux modes d’authentification distincts.

## Paramètres avancés

Les métadonnées sont publiées à `/.well-known/oauth-authorization-server` et `/.well-known/oauth-protected-resource/mcp`. Si la découverte automatique reste impossible, remplacer `https://votre-instance` dans ces valeurs :

| Champ | Valeur |
| --- | --- |
| URL du serveur | `https://votre-instance/mcp` |
| URL d’autorisation | `https://votre-instance/oauth/authorize` |
| URL du token | `https://votre-instance/oauth/token` |
| URL de base du serveur d’autorisation | `https://votre-instance` |
| Ressource | `https://votre-instance/mcp` |
| URL d’enregistrement | Vide |
| PKCE | S256 |
| OpenID Connect | Désactivé |

DCR et CIMD ne sont pas implémentés : utiliser un client préenregistré. Sillage ne fournit pas d’identité OIDC ni de point userinfo.

**Portées par défaut**, une par ligne : `read`, `write`. Ajouter `admin` uniquement pour administrer projets, équipes, formulaires et règles.

**Portées de base**, une par ligne : `read`, `offline_access`. Les outils déclarent leurs portées ; ChatGPT peut alors demander les portées des outils et celles de base sans les portées par défaut. Placer `offline_access` dans les portées de base permet donc le renouvellement.

Lors de la connexion, Sillage demande de s’identifier et de consentir aux droits. Le droit `admin` est décoché initialement : le cocher explicitement si nécessaire. Les droits sont toujours limités par le rôle actuel du compte.

## Durée et révocation

Les codes expirent après cinq minutes et sont utilisables une seule fois. Chaque accès expire après une heure au maximum. Avec `offline_access`, un jeton de renouvellement rotatif permet de maintenir la connexion pendant trente jours au maximum à partir de l’autorisation initiale. Une réutilisation d’un ancien jeton révoque la délégation entière.

Chaque utilisateur voit ses connexions, droits, expiration et dernier accès dans les paramètres et peut les révoquer. La suppression du client par un administrateur révoque toutes ses connexions. Un changement de mot de passe, rôle ou état du compte invalide les accès. La déconnexion du navigateur ne révoque pas les délégations OAuth : utiliser leur bouton de révocation.

Les tests utilisent de vrais échanges HTTP avec des clients fictifs sur la base isolée. La connexion depuis ChatGPT vers l’installation publique doit être vérifiée après le déploiement.

Références : [authentification des plugins OpenAI](https://developers.openai.com/plugins/build/auth), [autorisation MCP](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

Les URL, portées et identifiants possèdent chacun un bouton de copie. La création et les autres sauvegardes des paramètres s’effectuent sans navigation complète, avec confirmation ou erreur sur place. Les brouillons des autres sections sont conservés.

## Si le bouton Autoriser semble ne rien faire

Une ancienne version limitait aussi les redirections du formulaire au même site avec `form-action 'self'`. Chromium pouvait bloquer le retour vers ChatGPT après que le code avait été créé. Un second clic réutilisait alors une demande consommée. La règle du consentement autorise désormais uniquement l’origine du rappel enregistré ; le serveur conserve son contrôle de l’URL exacte. Les autres pages gardent la restriction au même site. Voir [le comportement des redirections CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/form-action).

Après déploiement, fermer l’ancien écran et relancer la connexion depuis ChatGPT avec le client existant. Les demandes expirées ou utilisées affichent une explication sur place. Plusieurs fenêtres d’autorisation peuvent coexister ; chacune conserve son état et ses droits. Si seul « Consulter » est présenté, le client n’a demandé que `read` : demander aussi `write` pour créer/modifier les tickets, et `admin` pour administrer les projets et règles.
