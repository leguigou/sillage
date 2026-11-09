# Wiki — spécification du lot 1

Statut : **lot 1 développé** sur la branche `feat/wiki` (version 1.26.0, non encore livrée). Prototype de l’éditeur et du rendu réalisé le 6 novembre 2026 (§ 6.2 et § 7.2). Le guide d’utilisation et de maintenance est [wiki.md](wiki.md). Ce document décrit le premier lot du module Wiki : une documentation interne rangée en dossiers, rédigée dans un éditeur visuel, enregistrée en fichiers Markdown (`.md`) sur disque, affichée en HTML, recherchable, avec un historique des modifications (qui, quand, quoi).

Les lots suivants (images, liens entre pages, import de documents, outils MCP, droits par dossier) sont listés en fin de document pour situer les choix, mais ne font pas partie de ce lot.

## 1. Hypothèses à confirmer

Ces trois points changent la conception. Les choix ci-dessous sont ceux retenus par défaut.

| # | Question | Hypothèse retenue | Conséquence si elle est fausse |
| --- | --- | --- | --- |
| H1 | Le wiki est-il commun à toute l’installation ou propre à chaque projet ? | **Commun** à l’installation. Le paramètre `?project=` des URL sert seulement à garder le contexte du menu. | Ajouter un `project_id` aux pages et un dossier racine par projet. |
| H2 | Les fichiers `.md` seront-ils modifiés hors de Sillage (éditeur de texte, partage réseau) ? | **Possible, mais pas encouragé.** Sillage détecte ces modifications et les inscrit dans l’historique avec l’auteur « Modification externe ». | Sans modifications externes, la détection reste utile comme filet de sécurité ; elle n’est pas retirée. |
| H3 | Faut-il des dossiers confidentiels dès le départ ? | **Non.** Toute personne connectée lit tout le wiki, comme pour les projets aujourd’hui. | Les droits par dossier deviennent un prérequis : le lot 1 ne doit alors pas accueillir de documents RH, financiers ou personnels. |

Tant que H3 n’est pas levée, la page d’accueil du wiki affiche un rappel : pas de mots de passe, de données personnelles ni de documents confidentiels.

## 2. Périmètre du lot 1

Inclus :

1. Entrée **Wiki** dans le menu principal.
2. Arborescence de dossiers et sous-dossiers : créer, renommer, déplacer, supprimer un dossier vide.
3. Pages : créer, afficher, modifier, renommer, déplacer, supprimer (avec conservation de l’historique).
4. Éditeur visuel (WYSIWYG) avec bascule vers le Markdown brut.
5. Aide à la rédaction par l’IA, en réutilisant les profils LLM existants.
6. Rendu HTML sécurisé, avec sommaire automatique.
7. Historique par page : liste des versions, comparaison (diff) entre deux versions, restauration.
8. Recherche plein texte dans les titres et contenus.
9. Détection des modifications faites hors de Sillage.
10. Téléchargement du fichier `.md` d’une page.

Exclus du lot 1 (voir § 17) : images et pièces jointes dans les pages, liens `[[Page]]` et rétroliens, modèles de page, corbeille dédiée, abonnements, import de documents, outils MCP, droits par dossier.

## 3. Stockage

### 3.1 Emplacement

- Variable d’environnement `WIKI_PATH`, par défaut `storage/wiki`. Ce dossier est déjà dans le volume Docker `sillage_storage`.
- Le dossier est créé au premier usage avec les droits `0700`, comme `storage/uploads`.
- **Sauvegarde** : `bin/backup.php` ne sauvegarde que la base MySQL. Les révisions en base permettent de reconstruire les fichiers (commande `wiki:rebuild`, § 9.3), mais la procédure de sauvegarde du volume `storage` doit être documentée dans `docs/wiki.md`.

### 3.2 Répartition fichiers / base

| Élément | Fichier `.md` | MySQL |
| --- | --- | --- |
| Contenu actuel | **Référence** | Copie dans la dernière révision |
| Arborescence | **Référence** (dossiers réels) | Chemin de chaque page |
| Historique | — | **Référence** (copie complète à chaque version) |
| Index de recherche | — | Texte brut + titre (`FULLTEXT`) |
| Auteur, dates | — | Oui |

