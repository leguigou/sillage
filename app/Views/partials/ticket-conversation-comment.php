<?php /**
 * One comment of the ticket conversation; user_id null means an automatic (Git/system)
 * message. Included in a loop by ticket-conversation.php and rendered alone by
 * TicketDevelopmentController::publishTestProcedure (returned as HTML in JSON).
 * Expects $c (comment row), $ticket and $canEdit.
 * The body is printed without e(): App\Services\Conversation::render() is responsible for
 * escaping plain text and sanitising HTML bodies.
 */ ?>
<article class="comment<?= $c['user_id']===null?' comment-system':'' ?>" id="comment-<?= (int)$c['id'] ?>">
<?php if($c['user_id']===null): ?><span class="avatar small" aria-hidden="true"><?= icon('git-pull') ?></span><?php else: ?><?= avatar($c['name'],'small',(int)$c['user_id']) ?><?php endif; ?>
<div><strong><?= e($c['name']) ?></strong><?php if($c['message_kind']??null): ?> <span class="git-kind-pill">Automatique</span><?php endif; ?><small><?= e($c['created_at']) ?></small><div class="conversation-content"><?= \App\Services\Conversation::render($c) ?></div>
<?php if(($c['message_kind']??'')==='staging_merged' && $c['git_event_id'] && $canEdit && !$ticket['archived']): ?><button type="button" class="button subtle small-button" data-test-procedure="<?= (int)$c['git_event_id'] ?>">Rédiger la procédure de test</button><?php endif; ?></div></article>
