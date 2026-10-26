<?php
declare(strict_types=1);
use App\Core\Router;
use App\Controllers\{
    AuthController,
    WorkspaceController,
    TicketController,
    AttachmentController,
    TransferController,
    FormController,
    DevelopmentController,
};
$r = new Router();
$auth = new AuthController();
$work = new WorkspaceController();
$tickets = new TicketController();
$files = new AttachmentController();
$transfer = new TransferController();
$forms = new FormController();
$dev = new DevelopmentController();
$r->add('GET', '/login', fn() => $auth->page())->add('POST', '/login', [$auth, 'login']);
$r->add('GET', '/register', fn() => $auth->page('register'))->add('POST', '/register', [
    $auth,
    'register',
]);
$r->add('GET', '/forgot', fn() => $auth->page('forgot'))->add('POST', '/forgot', [$auth, 'forgot']);
$r->add('GET', '/reset', fn() => $auth->page('reset'))->add('POST', '/reset', [$auth, 'reset']);
$r->add('GET', '/verify', [$auth, 'verify'])->add('POST', '/logout', [$auth, 'logout']);
$r->add('POST', '/account/password', [$auth, 'changePassword']);
$r->add('GET', '/', fn() => $work->show('dashboard'));
foreach (
    ['list', 'board', 'timeline', 'forms', 'development', 'charts', 'settings', 'all']
    as $section
) {
    $r->add('GET', '/' . $section, fn() => $work->show($section));
}
$r->add('POST', '/projects', [$work, 'project'])
    ->add('POST', '/views', [$work, 'saveView'])
    ->add('POST', '/views/{id}/delete', [$work, 'deleteView']);
$r->add('POST', '/rules', [$work, 'rule'])
    ->add('POST', '/rules/{id}/delete', [$work, 'deleteRule'])
    ->add('POST', '/account', [$work, 'account']);
$r->add('GET', '/tickets/{id}', [$tickets, 'show'])
    ->add('POST', '/tickets', [$tickets, 'create'])
    ->add('POST', '/tickets/{id}', [$tickets, 'update']);
$r->add('POST', '/tickets/{id}/quick', [$tickets, 'quick'])
    ->add('POST', '/tickets/{id}/archive', [$tickets, 'archive'])
    ->add('POST', '/bulk', [$tickets, 'bulk']);
$r->add('POST', '/tickets/{id}/schedule', [$tickets, 'schedule']);
$r->add('POST', '/tickets/{id}/move', [$tickets, 'move']);
$r->add('POST', '/tickets/{id}/comments', [$tickets, 'comment'])
    ->add('POST', '/tickets/{id}/links', [$tickets, 'link'])
    ->add('POST', '/links/{id}/delete', [$tickets, 'unlink']);
$r->add('POST', '/tickets/{id}/attachments', [$files, 'upload'])->add('GET', '/attachments/{id}', [
    $files,
    'download',
]);
$r->add('GET', '/export', [$transfer, 'export'])
    ->add('GET', '/import', [$transfer, 'page'])
    ->add('POST', '/import/preview', [$transfer, 'preview'])
    ->add('POST', '/import', [$transfer, 'commit']);
$r->add('POST', '/forms', [$forms, 'save'])
    ->add('POST', '/forms/{id}/toggle', [$forms, 'toggle'])
    ->add('POST', '/forms/{id}/rotate', [$forms, 'rotate']);
$r->add('GET', '/f/{token}', [$forms, 'show'])->add('POST', '/f/{token}', [$forms, 'submit']);
$r->add('POST', '/connections', [$dev, 'connect'])
    ->add('POST', '/connections/{id}/sync', [$dev, 'sync'])
    ->add('POST', '/connections/{id}/delete', [$dev, 'disconnect']);
$r->add('POST', '/webhooks/{id}', [$dev, 'webhook'], false);
$r->dispatch();
