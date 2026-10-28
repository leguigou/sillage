# Rester connecté pendant trente jours

Une connexion par e-mail et mot de passe crée automatiquement un cookie de connexion valable **30 jours**. Une session déjà ouverte avant cette évolution est équipée du cookie lors de sa prochaine visite authentifiée. Si elle avait déjà expiré, une première reconnexion est nécessaire.

La session PHP conserve sa durée d’inactivité courte. Quand son fichier disparaît, qu’elle expire ou que le navigateur a été fermé, le cookie persistant permet de recréer une session PHP et son jeton CSRF sans ressaisir le mot de passe. La limite reste fixée à trente jours depuis la connexion initiale : les visites et restaurations ne la prolongent pas indéfiniment.

Le cookie contient un identifiant aléatoire et un secret de 256 bits. MySQL conserve uniquement une empreinte HMAC du secret, liée à l’URL et à la clé de l’installation. Le mot de passe n’est jamais mis dans le cookie. Les attributs sont `HttpOnly`, `SameSite=Lax`, chemin `/` et `Secure` lorsque `SESSION_SECURE=true` (production HTTPS). Chaque appareil possède son propre enregistrement.

**Se déconnecter** efface le cookie et révoque ce justificatif côté serveur, y compris les sessions PHP recréées avec lui. Les autres appareils restent connectés. Un changement ou une réinitialisation de mot de passe, une modification de rôle ou une désactivation de compte invalide toutes les connexions persistantes du compte. Un compte non vérifié ne peut pas obtenir ce cookie.

La migration additive `014_persistent_login.sql` crée `persistent_logins`. Les données étant dans MySQL, un redéploiement qui remplace les fichiers de session PHP ne suffit plus à déconnecter l’utilisateur. Il faut conserver **la même base, `APP_URL` et `APP_KEY`** entre les déploiements. Le nom de société et les thèmes ne modifient pas ces identifiants. Un navigateur qui efface tous les cookies, une navigation privée fermée ou un changement de domaine peut toujours nécessiter une reconnexion.

Un ancien formulaire ouvert avant la recréation de session peut demander à être rechargé car son jeton CSRF n’est plus celui de la nouvelle session. La protection CSRF n’est pas désactivée pour conserver la connexion.

Les tests vérifient la durée calculée du cookie, ses attributs, la restauration après retrait du seul cookie de session PHP, la reprise d’une session ancienne sans nouveau mot de passe, la limite absolue inchangée, la falsification, l’expiration forcée, la déconnexion et le refus de réutilisation, les sessions dérivées et les comptes désactivés ou invalidés. Ils n’attendent pas trente jours et ne constituent pas une validation du futur hébergement HTTPS ou de sa politique de conservation MySQL.
