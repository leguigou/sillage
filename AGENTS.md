# Maintenance de Sillage

## Journal des versions

Toute évolution fonctionnelle, correction ou modification d’interface livrée doit être documentée dans `resources/application-history.json`, puis vérifiée avec `php bin/console.php history:check`.

- Pour une nouvelle livraison, incrémenter `current_version` et ajouter en tête une entrée portant cette version, la date réelle, un titre, un résumé et les changements observables par l’utilisateur. Utiliser une numérotation `majeure.mineure.correctif` ; fonctionnalités : mineure, corrections et ajustements : correctif, incompatibilités : majeure.
- Si une entrée « à paraître » est explicitement en cours de préparation, compléter cette entrée au lieu de créer une version pour chaque fichier modifié. Ne pas présenter une date prévue comme une date de publication réelle.
- Conserver les entrées précédentes. Les révisions antérieures à la numérotation sont identifiées par leur SHA Git complet ; ne pas leur inventer de numéros ni de dates de déploiement.
- Le journal décrit la version du logiciel fournie par le code. Les versions et livraisons des projets sont un autre module. La production ne doit pas avoir besoin d’un dossier `.git` ou d’un accès réseau pour afficher l’historique.
- Employer des descriptions en français, compréhensibles, sans secrets ni noms de société ou d’hébergement propres à une installation.

Le guide de fonctionnement et de maintenance est dans `docs/versions-application.md`. Les composants visuels des paramètres sont définis dans `public/assets/settings.css` et décrits dans `docs/interface-parametres.md`.
