<section class="panel ticket-conversation" id="activity" aria-labelledby="ticket-conversation-title"><h2 id="ticket-conversation-title">Suivi des commentaires <span class="ticket-comment-count">(<?= count($comments) ?>)</span></h2>
<?php foreach($comments as $c) require ROOT.'/app/Views/partials/ticket-conversation-comment.php'; if(!$comments): ?><p class="muted" data-no-comments>Aucun commentaire pour le moment.</p><?php endif; ?>
</section>
