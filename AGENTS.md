# Maintenance de Sillage

## Journal des versions

Toute évolution fonctionnelle, correction ou modification d’interface livrée doit être documentée dans `resources/application-history.json`, puis vérifiée avec `php bin/console.php history:check`.

- Pour une nouvelle livraison, incrémenter `current_version` et ajouter en tête une entrée portant cette version, la date réelle, un titre, un résumé et les changements observables par l’utilisateur. Utiliser une numérotation `majeure.mineure.correctif` ; fonctionnalités : mineure, corrections et ajustements : correctif, incompatibilités : majeure.
- Si une entrée « à paraître » est explicitement en cours de préparation, compléter cette entrée au lieu de créer une version pour chaque fichier modifié. Ne pas présenter une date prévue comme une date de publication réelle.
- Conserver les entrées précédentes. Les révisions antérieures à la numérotation sont identifiées par leur SHA Git complet ; ne pas leur inventer de numéros ni de dates de déploiement.
- Le journal décrit la version du logiciel fournie par le code. Les versions et livraisons des projets sont un autre module. La production ne doit pas avoir besoin d’un dossier `.git` ou d’un accès réseau pour afficher l’historique.
- Employer des descriptions en français, compréhensibles, sans secrets ni noms de société ou d’hébergement propres à une installation.

Le guide de fonctionnement et de maintenance est dans `docs/versions-application.md`. Les composants visuels des paramètres sont définis dans `public/assets/settings.css` et décrits dans `docs/interface-parametres.md`.

## Journaux techniques

Les appels HTTP sortants des intégrations passent par `App\Services\TechnicalLog::outgoing`, en transmettant uniquement les faits de transport. Les appels entrants sont suivis au front controller ; le routeur, MCP et les webhooks fournissent leurs identités contrôlées. Réutiliser cet identifiant de corrélation et la connexion autocommit indépendante du journal. Ne jamais transmettre de corps, en-têtes, jetons, cookies, paramètres d’URL ni messages d’exception bruts au journal. Préserver le résultat et les erreurs métier si l’écriture du journal échoue. Voir `docs/journaux-techniques.md` pour la couverture, la rétention et les limites.

## Modales et confirmations

Utiliser `window.SillageDialogs` pour ouvrir une modale, demander sa fermeture et confirmer une action. Attendre les promesses de `confirm` et `prompt` ; ne pas réintroduire `window.confirm`, `window.prompt` ou des fermetures directes qui ignorent les brouillons. `complete` est réservé à une sauvegarde/insertion réussie, `commit` aux préférences déjà enregistrées. Les producteurs réconcilient leurs aperçus et sélections sur `sillage:dialog-discarded`. Le composant et ses limites sont décrits dans `docs/modales-et-listes.md`.

## Suivi Git

Les choix manuels de branches sont dans `ticket_branch_links`, séparément des observations. `DevelopmentPayload` possède les noms, clés et références normalisées ; `DevelopmentEvent` possède leur rapprochement. Le ticket complet et son rafraîchissement utilisent le même modèle de lecture `TicketDevelopment`. Toujours revérifier l’appartenance du dépôt au projet côté serveur. Les doubles de transport dans `tests/git_router.php` ne sont utilisables que pour la base isolée et ne doivent jamais être branchés au front controller de production.

`DevelopmentRunPayload` normalise les faits CI/déploiement. `GitJob` possède les réservations et générations ; `GitSync` orchestre la file et `GitMonitor` collecte les faits fournisseur. Ne pas déduire un conflit d’une politique de fusion bloquée, ni une livraison d’une branche cible. Les preuves d’inclusion d’un commit de fusion appartiennent à `DevelopmentEvent`. Les appels CLI utilisent l’identifiant de corrélation public du travail, jamais son bail. Voir `docs/developpement.md`.

## Wiki

Les fichiers `.md` de `WIKI_PATH` sont la référence du contenu courant ; `wiki_revisions` garde une copie complète de chaque version. Passer par `App\Services\Wiki` pour toute écriture : contenu préparé dans un fichier temporaire, transaction (verrou, version de départ, révision), puis renommage après validation. Tout chemin venant d’un navigateur, du CLI ou d’un parcours passe par `WikiStorage::relativePath()`/`segment()` ; ne jamais construire un chemin de fichier autrement ni suivre un lien symbolique. Le rendu passe par `WikiRenderer` (Parsedown en mode sécurisé, aucun HTML brut hormis `<br>` nu dans les cellules de tableau, aucun attribut `style`) ; incrémenter `WikiRenderer::VERSION` quand la sortie change. Les images sont dans `wiki_assets` (nom = empreinte SHA-256, jamais remplacées, type détecté sur le contenu, SVG interdit) ; les dossiers `_assets/` n’en sont que des copies. Les documents importés passent par `WikiImport` puis `HtmlToMarkdown` : le HTML importé n’est jamais affiché, seulement converti en Markdown ; aucune ressource n’est téléchargée depuis une adresse du document. L’éditeur Milkdown se met à jour uniquement via `tools/wiki-editor/`. Voir `docs/wiki.md`.
