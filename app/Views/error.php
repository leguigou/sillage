<?php /**
 * Standalone error page (full HTML document, no layout.php/guest.php wrapper, not rendered via View::render).
 * Required directly by Core/Router.php (404 for unknown routes, 422 when a GET handler throws InvalidArgumentException)
 * and by the global exception handler in app/bootstrap.php (500; real message only when APP_ENV=local).
 * Expects: $message (falls back to a generic text). Output is escaped with e().
 */ ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e(app_name()) ?> — Une pause</title><?php require ROOT.'/app/Views/partials/favicon.php'; ?><link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>"></head><body class="error-page"><main class="empty-state"><span class="brand-symbol"><?= e(app_initial()) ?></span><h1>Un instant…</h1><p><?= e($message ?? 'Une erreur est survenue.') ?></p><a class="button primary" href="/">Revenir à <?= e(app_name()) ?></a></main></body></html>
