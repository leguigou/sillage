# Temps, historique, clôture et livraisons

## Temps de travail

La fiche ticket conserve son message initial et sa conversation dans le premier onglet. L’onglet **Temps** permet de saisir une durée en heures, une date et une note. Les collaborateurs corrigent ou suppriment leurs propres saisies ; un administrateur peut corriger celles de l’équipe. Les comptes en lecture seule peuvent consulter les saisies. Chaque saisie couvre de 0,01 à 24 heures, à une date passée ou du jour (date serveur UTC).

Les trois mesures restent indépendantes :

- **Estimation totale** : durée prévue, facultative, en heures.
- **Temps passé** : somme des saisies actives. Une correction ou suppression ajuste cette somme et conserve une trace dans l’historique.
- **Reste à faire** : `arrondi(estimation totale × (100 − avancement) / 100, 2)`.

Ainsi, une estimation de 10 h avec 60 % d’avancement donne **4 h restantes**, que le temps saisi soit de 2 h ou de 12 h. Sans estimation, le reste est inconnu (`null`), pas zéro. L’état « Terminé » ne modifie pas automatiquement l’avancement manuel. Les rapports par équipe agrègent les mêmes calculs sur tous les tickets filtrés et indiquent les tickets sans estimation. Les exports CSV/JSON et le MCP exposent ces valeurs.

## Historique détaillé

L’onglet **Historique** conserve les changements de champs avec leurs valeurs avant/après, l’acteur, la date UTC, la version du ticket et la source connue. Les identifiants et les libellés des valeurs de champs sont conservés ensemble au moment du changement. Un renommage ultérieur ne réécrit donc pas ces valeurs historiques. Le nom courant du compte auteur accompagne son identifiant. Les corrections et suppressions de temps, validations, archivages et restaurations sont également tracés.

Les changements et leur historique sont enregistrés dans la même transaction MySQL. Une version obsolète ou une transaction annulée ne laisse pas de faux historique. Les formats équivalents d’une estimation (`10`, `10,00`) ne produisent pas de faux changement de champ. L’historique est paginé ; il commence à l’installation de la migration 019. Les activités antérieures restent dans la vue d’ensemble, leurs anciennes valeurs ne peuvent pas être reconstruites.

## Résolution et validation

Dans **Modifier**, choisir la résolution de clôture : **Réalisé**, **Corrigé**, **Doublon**, **Rejeté**, **Non reproductible**. Elle est appliquée lorsque l’état appartient à la catégorie « Terminé », y compris avec un statut personnalisé. Sans choix explicite, « Réalisé » conserve la compatibilité avec les tableaux, automatismes et clients existants. Une réouverture efface la résolution et la date de clôture.

Les **Paramètres du projet** proposent **Exiger une validation avant la clôture des tickets**. Cette option est désactivée par défaut et ne réécrit pas les tickets déjà terminés. Dans la colonne de droite du ticket, un collaborateur peut valider le contenu ou demander une reprise avec une note obligatoire. La validation conserve son auteur et sa date. Elle ne ferme pas le ticket et n’impose pas un validateur différent du responsable.

Une modification des champs du contenu, de l’estimation, de l’avancement ou de la version cible remet la validation à « À valider ». Une transition seule conserve la validation ; une réouverture l’annule. Les commentaires et saisies de temps ne la remplacent pas. Dans un projet où la validation est obligatoire, il faut enregistrer les modifications, faire valider le contenu, puis terminer le ticket. Les API, modifications groupées et automatismes passent par le même contrôle. Les tickets déjà terminés peuvent être rouverts pour être corrigés et validés à nouveau.

## Versions et livraisons

Le menu **Versions et livraisons** permet de créer une version par projet : nom unique, objectif et date cible facultative. **Modifier un ticket → Version cible** associe le ticket à cette version. Une version ne peut pas être affectée à un ticket d’un autre projet. Les filtres permettent de retrouver les tickets d’une version, leur résolution et leur validation. La page de version présente ses tickets actuels, dont les tickets en pause, avec accès à la liste complète.

Les livraisons sont des déclarations explicites réalisées par un collaborateur ou un client MCP :

- environnement **Préproduction** ou **Production** ;
- résultat **Réussie**, **Échouée** ou **Retour arrière** ;
- référence du tag, commit ou build ;
- note, auteur et date UTC.

Le dernier résultat de chaque environnement est présenté séparément. L’historique des déclarations est conservé et paginé. Une version peut être archivée et restaurée ; ses anciens tickets et livraisons restent consultables, et aucune nouvelle affectation ou livraison n’est acceptée pendant l’archivage.

Le suivi Git existant continue de montrer les branches, commits et pull requests, avec leur état de fusion. Une fusion ne crée **aucune livraison** et ne change pas l’état d’un environnement. Cette fonctionnalité suit les livraisons déclarées ; elle ne lance pas de pipeline ou de déploiement et ne synchronise pas encore les builds ou environnements des fournisseurs Git. La liste des tickets ciblés reste modifiable : ce n’est pas un manifeste immuable du contenu d’un artefact livré.

## MCP et installation

Nouveaux outils : `tickets_time_list`, `tickets_time_save`, `tickets_time_delete`, `tickets_history`, `tickets_validate`, `releases_list`, `releases_save`, `releases_deliver`, `release_deliveries_list`. La lecture nécessite `read`, les écritures `write`. La configuration de la validation obligatoire utilise `projects_update.require_ticket_validation` avec `admin`. Les écritures sur un ticket ou une version existante exigent leur version courante. Actualiser le catalogue dans le client MCP après le déploiement.

Déployer le code puis appliquer `php bin/console.php migrate` sur la base configurée dans `.env`. La migration **019_time_history_releases.sql** ajoute les nouvelles tables et colonnes sans supprimer les données existantes. Sauvegarder la base avant la migration de production. PHP/MySQL/Apache restent les seuls composants requis en production.
