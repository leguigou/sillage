# Cohérence de l’interface des paramètres

La page conserve ses onglets et ses sauvegardes AJAX. Ses composants visuels communs sont définis dans `public/assets/settings.css`, chargé uniquement sur les paramètres, après la feuille globale. Les mises en page propres à un fournisseur ou à un tableau ne doivent pas redéfinir les boutons et pieds de formulaire.

- **Boutons** : hauteur minimale de 40 px, texte de 12 px, mêmes bordures et rayons. Le texte peut revenir à la ligne si l’écran est trop étroit. Les sélecteurs de thème et palettes restent des contrôles spécialisés.
- **Actions principales** : bouton rempli pour créer, générer ou enregistrer un formulaire. `.settings-actions` place les actions en bas à gauche, après un séparateur. Les actions secondaires utilisent `.button.subtle`, les suppressions/retraits/révocations `.button.settings-danger`, avec bordure rouge et fond transparent.
- **Formulaires** : `.settings-form` donne une largeur de saisie maximale de 760 px et un espacement régulier. `.settings-form-wide` permet aux matrices de notifications, transitions et listes de pictogrammes d’utiliser toute la carte. Les champs conservent leur largeur disponible dans les petites cartes et dialogues.
- **Listes et cartes** : `.settings-card` fournit le fond, la bordure, les angles et le remplissage communs aux équipes, fournisseurs et connexions. `.settings-row-actions` réunit les opérations propres à une ligne ou une carte, sans les étirer.
- **Ajouts et états** : `.settings-toolbar` place une action d’ajout sous l’introduction, à gauche. `.settings-empty` et `.settings-badges` présentent les états vides et les compteurs avec une même mise en forme.

Les libellés, aides, titres, champs et boutons de copie utilisent également les règles communes. Les variables `--settings-field-width` et `--settings-control-height` sont définies pour la page et ses dialogues. Éviter d’introduire de nouvelles classes propres à une fonctionnalité pour reproduire ces composants.
