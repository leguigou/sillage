# Transfert complet d’un site

Ce guide décrit comment déplacer une installation Sillage entière vers une nouvelle installation : comptes, rôles, équipes, projets, circuits, tickets, commentaires, historique, pièces jointes, formulaires, automatisations, versions, connexions Git, profils d’intelligence artificielle, apparence et identité de l’entreprise.

Le transfert se fait en ligne de commande, sur les serveurs. Il produit **un seul fichier zip**.

## Conditions

- **Même version de Sillage** des deux côtés, avec `php bin/console.php migrate` lancé sur chacun. L’import compare la liste exacte des migrations appliquées et refuse toute différence.
- **Même `APP_KEY`** : reprendre la valeur du `.env` du site d’origine dans celui du nouveau site *avant* l’import. Les jetons Git, secrets de webhooks et clés d’IA restent chiffrés dans l’archive ; sans la même clé, ils seraient illisibles. L’archive contient seulement une empreinte non réversible de la clé, pour vérifier la correspondance.
- **Site de destination neuf** : base migrée, sans aucun utilisateur, projet ni ticket. Ne pas lancer `admin:create` ni `seed` avant l’import.
- Extension PHP `zip` installée (incluse dans l’image Docker).

## 1. Export sur le site d’origine

```sh
php bin/console.php site:export
```

Le fichier `sillage-site-<date>-<suffixe>.zip` est créé dans `storage/exports/`, ou dans le dossier donné en argument (`site:export /chemin/prive`). Les données sont lues dans un instantané cohérent de la base, sans bloquer les utilisateurs ; pour un transfert définitif, prévenir les utilisateurs et éviter les modifications pendant l’opération.

Avec Docker, récupérer le fichier hors du conteneur, par exemple :

```sh
docker compose cp app:/var/www/html/storage/exports/<fichier>.zip .
```

Une pièce jointe enregistrée en base mais absente du disque est signalée et ignorée.

## 2. Import sur le nouveau site

```sh
php bin/console.php migrate
php bin/console.php site:import /chemin/vers/sillage-site-<date>-<suffixe>.zip
```

Avant d’écrire quoi que ce soit, l’import vérifie le format, la clé, la version, les noms de tables et de colonnes, ainsi que l’empreinte SHA-256 de chaque fichier de l’archive. Les données sont ensuite remplacées en **une seule transaction** : en cas d’erreur, la base reste vide. Les pièces jointes sont préparées dans un dossier temporaire de `storage/`, puis déplacées dans `storage/uploads` après validation.

L’archive n’est jamais exécutée comme du SQL : chaque valeur est insérée par requête préparée, dans des tables et colonnes qui doivent exister sur le site de destination.

## 3. Après l’import

- Chaque utilisateur se **reconnecte** avec son mot de passe habituel.
- Si l’adresse du site change :
  - mettre à jour `APP_URL` ;
  - mettre à jour l’URL des **webhooks** chez GitHub ou Bitbucket (`/webhooks/<numéro>`, numéros inchangés) ;
  - reconfigurer les **clients OAuth** (ChatGPT ou autre client MCP), dont les autorisations dépendent de l’adresse du serveur.
- Vérifier quelques tickets, pièces jointes et paramètres, puis **supprimer le fichier zip** des deux serveurs.

## Ce qui n’est pas transféré

| Élément | Raison |
|---|---|
| Connexions mémorisées (30 jours) | Liées au site d’origine ; chacun se reconnecte. |
| Liens de validation d’e-mail et de réinitialisation en cours | Courte durée ; en redemander un si besoin. |
| Limites de tentatives | Temporaires. |
| Journaux techniques | Diagnostic propre au site d’origine. |
| Codes OAuth en cours d’échange | Durée de quelques minutes. |
| État du processus de synchronisation Git | Recréé par le nouveau site. |

## Sécurité

Le fichier zip contient **des données personnelles** : comptes, adresses e-mail, tickets, commentaires et pièces jointes. Il est créé lisible par son seul propriétaire. Le conserver hors du dossier public, le transmettre par un canal sûr et le supprimer une fois l’import vérifié.

## Limites

- Le transfert vise un site **neuf**. Fusionner des projets dans un site qui contient déjà des données n’est pas pris en charge.
- Les deux sites doivent être exactement à la même version. Mettre d’abord à jour le site d’origine si nécessaire.
- La sauvegarde technique `bin/backup.php` reste l’outil de sauvegarde courante ; le transfert de site sert au déménagement.
