<?php /**
 * Ticket page panel #activity: comment count and threaded list, one ticket-conversation-comment.php
 * per root comment (replies are rendered nested by that partial). Included by ticket.php.
 * Expects $comments (all comments, for the count), $commentRoots and $commentReplies from
 * Ticket::commentThreads(), the project order in $project['comment_order'], plus $ticket and $canEdit.
 */ ?>
<section class="panel ticket-conversation" id="activity" aria-labelledby="ticket-conversation-title"><h2 id="ticket-conversation-title">Suivi des commentaires <span class="ticket-comment-count">(<?= count($comments) ?>)</span></h2>
<div class="comment-list" data-comment-list data-comment-order="<?= ($project['comment_order']??'asc')==='desc'?'desc':'asc' ?>"><?php foreach($commentRoots as $c) require ROOT.'/app/Views/partials/ticket-conversation-comment.php'; ?></div><?php if(!$comments): ?><p class="muted" data-no-comments>Aucun commentaire pour le moment.</p><?php endif; ?>
</section>
