# Validation locale — 26 octobre 2026

Environnement vérifié : Windows, Apache Lounge 2.4.69, PHP 8.5.5 Thread Safe, MySQL Community 8.4.13. PHP natif MVC, aucun framework runtime. Données de démonstration dans `sillage`, tests dans `sillage_test`.

- 28 contrôles des contrats métier et de la base MySQL : domaine exact, nom proposé, hachage, dates, chiffrement, expressions de filtre, neutralisation CSV, numérotation, appartenance des parents, cycles, résolution, concurrence et agrégation. Écritures unitaires annulées.
- 66 vérifications HTTP : session obligatoire, CSRF, connexion, rendu des neuf vues, protection de `.env` et du stockage, création et modification, conflits, dates et transitions du tableau, recherche, exports, actions groupées, vues enregistrées, commentaires, pièces jointes privées et rejet HTML, aperçu/import CSV à usage unique, signature et idempotence de webhook, formulaire externe, archivage, rôle en lecture seule, inscription, validation et réinitialisation à usage unique.
- Vérification visuelle dans le navigateur : connexion, dashboard et thèmes clair/sombre. La couverture des gestes et des différents formats d’écran est détaillée par les vérifications ajoutées lors de la finition.
- Lanceur local réexécuté sur les données existantes : configuration conservée, migrations à jour, démonstration non dupliquée, serveur HTTP valide.
- Sauvegarde de la base locale par le script `bin/backup.php` : fichier SQL créé hors du DocumentRoot. La restauration et le retour arrière de production restent à vérifier sur le futur hébergement.

Une difficulté d’écriture/disparition de scripts CLI a été observée sur Windows pendant l’implémentation. Après demande de réessai, les scripts ont été restaurés et leurs tests relancés avec succès. Aucun réglage antivirus n’a été désactivé par l’agent.

Les tests de développement utilisent des fixtures signées et ne prouvent pas le fonctionnement réel de GitHub ou Bitbucket. Le relais mail réel, HTTPS, les sauvegardes/restaurations du serveur cible, le workflow SSH et les politiques GitHub de production nécessitent la configuration du propriétaire. Docker et les workflows distants n’ont pas été exécutés localement.
