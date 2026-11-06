<?php
/**
 * GitHub/Bitbucket icons for each provider where the ticket has a linked branch or pull
 * request (see TicketBranch::providersForTickets); each links to the ticket's #development
 * tab. Included by list-ticket-cells.php ('git' column). Expects $t with 'branch_providers'
 * (list of provider names).
 */
if(!empty($t['branch_providers'])): ?><span class="ticket-git-links"><?php foreach($t['branch_providers'] as $branchProvider): $branchProviderName=$branchProvider==='github'?'GitHub':'Bitbucket'; ?>
<a class="ticket-git-link ticket-git-<?= e($branchProvider) ?>" href="<?= e(ticket_url((int)$t['id'],'#development')) ?>" title="<?= e('Branche ou pull request '.$branchProviderName.' liée — ouvrir le développement') ?>" aria-label="<?= e('Voir le développement '.$branchProviderName.' lié à '.$t['key']) ?>"><?= icon($branchProvider) ?></a>
<?php endforeach; ?></span><?php endif; ?>
