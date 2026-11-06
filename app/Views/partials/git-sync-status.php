<?php
/**
 * Sync status card of one repository connection: state pill, last success, catch-up
 * interval, pending jobs and recent job details, all from App\Models\GitJob::status().
 * Included in a loop by sections/development.php and ticket-development.php.
 * Expects $c (connection row: id, provider, repository), $user and $project.
 * "Rattraper maintenant" is hidden for viewers; links to the technical logs are admin-only.
 */
$sync=App\Models\GitJob::status((int)$c['id']);$counts=$sync['counts']; ?>
<div class="git-sync-status" data-git-sync-status="<?= (int)$c['id'] ?>"><div class="git-sync-heading"><strong><?= e($c['provider']==='github'?'GitHub':'Bitbucket') ?> · <?= e($c['repository']) ?></strong><span class="pill <?= $sync['problems']>0?'warning':(!empty($sync['worker']['active'])?'success':'neutral') ?>"><?= !$sync['settings']['enabled']?'Désactivée':(empty($sync['worker']['active'])?'Service à démarrer':($sync['problems']>0?'Vérification nécessaire':'Suivi automatique')) ?></span></div><p class="help-text">Dernier traitement réussi : <?= e($sync['settings']['last_success_at']??'pas encore') ?> · Rattrapage toutes les <?= (int)$sync['settings']['interval_minutes'] ?> min · <?= (int)($counts['pending']??0) ?> en attente<?= empty($counts['running'])?'':' · traitement en cours' ?></p>
<?php if($user['role']!=='viewer'): ?><button type="button" class="button subtle small-button" data-git-recover="<?= (int)$c['id'] ?>" data-project="<?= (int)$project['id'] ?>">Rattraper maintenant</button><?php endif; ?>
<?php if($sync['jobs']): ?><details id="git-sync-jobs-<?= (int)$c['id'] ?>"><summary>État des récupérations</summary><?php foreach($sync['jobs'] as $job): ?><p class="help-text"><strong><?= e(App\Models\GitJob::LABELS[$job['kind']]??'Traitement') ?></strong> · <?= e(App\Models\GitJob::STATES[$job['state']]??$job['state']) ?><?= $job['last_error']?' · '.e($job['last_error']):'' ?><?php if($job['attempts']): ?> · tentative <?= (int)$job['attempts'] ?>/5<?php endif; ?><?php if($user['role']==='admin' && $job['request_id']): ?> · <a href="/settings?log_request=<?= e($job['request_id']) ?>#technical-logs">Voir les appels</a><?php endif; ?></p><?php endforeach; ?></details><?php endif; ?><p class="help-text" role="status" aria-live="polite" data-git-recover-message></p></div>
