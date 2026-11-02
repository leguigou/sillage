# Versions et améliorations de Sillage

Les administrateurs disposent de **Paramètres → Versions et améliorations**. Cet onglet présente la version du code installé, les changements documentés et leur date. Une recherche par mots-clés/version/révision et un filtre par catégorie permettent de retrouver une fonctionnalité ou une correction. Les autres comptes ne reçoivent pas ce panneau dans la page des paramètres.

La numérotation commence avec **1.0.0**, le 2 novembre 2026. Aucune version numérotée ni étiquette Git n’existait auparavant : les 42 révisions disponibles lors de la constitution du journal sont reprises avec leurs SHA complets, leurs dates de commit et une description éditoriale vérifiée. Les dates historiques sont présentées en Europe/Paris. Elles décrivent les changements du code et ne constituent pas un historique des déploiements réels d’une installation.

## Source et déploiement

`resources/application-history.json` est la source versionnée du journal. Il est livré avec le code, y compris dans l’image Docker et les transferts de déploiement. L’application n’exécute aucune commande Git et ne contacte pas GitHub pour l’afficher. Le fichier se trouve hors du DocumentRoot public ; il n’est pas exposé comme une API anonyme. Aucune migration MySQL n’est nécessaire.

Le premier élément correspond à `current_version`. Chaque élément porte une version numérotée ou une révision Git complète, une date, une catégorie, un titre, un résumé et une liste de changements. `ApplicationHistory` valide ces données et fournit les libellés et dates affichés. Une même version ou révision ne peut pas être déclarée deux fois.

```powershell
php -c .runtime/php-config/php.ini bin/console.php version
php -c .runtime/php-config/php.ini bin/console.php history:check
```

Sur le serveur : `php bin/console.php version` et `php bin/console.php history:check`. La validation du journal est exécutée en intégration continue avant les tests applicatifs.

## Ajouter une mise à jour

1. Incrémenter la version courante et ajouter son entrée en tête du fichier. Employer `majeure.mineure.correctif` : fonctionnalité → mineure ; correction/ajustement → correctif ; incompatibilité → majeure.
2. Décrire les améliorations réellement livrées, leurs éventuelles limites et la date réelle de la mise à jour. Ne pas inclure de secret ni d’information propre à une société.
3. Conserver l’historique précédent et lancer `history:check`, puis les vérifications adaptées au changement.
4. Déployer le code et son journal ensemble. L’onglet affiche alors la version contenue dans cette copie du logiciel.

Le journal est maintenu par le dépôt ; les administrateurs le consultent dans le produit. Il n’est pas réécrit manuellement dans chaque base de données. Les règles de maintenance figurent également dans `AGENTS.md` pour les futures évolutions.
