# Configurer les fournisseurs LLM

Dans **Paramètres → Intelligence artificielle**, un administrateur ajoute un profil : nom, fournisseur, URL de base et clé API si nécessaire. Le bouton **Tester la connexion et lister les modèles** appelle réellement le catalogue du serveur choisi. Après réussite, sélectionner un modèle puis **Enregistrer le profil**. Le test ne génère pas de texte et n’envoie aucun ticket.

| Fournisseur | URL de base initiale | Authentification | Génération |
| --- | --- | --- | --- |
| OpenAI | `https://api.openai.com/v1` | Clé Bearer | Responses, `store: false` |
| LM Studio | `http://127.0.0.1:1234/v1` | Bearer facultatif selon le serveur | Chat Completions compatible OpenAI |
| Ollama | `http://127.0.0.1:11434` | Bearer facultatif selon le serveur | API native `/api/chat` |
| DeepSeek | `https://api.deepseek.com` | Clé Bearer | Chat Completions |
| Anthropic / Claude | `https://api.anthropic.com/v1` | En-tête `x-api-key` | Messages |
| Grok / xAI | `https://api.x.ai/v1` | Clé Bearer | Chat Completions |
| Gemini / Google | `https://generativelanguage.googleapis.com/v1beta` | En-tête `x-goog-api-key` | Generate Content |

Le catalogue vient du fournisseur : aucun modèle ou abonnement n’est imposé par Sillage. Le test confirme l’accès au catalogue, pas le quota de génération ni les capacités de chaque modèle. Choisir un modèle capable de générer du texte. Gemini est filtré selon la capacité `generateContent` annoncée. Les modèles locaux doivent être installés et le serveur API démarré.

Les URL sont modifiables. Les fournisseurs distants exigent HTTPS et une destination publique ; les passerelles locales se configurent avec LM Studio ou Ollama. HTTP est réservé aux adresses de boucle locale ou du réseau privé. Depuis PHP, `localhost` désigne la machine qui héberge Sillage : après déploiement, ce n’est plus le PC de l’utilisateur. Avec Docker, utiliser une adresse accessible depuis le conteneur PHP.

## Plusieurs profils et un modèle par défaut

Plusieurs profils peuvent coexister, y compris pour le même fournisseur. Chaque profil conserve son URL, son modèle et sa clé chiffrée avec `APP_KEY`. **Utiliser par défaut** désigne le profil utilisé par les aides de l’espace ; aucun texte n’est généré par ce changement. **Désactiver** le profil par défaut retire l’assistance tant qu’un autre profil n’a pas été choisi. **Supprimer** efface le profil et sa clé après confirmation.

**Configurer** reprend le nom, le fournisseur, l’URL et le modèle. La clé reste vide dans le navigateur. Champ vide lors d’un nouveau test : conserve la clé existante uniquement si le fournisseur et l’URL sont identiques. Un changement de destination exige une nouvelle clé pour empêcher son transfert involontaire. Après test, les informations vérifiées sont conservées au maximum dix minutes en session, sous forme chiffrée pour la clé ; l’enregistrement n’accepte qu’un modèle de ce catalogue. Un nouveau test remplace le précédent.

Chaque carte indique la dernière vérification et le dernier usage de génération réussi, en UTC. Ces dates ne garantissent pas la disponibilité actuelle du fournisseur. Les profils ne sont modifiables que par les administrateurs ; les rôles de l’espace restent inchangés.

## Aides dans les tickets

Lorsqu’un profil par défaut est actif, **Assistant** apparaît dans la barre supérieure. Les descriptions des tickets, le formulaire de création et la conversation proposent aussi **Aide à la rédaction**.

- **Rédiger une description**, **Reformuler**, **Résumer** : le texte saisi dans le dialogue est envoyé au modèle. Le bouton **Insérer la proposition** remplit le brouillon de description ; dans une conversation, il ajoute un paragraphe sans retirer le contenu existant. La création, la modification ou la publication du commentaire restent des actions séparées de l’utilisateur. Une modification du champ pendant la préparation empêche son remplacement silencieux.
- **Rechercher dans les tickets** : choisir un projet ou tous les projets et saisir une question avec des mots de sujet ou une clé de ticket. Sillage retrouve localement les correspondances lexicales, puis envoie au modèle jusqu’à 30 tickets avec leurs titres, états, priorités, responsables, équipes, avancement et des extraits de description de 1 200 caractères. Les tickets archivés sont exclus. Le résultat affiche les sources consultables et la couverture. Cette recherche n’est pas une recherche sémantique exhaustive ; reformuler la question peut retrouver d’autres tickets.

Les membres en lecture seule disposent de la recherche, mais pas des aides à la rédaction. Aucun fournisseur n’est appelé en arrière-plan. Le dialogue affiche le destinataire et les données concernées avant **Générer**. Si le fournisseur ou sa configuration a changé depuis l’affichage, la requête est refusée jusqu’au rechargement. Les clés, pièces jointes et conversations ne sont pas automatiquement ajoutées au contexte de recherche.

## Limites et exploitation

Les propositions restent du texte à relire et n’exécutent ni requête SQL produite par le modèle, ni outil, ni action sur un ticket. Les liens de sources sont construits à partir des tickets retrouvés par le serveur. Les textes générés sont insérés comme texte, sans interprétation HTML.

Les clés sont conservées en MySQL sous chiffrement AES-GCM ; sauvegarder `APP_KEY` séparément de la base. Les corps d’erreur du fournisseur ne sont pas repris dans les messages du connecteur. Les appels ne suivent pas les redirections ; les adresses DNS sont contrôlées puis fixées pour le transfert. Réponse limitée à 2 Mo, génération à 55 secondes et 2 000 tokens de sortie demandés ; saisie limitée à 6 000 caractères. Des modèles de raisonnement peuvent atteindre la limite avant de produire une réponse finale. Les catalogues paginés sont bornés à dix pages et à une fenêtre de chargement d’environ trente secondes. Les quotas et règles de traitement des données du fournisseur restent applicables.

La migration additive `012_llm_profiles.sql` crée les profils et la préférence globale. Aucune clé réelle ni aucun profil de démonstration n’est installé dans la base principale par cette migration.

Les tests locaux couvrent les formats des sept fournisseurs et des appels HTTP réels vers des serveurs de test synthétiques compatibles LM Studio et Ollama. Ils ne prouvent pas la compatibilité réelle avec chaque modèle et compte cloud. Effectuer le test avec la clé et le modèle du compte visé avant utilisation.

## Références officielles consultées

- [OpenAI : démarrage avec Responses](https://developers.openai.com/api/docs/quickstart) et [catalogue des modèles](https://developers.openai.com/api/reference/resources/models/methods/list).
- [LM Studio : endpoints compatibles OpenAI](https://lmstudio.ai/docs/developer/openai-compat).
- [Ollama : modèles](https://docs.ollama.com/api/tags) et [chat](https://docs.ollama.com/api/chat).
- [DeepSeek : modèles](https://api-docs.deepseek.com/api/list-models/) et [chat](https://api-docs.deepseek.com/api/create-chat-completion/).
- [Anthropic : modèles](https://platform.claude.com/docs/en/api/models/list) et [Messages](https://platform.claude.com/docs/en/api/messages/create).
- [xAI : catalogue](https://docs.x.ai/developers/rest-api-reference/inference/models).
- [Gemini : modèles](https://ai.google.dev/api/models) et [génération](https://ai.google.dev/api/generate-content).
