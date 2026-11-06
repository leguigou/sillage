# Wiki

Le menu **Wiki** rassemble la documentation de l’équipe : procédures, modes opératoires, guides. Chaque page est un fichier Markdown (`.md`) rangé dans un dossier ; Sillage l’affiche en HTML, le rend recherchable et garde l’historique de ses modifications.

La spécification complète et les choix techniques sont dans [wiki-specification-lot-1.md](wiki-specification-lot-1.md).

## Utilisation

- **Nouvelle page** : un titre et un dossier ; l’éditeur s’ouvre aussitôt. Le fichier porte le nom du titre, sans les caractères `\ / : * ? " < > |`.
- **Nouveau dossier**, puis dans un dossier : **Renommer ou déplacer**, et pour un administrateur **Supprimer le dossier** (vide uniquement).
- **Modifier** : éditeur visuel avec barre d’outils, menu `/` pour insérer un bloc, tableaux et cases à cocher. Le bouton **Markdown** montre le texte brut. `Ctrl+S` enregistre. Le champ **Décrire la modification** devient le message de la version.
- **Aide à la rédaction** (si un fournisseur IA est configuré) : reformuler, résumer, corriger l’orthographe ou structurer en procédure. Le texte sélectionné, ou toute la page sans sélection (6 000 caractères au maximum), est envoyé au fournisseur affiché dans la fenêtre. La proposition est insérée seulement sur **Insérer la proposition** et n’est enregistrée qu’avec **Enregistrer**.
- **Historique** : chaque version avec son auteur, sa date, son action et le nombre de lignes ajoutées et supprimées. **Comparer** affiche les lignes et mots modifiés ; **Ignorer la mise en forme** masque les réécritures de syntaxe (puces, espaces des tableaux). **Restaurer cette version** crée une nouvelle version ; aucune n’est effacée.
- **Recherche** : titres et contenus, sans tenir compte des accents ni des majuscules ; tous les mots sont requis et un mot trouve aussi les mots qui commencent par lui. Les mots de moins de 3 lettres sont ignorés et il n’y a pas de gestion du pluriel.
- **Supprimer une page** retire son fichier. Un administrateur la retrouve dans **Pages supprimées** et peut la restaurer si son emplacement est libre.

Toutes les personnes connectées lisent tout le wiki. Il ne doit contenir ni mot de passe, ni donnée personnelle de client ou de salarié, ni document confidentiel. Les comptes en lecture seule consultent, recherchent et téléchargent ; les membres et administrateurs modifient.

## Conflits

L’éditeur envoie la version sur laquelle il est parti. Si quelqu’un a enregistré entre-temps, l’enregistrement est refusé : **Voir les différences** ouvre la comparaison, **Copier mon texte** permet de reporter ses changements à la main, **Écraser malgré tout** crée une nouvelle version avec son texte (la version de l’autre personne reste dans l’historique).

## Fichiers, sauvegarde et modifications externes

Les fichiers sont dans `WIKI_PATH` (par défaut `storage/wiki`, donc dans le volume Docker `sillage_storage`). Les noms commençant par `.` ou `_` sont réservés et ignorés. Les liens symboliques ne sont jamais suivis.

**Sauvegarde** : `bin/backup.php` sauvegarde la base, qui contient une copie complète de chaque version. Sauvegardez aussi le volume `storage`. En cas de perte des fichiers :

```bash
php bin/console.php wiki:rebuild /chemin/vers/un/dossier/vide
```

écrit la dernière version de chaque page dans ce dossier, qu’il suffit ensuite de placer comme `WIKI_PATH`.

**Transfert de site** (`site:export` / `site:import`, voir [transfert-site.md](transfert-site.md)) : les tables du wiki sont transférées, pas les fichiers. Après l’import, lancer `wiki:rebuild` vers le dossier du wiki encore vide.

Les fichiers peuvent être modifiés hors de Sillage, mais ce n’est pas recommandé. À l’ouverture d’une page, Sillage compare le fichier à sa dernière version connue : une différence est enregistrée comme **Modification externe** (sans auteur). Pour tout le dossier, y compris les fichiers ajoutés ou supprimés : **Analyser le dossier** (administrateurs) ou

```bash
php bin/console.php wiki:scan
```

Un fichier non UTF-8, au nom invalide (caractère interdit, nom réservé Windows comme `CON`, doublon ne différant que par les majuscules) ou de plus de 512 Ko est signalé et ignoré, jamais écrasé.

## Fonctionnement

| Élément | Rôle |
| --- | --- |
| `App\Services\WikiStorage` | Validation des noms et chemins, écriture atomique, parcours du dossier |
| `App\Services\Wiki` | Opérations : créer, enregistrer, déplacer, supprimer, restaurer, synchroniser, analyser, reconstruire |
| `App\Services\WikiRenderer` | Markdown → HTML (Parsedown 1.8.0 en mode sécurisé, `app/Libraries/Parsedown`) puis nettoyage compatible CSP |
| `App\Services\TextDiff` | Comparaison ligne à ligne et mot à mot |
| `App\Models\WikiPage`, `WikiRevision` | Tables `wiki_pages` (index, cache HTML, recherche) et `wiki_revisions` (historique) |
| `App\Controllers\WikiController`, `app/Views/wiki.php`, `public/assets/wiki.{js,css}` | Écrans |

Ordre d’écriture : le contenu est d’abord écrit dans un fichier temporaire du dossier cible, puis la transaction verrouille la page, vérifie la version de départ et ajoute la version ; le fichier temporaire ne remplace la page qu’après la validation. Si ce remplacement échoue, le fichier garde la version précédente : la synchronisation le reconnaît à son empreinte et le réécrit. Déplacements et suppressions font l’inverse : l’opération disque d’abord, annulée si la transaction échoue.

Le HTML est calculé à l’enregistrement et mis en cache ; changer la sortie du rendu impose d’incrémenter `WikiRenderer::VERSION`.

## Éditeur (Milkdown)

L’éditeur est préparé dans `tools/wiki-editor/` et produit `public/assets/vendor/milkdown/` (fichiers commités, avec `BUILD.json` et les licences). Node n’est nécessaire que pour mettre à jour l’éditeur :

```bash
cd tools/wiki-editor
npm ci --ignore-scripts
npm run build
```

Choisir des versions publiées depuis au moins deux semaines, garder les versions exactes dans `package.json`, relire le diff du dossier `vendor` et vérifier dans le navigateur qu’aucune violation de la politique de sécurité (CSP) n’apparaît. Les fonctionnalités LaTeX, CodeMirror, IA et images de Milkdown sont désactivées ; KaTeX et les langages CodeMirror sont remplacés par des modules vides au moment de la préparation.

## Limites du lot 1

Pas d’images ni de pièces jointes dans les pages (une image Markdown s’affiche comme un lien), pas de liens `[[Page]]`, de modèles, d’abonnements ni de droits par dossier, pas d’import de documents. Voir la section « Lots suivants » de la spécification.
