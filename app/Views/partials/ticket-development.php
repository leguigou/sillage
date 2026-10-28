<?php use App\Services\DevelopmentPayload; $branchSuggestion='feature/'.$ticket['key'].'-'.trim(substr(preg_replace('/[^a-z0-9]+/','-',strtolower((string)iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$ticket['title']))),0,60),'-'); ?>
<section class="panel ticket-development" id="development" data-ticket-panel aria-labelledby="ticket-tab-development">
 <div class="panel-header"><div><h2>Développement</h2><p>Branches, commits et pull requests liés à <?= e($ticket['key']) ?>.</p></div><?= icon('code') ?></div>
 <?php if($primaryRepository): ?><p class="help-text">Dépôt principal : <strong><?= e($primaryRepository['repository']) ?></strong></p><?php endif; ?>
 <div class="development-counts"><?php foreach(DevelopmentPayload::KINDS as $kind=>$label): ?><button type="button" data-development-open="ticket-development-<?= e($kind) ?>"><strong><?= (int)($developmentCounts[$kind]??0) ?></strong><span><?= e($label) ?></span></button><?php endforeach; ?></div>
 <p class="help-text">Ajoutez la clé <strong><?= e($ticket['key']) ?></strong> au nom d’une branche, au message d’un commit ou au titre d’une pull request.</p>
 <button type="button" class="button subtle small-button" data-copy="<?= e($branchSuggestion) ?>"><?= icon('code') ?>Copier un nom de branche</button>
 <?php foreach(DevelopmentPayload::KINDS as $kind=>$label): $kindEvents=array_filter($events,fn($e)=>$e['kind']===$kind); ?>
 <details class="ticket-development-kind" id="ticket-development-<?= e($kind) ?>"><summary><?= e($label) ?> <span class="pill neutral"><?= (int)($developmentCounts[$kind]??0) ?></span></summary>
  <?php foreach($kindEvents as $event): ?><article class="ticket-development-event"><div><?php if($event['external_url']): ?><a href="<?= e($event['external_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($event['title']) ?></a><?php else: ?><strong><?= e($event['title']) ?></strong><?php endif; ?><small><?= e($event['repository']) ?><?php if($event['metadata']['branch']??null): ?> · <?= e($event['metadata']['branch']) ?><?php endif; ?><?php if($event['commit_sha']): ?> · <?= e(substr($event['commit_sha'],0,12)) ?><?php endif; ?></small><small><?= ($event['metadata']['date_known']??true)?e($event['occurred_at']):'Date non fournie par le fournisseur' ?></small></div><span class="pill <?= $event['state']==='merged'?'success':'neutral' ?>"><?= e($event['state_label']) ?></span></article><?php endforeach; ?>
  <?php if(!$kindEvents): ?><p class="muted">Aucun élément reçu pour le moment.</p><?php endif; ?>
 </details><?php endforeach; ?>
 <?php if(count($events)===100): ?><small>Les 100 éléments liés les plus récents sont affichés.</small><?php endif; ?>
</section>
