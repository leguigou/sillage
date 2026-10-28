<details class="provider-guide" id="provider-help-github" data-provider-guide="github" aria-labelledby="github-guide-title">
 <summary id="github-guide-title">Créer un jeton GitHub</summary>
 <ol>
  <li>Ouvrez <a href="https://github.com/settings/personal-access-tokens" target="_blank" rel="noopener noreferrer">Fine-grained personal access tokens</a>, puis <strong>Generate new token</strong>.</li>
  <li>Nommez-le <strong>Sillage</strong>, choisissez une expiration et le <strong>Resource owner</strong> propriétaire du dépôt.</li>
  <li>Dans <strong>Repository access → Only select repositories</strong>, sélectionnez les dépôts à connecter.</li>
  <li>Dans <strong>Repository permissions</strong>, accordez <strong>Pull requests → Read-only</strong>. <strong>Metadata → Read-only</strong> est ajouté automatiquement. Générez le jeton et copiez-le dans le champ ci-dessous.</li>
 </ol>
 <p>Dépôt attendu : <code>proprietaire/nom-du-depot</code>, par exemple <code>organisation/mon-projet</code>, sans URL complète.</p>
 <p>Si le dépôt appartient à une organisation, son approbation peut être nécessaire. <a href="https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens" target="_blank" rel="noopener noreferrer">Permissions GitHub</a></p>
</details>
<details class="provider-guide" id="provider-help-bitbucket" data-provider-guide="bitbucket" aria-labelledby="bitbucket-guide-title" hidden>
 <summary id="bitbucket-guide-title">Créer un jeton Bitbucket Cloud</summary>
 <ol>
  <li>Dans votre compte Atlassian, ouvrez <strong>Security → Create and manage API tokens</strong>, puis <strong>Create API token with scopes</strong>.</li>
  <li>Nommez-le <strong>Sillage</strong>, choisissez une expiration, puis sélectionnez l’application <strong>Bitbucket</strong>.</li>
  <li>Accordez <strong>Repositories → Read</strong> (<code>read:repository:bitbucket</code>) et <strong>Pull requests → Read</strong> (<code>read:pullrequest:bitbucket</code>).</li>
  <li>Créez le jeton et copiez-le dans le champ ci-dessous. Sillage l’utilise directement, sans demander votre adresse Atlassian.</li>
 </ol>
 <p>Dépôt attendu : <code>workspace/nom-du-depot</code>, sans URL complète.</p>
 <p>Un <strong>Repository access token</strong> est aussi accepté avec <strong>Repositories → Read</strong> et <strong>Pull requests → Read</strong>, si cette option est disponible sur votre dépôt.</p>
 <p><a href="https://support.atlassian.com/bitbucket-cloud/docs/create-an-api-token/" target="_blank" rel="noopener noreferrer">Guide Atlassian</a> · <a href="https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/" target="_blank" rel="noopener noreferrer">Permissions Bitbucket</a></p>
</details>
