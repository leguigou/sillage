<?php /**
 * One comment of the ticket conversation, wrapped in a .comment-thread with its replies after it; user_id
 * null means an automatic (Git/system) message. The thread wrapper lets app.css draw the branches: a trunk
 * from the message avatar and a rounded elbow towards each reply. Included in a loop by ticket-conversation.php, recursively by itself
 * for replies, and rendered alone by TicketDevelopmentController::publishTestProcedure (returned as HTML
 * in JSON, without replies).
 * Expects $c (comment row), $ticket and $canEdit; $commentReplies (replies keyed by parent id) is optional.
 * The body is printed without e(): App\Services\Conversation::render() is responsible for
 * escaping plain text and sanitising HTML bodies.
 */ ?>
<?php $commentParent=!empty($commentStack)?end($commentStack):null; $commentThread=$commentReplies[(int)$c['id']]??[]; ?><div class="comment-thread">
<article class="comment<?= $c['user_id']===null?' comment-system':'' ?><?= !empty($c['parent_id'])?' comment-reply':'' ?><?= $commentThread?' comment-has-replies':'' ?>" id="comment-<?= (int)$c['id'] ?>" data-comment-id="<?= (int)$c['id'] ?>">
<?php if($c['user_id']===null): ?><span class="avatar small" aria-hidden="true"><?= icon('git-pull') ?></span><?php else: ?><?= avatar($c['name'],'small',(int)$c['user_id']) ?><?php endif; ?>
<div class="comment-body"><strong data-comment-author><?= e($c['name']) ?></strong><?php if($c['message_kind']??null): ?> <span class="git-kind-pill">Automatique</span><?php endif; ?><small><?= e($c['created_at']) ?></small><?php if($commentParent): ?><small class="comment-reply-to">en réponse à <?= e($commentParent['name']) ?></small><?php endif; ?><div class="conversation-content"><?= \App\Services\Conversation::render($c) ?></div>
<?php if($canEdit): ?><div class="comment-actions"><?php if(($c['message_kind']??'')==='staging_merged' && $c['git_event_id'] && $canEdit && !$ticket['archived']): ?><button type="button" class="button subtle small-button" data-test-procedure="<?= (int)$c['git_event_id'] ?>">Rédiger la procédure de test</button><?php endif; ?><button type="button" class="text-button comment-reply-button" data-comment-reply="<?= (int)$c['id'] ?>" aria-label="Répondre à <?= e($c['name']) ?>"><?= icon('comment') ?>Répondre</button></div><?php endif; ?></div></article>
<?php if($commentThread): $commentStack[]=$c; ?><div class="comment-replies" aria-label="Réponses à <?= e($c['name']) ?>"><?php foreach($commentThread as $c) require __FILE__; ?></div><?php $c=array_pop($commentStack); endif; ?></div>
