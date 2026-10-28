<?php $canEdit=$user['role']!=='viewer'; ?>
<div class="ticket-detail" data-ticket-detail>
  <div class="ticket-navigation">
    <header class="ticket-heading">
      <a class="icon-button ticket-back" href="/list?project=<?= (int)$project['id'] ?>" aria-label="Revenir à la liste" title="Revenir à la liste">←</a>
      <div class="ticket-identity"><span class="ticket-key"><?= e($ticket['key']) ?></span><h1><?= e($ticket['title']) ?></h1></div>
      <div class="ticket-actions"><?php if($canEdit): ?><button type="button" class="button primary" data-ticket-comment>Commenter</button><button type="button" class="button subtle" data-open="ticket-edit-dialog">Modifier</button><?php endif; ?><button type="button" class="icon-button" aria-label="Copier le lien du ticket" title="Copier le lien" data-copy="<?= e(url('/tickets/'.$ticket['id'])) ?>"><?= icon('link') ?></button></div>
    </header>
    <nav class="ticket-tabs" aria-label="Rubriques du ticket" data-ticket-tabs><a id="ticket-tab-ticket-overview" href="#ticket-overview" data-ticket-tab="ticket-overview">Ticket et suivi</a><a id="ticket-tab-development" href="#development" data-ticket-tab="development">Développement</a></nav>
  </div>
  <?php if($ticket['archived']): ?><div class="notice ticket-archive-notice">Ce ticket est archivé.<?php if($canEdit): ?><form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/archive"><?= csrf() ?><input type="hidden" name="restore" value="1"><button class="button subtle">Restaurer le ticket</button></form><?php endif; ?></div><?php endif; ?>
  <div class="ticket-layout">
    <div class="ticket-main">
      <div class="ticket-thread" id="ticket-overview" data-ticket-panel aria-labelledby="ticket-tab-ticket-overview"><section class="panel ticket-overview" aria-labelledby="ticket-initial-title"><h2 id="ticket-initial-title">Message initial</h2><?php if($ticket['description']!==''): ?><div class="ticket-description"><?= e($ticket['description']) ?></div><?php else: ?><p class="muted">Aucune description renseignée.</p><?php endif; ?></section>
      <?php require ROOT.'/app/Views/partials/ticket-conversation.php'; ?></div>
      <?php require ROOT.'/app/Views/partials/ticket-development.php'; ?>
    </div>
    <aside class="ticket-aside" aria-label="Informations du ticket">
      <?php require ROOT.'/app/Views/partials/ticket-details.php'; ?>
      <?php require ROOT.'/app/Views/partials/ticket-related.php'; ?>
    </aside>
  </div>
</div>
<?php if($canEdit): require ROOT.'/app/Views/partials/ticket-edit.php'; require ROOT.'/app/Views/partials/ticket-comment.php'; endif; ?>
