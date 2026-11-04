<?php if(!empty($t['branch_providers'])): ?><span class="ticket-git-links"><?php foreach($t['branch_providers'] as $branchProvider): $branchProviderName=$branchProvider==='github'?'GitHub':'Bitbucket'; ?>
<a class="ticket-git-link ticket-git-<?= e($branchProvider) ?>" href="<?= e(ticket_url((int)$t['id'],'#development')) ?>" title="<?= e('Branche '.$branchProviderName.' liée — ouvrir le développement') ?>" aria-label="<?= e('Voir les branches '.$branchProviderName.' liées à '.$t['key']) ?>"><?= icon($branchProvider) ?></a>
<?php endforeach; ?></span><?php endif; ?>