Une copie complète par version (et non un diff stocké) garde la restauration simple et fiable. Avec une taille maximale de 512 Ko par page, le volume reste maîtrisé ; une compression pourra être ajoutée plus tard sans changer le modèle.

### 3.3 Règles sur les noms et chemins

Les fichiers restent lisibles par un humain qui parcourt le dossier : le nom du fichier est le titre de la page (`Clôture de caisse.md`), pas un identifiant.

- Encodage UTF-8, normalisation Unicode NFC (les accents saisis sur macOS et Windows donnent le même nom).
- Caractères interdits : `\ / : * ? " < > |`, caractères de contrôle, octet nul.
- Interdits : nom vide, `.` ou `..`, nom commençant par `.` ou `_` (réservés : `_assets` au lot 2), point ou espace final, noms réservés Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1`…`COM9`, `LPT1`…`LPT9`).
- Longueur : 120 caractères par segment, 500 pour le chemin complet, 8 niveaux de dossiers.
- **Unicité insensible à la casse** : `Caisse.md` et `caisse.md` sont refusés ensemble, sinon le wiki casserait en passant de Linux à Windows.
- Seuls les fichiers `.md` sont indexés ; les autres fichiers du dossier sont ignorés.

### 3.4 Sécurité des chemins

Tout chemin reçu du navigateur est relatif à `WIKI_PATH`. Le service de stockage :

- refuse les chemins absolus, les segments `..` et les octets nuls ;
- vérifie après résolution (`realpath` du parent) que la cible reste sous `WIKI_PATH` ;
- refuse de suivre les liens symboliques (lecture, écriture et parcours) ;
- n’accepte jamais un chemin comme identifiant dans une URL de lecture : les pages sont désignées par leur identifiant en base.

## 4. Modèle de données

Migration `database/028_wiki.sql`.

```sql
CREATE TABLE IF NOT EXISTS wiki_pages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 path VARCHAR(500) NOT NULL,                -- relatif à WIKI_PATH, séparateur « / », NFC
 path_key VARCHAR(500) NOT NULL,            -- path en minuscules, pour l’unicité insensible à la casse
 title VARCHAR(255) NOT NULL,
 body_text MEDIUMTEXT NOT NULL,             -- texte brut extrait du rendu, pour la recherche
 content_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 file_mtime BIGINT NULL, file_size INT UNSIGNED NULL,  -- contrôle rapide des modifications externes
 current_revision_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
 updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 active_path_key VARCHAR(500) AS (IF(deleted_at IS NULL, path_key, NULL)) STORED,
 UNIQUE(active_path_key),
 FULLTEXT(title, body_text),
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wiki_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 action VARCHAR(16) NOT NULL,               -- create, edit, move, delete, restore, external
 source VARCHAR(16) NOT NULL,               -- application, external (puis mcp, import aux lots suivants)
 path_before VARCHAR(500) NULL,
 path_after VARCHAR(500) NULL,
 content MEDIUMTEXT NOT NULL,               -- copie complète du Markdown après l’action
 content_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 lines_added INT UNSIGNED NOT NULL DEFAULT 0,
 lines_removed INT UNSIGNED NOT NULL DEFAULT 0,
 message VARCHAR(255) NULL,                 -- commentaire facultatif de l’auteur
 user_id BIGINT UNSIGNED NULL,
 user_name VARCHAR(190) NOT NULL,           -- nom figé au moment de la modification
 created_at DATETIME NOT NULL,
 UNIQUE(page_id, revision_no),
 KEY(page_id, created_at),
 FOREIGN KEY(page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes :

- `active_path_key` permet de recréer une page au chemin d’une page supprimée sans perdre l’historique de l’ancienne.
- Les dossiers ne sont pas en base : ils sont lus sur disque. Un dossier vide est donc visible tant qu’il existe.
- `user_name` est figé, comme dans `ticket_history`, pour qu’un historique reste lisible après le départ d’une personne.
- Le titre est le premier titre de niveau 1 du document, sinon le nom du fichier sans `.md`.

## 5. Écran et parcours

### 5.1 Navigation

- Nouvelle entrée `wiki` dans `$nav` de `app/Views/layout.php`, libellé **Wiki**, après **Versions et livraisons**. Une icône `book` est à ajouter dans `app/Views/icons.php` (aucune icône adaptée n’existe).
- Mise en page en deux colonnes : arborescence repliable à gauche, page à droite. Sur mobile, l’arborescence passe dans un panneau.
- Fil d’Ariane : Wiki › Dossier › Sous-dossier › Page.

### 5.2 Accueil du wiki (`/wiki`)

- Barre de recherche.
- Dernières pages modifiées (20), avec auteur et date.
- Rappel sur les contenus interdits (§ 1, H3).
- Boutons **Nouvelle page** et **Nouveau dossier** (membres et administrateurs).

### 5.3 Lecture d’une page

- Rendu HTML, sommaire généré à partir des titres de niveau 2 et 3 (ancres stables).
- Bandeau : dernière modification par *Nom*, le *date*, lien **Historique (n versions)**.
- Actions : **Modifier**, **Renommer / déplacer**, **Télécharger le .md**, **Supprimer**.
- Si une modification externe vient d’être détectée à l’ouverture, un message l’indique.

### 5.4 Modification

- Éditeur visuel par défaut ; bouton **Markdown** pour basculer en texte brut et revenir.
- Champ facultatif **Décrire la modification** (devient le message de la version).
- **Enregistrer** / **Annuler**. Quitter avec des changements non enregistrés demande confirmation via `window.SillageDialogs.confirm` (et `beforeunload` pour la fermeture de l’onglet). Aucun `window.confirm`.
- Raccourci `Ctrl+S` pour enregistrer.

### 5.5 Création, déplacement, suppression

Les dialogues passent par `SillageDialogs` :

- **Nouvelle page** : titre + dossier de destination ; ouvre directement l’éditeur.
- **Renommer / déplacer** : nouveau titre et/ou nouveau dossier. Le premier titre de niveau 1 n’est pas réécrit automatiquement dans le contenu.
- **Supprimer une page** : confirmation ; le fichier est retiré du disque, la page est marquée supprimée, l’historique est conservé.
- **Supprimer un dossier** : uniquement s’il est vide.

## 6. Éditeur

### 6.1 Contraintes imposées par Sillage

- **Politique de sécurité du navigateur (CSP)** : `script-src 'self'` et `style-src 'self'`. L’éditeur doit être **copié dans `public/assets/vendor/`**, sans CDN, et ne doit pas dépendre de balises `<style>` injectées ni d’attributs `style` dans le HTML. Les styles appliqués par JavaScript via `element.style` restent autorisés.
- **Pas de chaîne de compilation** front (pas de `npm`, pas de bundler) : il faut une version distribuée en un seul fichier.
- Thèmes clair et sombre de Sillage.

### 6.2 Résultats du prototype (6 novembre 2026)

Prototype hors dépôt : les trois éditeurs ont été chargés dans une page servie avec **exactement** l’en-tête CSP de `Security::contentSecurityPolicy()`, sur un document de test (titres, listes imbriquées, cases à cocher, tableau aligné, code, citation, liens, accents, `&`). Mesures : violations CSP relevées par l’événement `securitypolicyviolation`, aller-retour Markdown → éditeur → Markdown sans modification, puis une saisie réelle au clavier (texte, gras).

| Critère | Toast UI Editor 3.2.2 | TipTap 3.32.0 + `@tiptap/markdown` | **Milkdown Crepe 7.22.3** |
| --- | --- | --- | --- |
| Dernière publication | Février 2023 (abandonné de fait) | Active | Active |
| Licence | MIT | MIT | MIT |
| Violations CSP | **19** (attributs `style`) : onglets « Écrire / Aperçu » et infobulle visibles à tort, hauteur ignorée | 1 (balise `<style>` injectée ; option `injectCSS: false` disponible) | **0** |
| Aller-retour Markdown | Sens conservé ; réécrit puces (`*`), tableau, `***`, liens automatiques | **`&` devient `&amp;` dans le fichier** ; réécrit tableau et liens automatiques | Sens conservé ; réécrit puces (`*`), espacement du tableau, `***` |
| Cases à cocher, tableaux alignés | Oui | Oui | Oui |
| Barre d’outils | Fixe, complète, traduite en français | **Aucune** : à développer entièrement | Fixe (`TopBar`) + menu de sélection ; libellés en anglais, configurables |
| Saisie réelle (texte, gras, `&`) | — | — | Correcte |
| Poids chargé (compressé gzip) | ~170 Ko | ~155 Ko (sans barre d’outils) | ~480 Ko (configuration allégée) |

**Décision validée le 6 novembre 2026 : Milkdown Crepe**, seul candidat sans aucune violation CSP et sans perte de données, avec une barre fixe adaptée à des personnes non techniques. Toast UI est écarté (abandonné, interface cassée par la CSP). TipTap reste l’alternative si le poids devient un problème, mais il faudrait développer la barre d’outils et corriger la sérialisation de `&`.

Configuration retenue : fonctionnalités `Latex`, `CodeMirror` et `AI` désactivées (l’aide IA passe par les profils LLM de Sillage, § 6.4), `TopBar` activée. Le fichier n’est chargé que sur l’écran d’édition, jamais en lecture.

### 6.3 Conséquences de ce choix

- **Préparation du fichier de l’éditeur** : Milkdown n’est pas distribué en un seul fichier. Il faut une petite étape de préparation (esbuild) : un dossier `tools/wiki-editor/` avec `package.json` et `package-lock.json` aux versions exactes, et un script qui produit `public/assets/vendor/milkdown/` (modules ES `type="module"`, autorisés par `script-src 'self'`). **Les fichiers produits sont commités** : Node n’est nécessaire que pour mettre à jour l’éditeur, jamais pour installer ou déployer Sillage. Cette exception à « pas de chaîne de compilation » a été acceptée par le mainteneur le 6 novembre 2026 ; elle est limitée à l’éditeur du wiki.
- Mise à jour de l’éditeur : `npm ci --ignore-scripts`, versions publiées depuis au moins deux semaines, relecture du diff du dossier `vendor`.
- **Traduction** : les libellés (titres, barre, menus) sont à fournir en français par configuration ; vérifier pendant le développement que tous le sont (infobulles, menu `/`).
- **Thème sombre** : Crepe fournit un thème clair et un thème sombre ; les relier aux variables de thème de Sillage plutôt que charger deux feuilles.
- **Réécriture du Markdown** : comme tous les éditeurs testés, Crepe normalise la syntaxe à l’enregistrement (puces `*`, alignement des tableaux, `***`). Un fichier écrit hors de Sillage aura donc, à sa première modification dans l’éditeur, un diff plus large que le changement réel. D’où l’option **Ignorer la mise en forme** du diff (§ 10).

Repli si Milkdown pose problème en développement : TipTap avec une barre d’outils maison, soit plusieurs jours de développement supplémentaires.

### 6.4 Aide à la rédaction (IA)

Uniquement si un profil LLM par défaut est actif, et pour les membres et administrateurs :

- Sur la sélection ou la page : **Reformuler**, **Résumer**, **Corriger l’orthographe**, **Structurer en procédure numérotée**.
- Même fonctionnement que dans les tickets (`docs/intelligence-artificielle.md`) : le dialogue montre le fournisseur et le texte envoyé avant **Générer** ; la proposition est insérée seulement sur **Insérer la proposition** ; rien n’est enregistré sans **Enregistrer**.
- Limite existante de 6 000 caractères en entrée : au-delà, l’action porte sur la sélection uniquement et le dialogue l’explique.
- Les appels passent par le client LLM existant, déjà suivi dans le journal technique.

## 7. Rendu Markdown → HTML

### 7.1 Syntaxe prise en charge

CommonMark + tableaux et texte barré (style GitHub) : titres, gras, italique, listes, citations, code en ligne et en bloc, liens, séparateurs, tableaux. Les images sont affichées comme liens au lot 1 (prises en charge au lot 2). Ce qui n’est pas représentable en Markdown (couleurs, cellules fusionnées, mise en page) n’est pas proposé dans l’éditeur.

### 7.2 Moteur

- **Parsedown 1.8.0** (licence MIT, publiée en février 2026), un seul fichier copié dans le dépôt car il n’y a pas de Composer, utilisé en **mode sécurisé**. Fichier vérifié identique au commit officiel `96baaad00f71ba04d76e45b4620f54d3beabd6f7` (SHA-256 `34075a6f176841dbb91ca6a26ac7455b5bb576e368cfadb8249edb79d67b1a06`).

Résultats du prototype :

- **Sécurité** : 27 tentatives d’injection testées (balises `<script>`, `<svg>`, `<iframe>`, attributs `on…`, liens `javascript:`, `vbscript:`, `data:`, encodés en entités, en majuscules, avec espaces, en référence, dans un tableau, un titre de lien, un texte alternatif, une langue de bloc de code), analysées sur le HTML produit : **aucune n’aboutit**.
- **Compatibilité** : aucun avertissement sous PHP 8.1 ; contrôle du code pour PHP 8.4 (types nullables explicites, propriétés déclarées) sans problème. Le test n’a pas pu être exécuté sous PHP 8.4, absent du poste : à refaire dans l’image Docker.
- **Performance** (PHP 8.1, sans cache) : 12 ms pour 10 Ko, 140 ms pour 100 Ko, 700 ms pour 500 Ko, croissance linéaire. Des entrées volontairement pathologiques (5 000 niveaux de citation, 20 000 crochets) prennent 0,6 à 1,5 s.
- **Non pris en charge** : cases à cocher (`- [ ]` reste du texte), identifiants des titres (nécessaires au sommaire).

### 7.3 Règles de sortie

- Aucun HTML venant du document n’est rendu tel quel (protection contre l’injection de script).
- Liens externes : `rel="noopener noreferrer"` et ouverture dans un nouvel onglet.
- Aucun attribut `style` produit (CSP). Parsedown écrit `style="text-align: …"` sur les cellules de tableaux alignés, ce que la CSP bloque : un post-traitement (DOM, extension `dom` déjà installée) le remplace par une classe `align-left|center|right`.
- Post-traitement également pour : identifiants des titres (sommaire), cases à cocher affichées en lecture seule (`- [ ]` / `- [x]`, produites par l’éditeur), liens externes.
- Le rendu produit aussi : le sommaire (titres 2 et 3) et le texte brut pour l’index de recherche.
- **Le HTML est calculé à l’enregistrement et mis en cache** (colonne `html` de `wiki_pages`, ou fichier dans `storage/cache/wiki`), pas à chaque lecture : une page de 500 Ko coûte environ 0,7 s à rendre. Le cache est recalculé quand le fichier change (détection § 9) ou quand la version du moteur de rendu change.
- Taille maximale d’une page ramenée à **512 Ko** (au lieu de 1 Mo).

## 8. Enregistrement et conflits

### 8.1 Ordre des opérations

Le fichier et la base ne peuvent pas être écrits dans une seule transaction. L’ordre retenu reprend la règle déjà appliquée aux pièces jointes (toucher au fichier définitif seulement après la validation en base) :

1. Normaliser le contenu : UTF-8 sans BOM, fins de ligne LF, taille ≤ 512 Ko.
2. Écrire le contenu dans un fichier temporaire du même dossier.
3. Transaction : verrouiller la page (`SELECT … FOR UPDATE`), vérifier la version de départ (§ 8.2), insérer la révision, mettre à jour `wiki_pages`.
4. Valider la transaction, puis renommer atomiquement le fichier temporaire vers le fichier définitif.
5. En cas d’échec avant validation : supprimer le fichier temporaire, rien n’a changé.

Si le renommage échoue après validation, le fichier sur disque correspond à la version **précédente** connue. La détection (§ 9) reconnaît ce cas grâce aux empreintes et réécrit le fichier à partir de la dernière révision, au lieu de l’enregistrer comme modification externe.

### 8.2 Modifications simultanées

- L’éditeur envoie le numéro de la version sur laquelle il est parti.
- Si une autre version a été enregistrée entre-temps, l’enregistrement est refusé (HTTP 409). L’écran affiche le diff entre la version de départ et la version actuelle, et propose : **Copier mon texte** (pour le réintégrer à la main) ou **Écraser malgré tout** (crée une nouvelle version ; rien n’est perdu puisque l’autre version reste dans l’historique).
- Pas de verrou de page ni d’indicateur « en cours de modification » au lot 1.

## 9. Modifications externes

### 9.1 À l’ouverture d’une page

Contrôle rapide sur la date et la taille du fichier ; si elles diffèrent, calcul de l’empreinte SHA-256 :

| Situation | Action |
| --- | --- |
| Empreinte identique à la base | Rien |
| Empreinte de la version précédente (écriture interrompue) | Réécriture du fichier depuis la dernière révision |
| Autre contenu | Nouvelle révision `external`, auteur « Modification externe » |
| Fichier absent | Page signalée « fichier introuvable » ; pas de suppression automatique à l’ouverture |
| Fichier non UTF-8 | Page affichée en erreur, aucun écrasement ; à corriger par un administrateur |

### 9.2 Commande d’analyse

`php bin/console.php wiki:scan` parcourt tout `WIKI_PATH` :

- crée les pages pour les nouveaux fichiers `.md` (révision `external`) ;
- enregistre les fichiers modifiés ;
- marque supprimées les pages dont le fichier a disparu (révision `delete`, source `external`) ;
- affiche un rapport : fichiers refusés (nom invalide, encodage, taille), doublons de casse.

Un bouton **Analyser le dossier** dans la page d’accueil du wiki lance la même opération pour les administrateurs.

### 9.3 Reconstruction

`php bin/console.php wiki:rebuild` réécrit sur disque les fichiers des pages actives à partir de leur dernière révision, dans un dossier vide indiqué. Ne remplace jamais un dossier existant non vide.

## 10. Historique et diff

- Liste par page : n° de version, date, auteur, action, message, lignes `+n / −n`.
- **Comparer** deux versions, par défaut une version et la précédente : diff ligne à ligne, avec mise en évidence des mots modifiés dans les lignes changées. Vue unifiée (numéros de l’ancienne et de la nouvelle ligne), identique sur grand écran et sur mobile ; une vue côte à côte pourra venir plus tard.
- Le diff porte sur le Markdown source (fiable et exact). Un diff « visuel » sur le rendu n’est pas prévu.
- Option **Ignorer la mise en forme** : compare après normalisation (espaces de fin, puces `-`/`*`, alignement des colonnes de tableaux, séparateurs `---`/`***`), pour masquer les réécritures de l’éditeur (§ 6.3).
- Implémentation : service `App\Services\TextDiff` (plus longue sous-suite commune, après retrait des lignes identiques en début et en fin), sans dépendance externe. Garde-fou : au-delà de 9 millions de cellules comparées (environ 3 000 lignes modifiées de chaque côté), affichage d’un résumé avec les nombres de lignes et des liens vers les deux versions.
- **Restaurer cette version** : crée une nouvelle version `restore` avec ce contenu ; aucune version n’est effacée.
- Le diff et la restauration fonctionnent aussi pour une page supprimée (accessible depuis une liste **Pages supprimées** réservée aux administrateurs ; la corbeille complète vient au lot 2).

## 11. Recherche

- Champ en haut du wiki, résultats paginés : titre, chemin, extrait avec les termes surlignés, date de modification.
- Index `FULLTEXT` MySQL en mode booléen avec recherche par préfixe (`clôt*`), filtre facultatif sur un dossier et ses sous-dossiers.
- La collation `utf8mb4_unicode_ci` rend la recherche insensible à la casse et aux accents.
- Limites connues à documenter : mots de moins de 3 caractères ignorés par défaut (`innodb_ft_min_token_size`), pas de racinisation française (« procédure » ne trouve pas « procédures » sans le `*`). Le lot 3 ajoutera la recherche assistée par l’IA.
- Intégration à la recherche globale (`/`) : hors lot 1, à décider.

## 12. Droits

| Action | Lecture seule | Membre | Administrateur |
| --- | --- | --- | --- |
| Lire, rechercher, historique, diff, télécharger | Oui | Oui | Oui |
| Créer, modifier, renommer, déplacer une page | — | Oui | Oui |
| Restaurer une version | — | Oui | Oui |
| Supprimer une page | — | Oui | Oui |
| Créer, renommer, déplacer un dossier | — | Oui | Oui |
| Supprimer un dossier vide | — | — | Oui |
| Pages supprimées, analyse du dossier | — | — | Oui |

Contrôles côté serveur avec `Auth::requireUser()`, `Auth::editor()` et `Auth::admin()` ; CSRF vérifié par le routeur sur tous les `POST`. Point à valider : faut-il réserver la suppression de page aux administrateurs ?

## 13. Organisation technique

### 13.1 Routes (`routes/web.php`)

```text
GET  /wiki                                  accueil, arborescence, dernières pages
GET  /wiki/search?q=&folder=                recherche
GET  /wiki/pages/{id}                       lecture
GET  /wiki/pages/{id}/edit                  édition
GET  /wiki/pages/{id}/raw                   téléchargement du .md
GET  /wiki/pages/{id}/history               liste des versions
GET  /wiki/pages/{id}/diff?from=&to=&format= comparaison (format=1 : ignorer la mise en forme)
GET  /wiki/pages/{id}/revisions/{rev}       lecture d’une ancienne version
GET  /wiki/deleted                          pages supprimées (administrateur)
POST /wiki/pages                            création (dossier, titre)
POST /wiki/pages/{id}                       enregistrement (contenu, version de départ, message)
POST /wiki/pages/{id}/move                  renommage / déplacement
POST /wiki/pages/{id}/delete                suppression
POST /wiki/pages/{id}/revisions/{rev}/restore
POST /wiki/folders                          création (parent, nom)
POST /wiki/folders/move                     renommage / déplacement (chemin dans le corps)
POST /wiki/folders/delete                   suppression d’un dossier vide
POST /wiki/scan                             analyse du dossier (administrateur)
```

Le routeur actuel ne capture qu’un segment par paramètre : les chemins de dossiers passent donc dans le corps ou la chaîne de requête, jamais dans le chemin de l’URL.

### 13.2 Classes

| Classe | Rôle |
| --- | --- |
| `App\Controllers\WikiController` | Routes ci-dessus, droits, réponses HTML/JSON |
| `App\Models\WikiPage` | Lecture et écriture de `wiki_pages`, recherche |
| `App\Models\WikiRevision` | Historique, restauration |
| `App\Services\WikiStorage` | Validation des noms et chemins, lecture/écriture atomique, parcours |
| `App\Services\WikiRenderer` | Markdown → HTML, sommaire, texte brut |
| `App\Services\Wiki` | Opérations coordonnant fichiers et base : création, enregistrement, déplacement, suppression, restauration, synchronisation, `wiki:scan`, `wiki:rebuild`, arborescence |
| `App\Services\WikiConflict` | Exception de conflit (version de départ dépassée) |
| `App\Services\TextDiff` | Diff ligne à ligne et mot à mot |

Vues : `app/Views/sections/wiki.php` et partiels `app/Views/partials/wiki-*.php`. Scripts : `public/assets/wiki.js`, chargé seulement quand `$section === 'wiki'`, et l’éditeur dans `public/assets/vendor/`. Styles dans `app.css` (ou `wiki.css` chargé de la même façon).

### 13.3 Documentation et journal des versions

- Nouveau guide `docs/wiki.md` : usage, emplacement des fichiers, sauvegarde, modifications externes, limites de la recherche.
- Entrée dans `resources/application-history.json` : version **mineure** (nouvelle fonctionnalité), vérifiée par `php bin/console.php history:check`.
- Paragraphe « Wiki » dans `AGENTS.md` : stockage de référence, ordre d’écriture, règles de chemins, interdiction du HTML brut.
- `.env.example` : `WIKI_PATH`.

## 14. Tests

Sur la base isolée `sillage_test` et un `WIKI_PATH` temporaire :

- `tests/wiki_files.php` (sans base) et `tests/wiki.php` (avec base) : validation des noms (caractères interdits, noms réservés, casse, longueur), refus de `..`, des chemins absolus et des liens symboliques ; écriture atomique ; échec simulé après validation puis réparation ; détection externe (création, modification, suppression, non UTF-8) ; diff (ajouts, suppressions, lignes modifiées, garde-fou) ; restauration ; rendu sécurisé (`<script>`, `javascript:`, `onerror=`).
- `tests/http_wiki.py` : parcours complet création → modification → historique → diff → restauration ; conflit 409 entre deux sessions ; refus des actions d’écriture pour un compte en lecture seule ; CSRF ; recherche avec accents et préfixe.
- Vérification manuelle dans le navigateur : éditeur sans erreur CSP dans la console, thèmes clair et sombre, mobile.

## 15. Critères d’acceptation

1. Une page créée dans Sillage apparaît comme fichier `.md` lisible dans le dossier configuré, au bon emplacement.
2. Chaque enregistrement crée une version avec auteur et date ; le diff entre deux versions montre exactement les lignes changées.
3. Restaurer une ancienne version ne supprime aucune version.
4. Deux personnes qui modifient la même page ne s’écrasent pas sans le savoir.
5. Un fichier modifié à la main dans le dossier est détecté et apparaît dans l’historique comme modification externe.
6. Aucun chemin envoyé par le navigateur ne permet de lire ou d’écrire hors du dossier wiki.
7. Un contenu contenant du HTML ou du JavaScript s’affiche comme texte, sans s’exécuter.
8. La recherche trouve une page par un mot de son titre ou de son contenu, avec ou sans accents.
9. Un compte en lecture seule ne peut rien modifier, même en appelant les URL directement.
10. Aucune erreur CSP dans la console du navigateur ; la CSP n’est pas assouplie.

## 16. Ordre de réalisation proposé

1. ~~Prototype de l’éditeur et du moteur Markdown sous la CSP réelle~~ : fait le 6 novembre 2026 (§ 6.2, § 7.2). Étape de préparation de l’éditeur validée (§ 6.3). Reste à refaire le test Parsedown sous PHP 8.4.
2. Mise en place de `tools/wiki-editor/` et de `public/assets/vendor/milkdown/`, puis migration, `WikiStorage` et ses tests de sécurité des chemins.
3. Lecture, arborescence, rendu, création et modification avec révisions.
4. Historique, `TextDiff`, restauration, conflits.
5. Recherche.
6. Détection externe, `wiki:scan`, `wiki:rebuild`.
7. Aide IA dans l’éditeur.
8. Documentation, journal des versions, tests HTTP, recette manuelle.

## 17. Lots suivants (pour mémoire)

- **Fait en avance du lot 2** (même version 1.26.0) : images collées (table `wiki_assets` + copies `_assets/`), encadrés `> [!NOTE]`. Voir [wiki.md](wiki.md).
- **Lot 2** : liens `[[Page]]` et rétroliens, redirections après renommage, modèles de page, corbeille, abonnements et notifications, brouillons enregistrés côté serveur, date de relecture et rappel.
- **Fait (version 1.27.0)** : import des exports Word de Confluence, MHTML, HTML et Markdown, par un convertisseur PHP dédié plutôt que Pandoc (emojis et macros Confluence reconnus). Voir [wiki.md](wiki.md).
- **Lot 3** : import DOCX, ODT, RTF via Pandoc (ajouté au `Dockerfile`, exécuté en mode `--sandbox` avec délai et taille maximum, aperçu avant enregistrement, original conservé) ; recherche assistée par l’IA ; outils MCP `wiki_search` et `wiki_read`.
- **Lot 4** : PDF (texte puis OCR pour les scans), XLSX/PPTX, nettoyage par l’IA avec découpage, droits par dossier.
