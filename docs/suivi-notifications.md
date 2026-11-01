# Mentions, abonnements et boîte personnelle

## Suivre un ticket

Dans la colonne droite d’un ticket, **Suivre ce ticket** abonne le compte connecté ; **Ne plus suivre** annule cet abonnement. Les comptes en lecture seule peuvent gérer ce suivi personnel. Cela ne change ni la version ni le contenu du ticket. Le nombre indiqué concerne les comptes actifs et vérifiés.

Les nouvelles créations, modifications, affectations, changements d’état, commentaires et archivages/restaurations produisent une notification interne pour les personnes concernées : demandeur interne, responsable individuel, ancien responsable lors d’une réaffectation et abonnés. L’auteur de l’action est exclu. Ces notifications fonctionnent indépendamment de l’activation des e-mails du projet. Les opérations antérieures à la migration ne sont pas rejouées.

## Mentionner un collaborateur

Dans la conversation, taper **@** ou cliquer **@ Mentionner**, puis rechercher un nom ou une adresse professionnelle. Les huit premières correspondances sont proposées. Choisir la personne avec la souris ou les flèches puis Entrée/Tab ; Échap ferme la liste. Le commentaire contient une mention liée à l’identifiant du compte, et pas une déduction à partir d’un prénom ambigu. Vingt personnes distinctes maximum peuvent être mentionnées par commentaire.

La personne reçoit une notification avec le repère « vous a mentionné ». Si elle suit aussi le ticket, une seule notification est créée pour ce commentaire. Le nom est normalisé côté serveur ; seuls les comptes actifs et vérifiés peuvent être mentionnés lors de la publication. Une désactivation ultérieure ne masque pas l’ancien commentaire. Les notifications sont enregistrées dans la même transaction que le commentaire et disparaissent si celle-ci est annulée.

Pour un commentaire texte envoyé depuis l’API/MCP, utiliser le jeton explicite `@[user:123]` avec l’identifiant retourné par `members_list`. Écrire un prénom après @ sans sélectionner de personne ne déclenche pas une mention. Le HTML des commentaires conserve uniquement les attributs de mention validés et la mise en forme autorisée.

## Lire les notifications

La cloche en haut ouvre **Notifications** pour tous les projets. Son badge est actualisé à la navigation, au retour sur la fenêtre et toutes les 45 secondes tant que la page est visible. La boîte propose **Toutes**, **Non lues**, la pagination et **Tout marquer comme lu**. Une notification peut être remise en non lue. Dans le filtre Non lues, les lignes lues quittent la liste après actualisation AJAX de la section.

Cliquer le titre marque la notification lue puis ouvre le ticket, directement au commentaire lorsqu’il existe. Le lien de retour conserve la boîte d’origine. Les actions de lecture et de suivi sont personnelles, protégées par la session et CSRF ; une personne ne peut pas modifier les notifications d’un autre compte. Le texte des commentaires n’est pas copié dans la boîte.

## E-mails et MCP

Les réglages e-mail par projet proposent deux destinataires supplémentaires : **Personnes qui suivent le ticket** et **Personnes mentionnées (commentaires)**. Ils respectent l’activation du projet et la préférence personnelle d’e-mails. Les politiques déjà enregistrées sont conservées : cocher ces destinataires si nécessaire. Les nouveaux réglages recommandés les incluent pour les événements concernés. Les e-mails gardent un lien de connexion et les métadonnées, sans recopier le commentaire.

`tickets_follow` gère le suivi du compte connecté ; `notifications_list` lit sa boîte paginée ; `notifications_mark` modifie l’état d’une de ses notifications. Les mutations MCP demandent le droit `write` et un rôle autorisé à l’utiliser ; la lecture seule peut gérer son suivi et ses lectures depuis l’interface web. Aucun outil n’accepte un autre compte comme propriétaire de l’action.
