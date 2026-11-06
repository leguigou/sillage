<?php /**
 * Error page for an invalid, expired or already-used OAuth authorization request (guest layout, HTTP 422).
 * Rendered by OAuthController::invalidConsent() with guest=true.
 * Expects: optional $consentError (escaped with e()); a default explanation is shown otherwise.
 */ ?>
<main class="oauth-consent panel"><span class="eyebrow">CONNEXION MCP</span><h1>Relancez l’autorisation</h1><div class="notice error" role="alert"><?= e($consentError??'Cette demande d’autorisation a expiré, a déjà été utilisée ou n’est plus liée à votre session.') ?></div><p>Aucune nouvelle connexion n’a été autorisée par cette demande.</p><p>Fermez cette fenêtre, puis relancez la connexion depuis ChatGPT pour obtenir une nouvelle demande. Ne réutilisez pas l’ancien écran d’autorisation.</p></main>
