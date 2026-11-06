<?php
/**
 * Deployment badge of one ticket in the list's "development" column, linking to the
 * ticket's #development tab; a dash when no Git event is linked.
 * Included by list-ticket-cells.php. Expects $t (ticket row) and $deploymentBadge
 * (array with tone/title/icon/label/detail, or null), taken from the $deploymentBadges
 * map built in sections/list.php with App\Services\TicketDevelopment::badges().
 */
if($deploymentBadge): ?><a class="deployment-badge deployment-<?= e($deploymentBadge['tone']) ?>" href="<?= e(ticket_url((int)$t['id'],'#development')) ?>" title="<?= e($deploymentBadge['title']) ?>"><?= icon($deploymentBadge['icon']) ?><span><strong><?= e($deploymentBadge['label']) ?></strong><?php if($deploymentBadge['detail']!==''): ?><small><?= e($deploymentBadge['detail']) ?></small><?php endif; ?></span></a><?php else: ?><span class="muted" title="Aucun événement Git lié reçu">—</span><?php endif; ?>
