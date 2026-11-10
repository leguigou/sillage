<?php
/**
 * Minimal page shell for unauthenticated or standalone pages (no sidebar, no navigation).
 * Used by View::render() instead of layout.php when the controller passes guest=true:
 * auth, public-form, initial-project, oauth-consent and oauth-error.
 * Expects: $title and $content (the already-rendered page template, echoed unescaped on purpose).
 * Shows and then clears the flash messages stored in $_SESSION['flash'].
 */
require_once ROOT.'/app/Views/icons.php'; ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title><?= e($title) ?> — <?= e(app_name()) ?></title><?php require ROOT.'/app/Views/partials/favicon.php'; ?><script src="<?= e(asset_url('theme.js')) ?>"></script><link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>"><script src="<?= e(asset_url('dialogs.js')) ?>" defer></script><script src="<?= e(asset_url('app.js')) ?>" defer></script></head>
<body class="guest-body"><header class="guest-header"><a class="wordmark" href="/"><span class="brand-symbol"><?= e(mb_strtolower(app_initial())) ?></span><?= e(app_name()) ?><span class="brand-dot">.</span></a><div class="header-right"><span class="company-icon guest-company-icon"><?= App\Core\Company::mark() ?></span><span class="eyebrow">L’ESPACE DE TRAVAIL <?= e(App\Core\Company::name()) ?></span><button class="icon-button" data-theme-toggle aria-label="Changer de thème"><?= icon('moon') ?></button></div></header>
<?php foreach ($_SESSION['flash']??[] as $f): ?><div class="toast <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?= $content ?><footer class="guest-footer">Pensé pour avancer, ensemble. <span><?= e(App\Core\Company::name()) ?> × <?= e(app_name()) ?></span></footer></body></html>
