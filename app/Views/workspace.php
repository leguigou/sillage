<?php
/**
 * Common frame of every project workspace page (/, /list, /all, /board, /timeline, /forms, /development, /charts,
 * /settings, /automation, /releases), inside layout.php. Rendered by WorkspaceController::show(), which loads all data
 * up front (tickets, stats, kpis, activity, settings data, etc. — see the compact() list there).
 * It prints the page heading, any filter error, the filter bar (partials/filters.php) for ticket views, then requires
 * sections/<name>.php chosen through $pageLinks ('all' reuses list.php).
 * Section templates and some partials rely on two things defined here: $baseQuery (current filters minus page/view, used to build links)
 * and the bars() helper function (used by sections/dashboard.php and sections/charts.php).
 */
use App\Models\Ticket;
$pageLinks=['releases'=>'releases','automation'=>'automation','dashboard'=>'dashboard','list'=>'list','all'=>'list','board'=>'board','timeline'=>'timeline','forms'=>'forms','development'=>'development','charts'=>'charts','settings'=>'settings'];
$baseQuery=array_diff_key($filters,['page'=>1,'view'=>1]);if($activeView) $baseQuery['view']=$activeView['id'];
/** Prints one labelled <progress> bar per aggregate row ('label', 'n', optional 'color'), scaled to the largest count. */
function bars(array $rows,array $labels,string $kind='status'): void { $max=max([1,...array_map(fn($r)=>(int)$r['n'],$rows)]); foreach($rows as $row): ?><div class="bar-row"><div><span class="legend-dot <?= e($kind.'-'.$row['label']) ?>" <?= isset($row['color'])?'data-status-color="'.e($row['color']).'"':'' ?>></span><span><?= e($labels[$row['label']]??$row['label']) ?></span><strong><?= (int)$row['n'] ?></strong></div><progress value="<?= (int)$row['n'] ?>" max="<?= $max ?>" aria-label="<?= e($labels[$row['label']]??$row['label']) ?>"></progress></div><?php endforeach; if(!$rows): ?><p class="muted">Aucun ticket pour ces critères.</p><?php endif; }
?>
<section class="page-heading"><div><div class="eyebrow"><?= $section==='dashboard'?'UN PEU DE RECUL, BEAUCOUP DE CLARTÉ':'ESPACE '.e($project['project_key']) ?></div><h1><?= $section==='dashboard'?'Bonjour, '.e(explode(' ',$user['name'])[0]).'<span class="wave">✦</span>':e($title) ?></h1><p><?= match($section){'dashboard'=>'Voici ce qui fait avancer votre équipe aujourd’hui.','board'=>'Un regard sur le travail. Une direction commune.','list','all'=>'Les détails au bon endroit. Le travail sous contrôle.','timeline'=>'Du premier jalon à la dernière ligne droite.','forms'=>'Transformez les demandes en prochaines étapes.','development'=>'Le lien entre vos idées et le code qui leur donne vie.','charts'=>'Prenez de la hauteur sur vos projets.',default=>'Un espace de travail à votre image.'} ?></p></div><div class="heading-actions"><?php if($user['role']==='admin'&&!empty($project['id'])): ?><button class="icon-button" data-edit-project="<?= (int)$project['id'] ?>" title="Paramètres du projet" aria-label="Paramètres du projet"><?= icon('settings') ?></button><?php endif; ?><?php if($section==='dashboard'): ?><span class="date-label"><?= icon('clock') ?><?= (new DateTimeImmutable())->format('d.m.Y') ?></span><?php endif; ?><?php if($user['role']!=='viewer'&&!empty($project['id'])): ?><button class="button primary" data-open="ticket-dialog"><?= icon('plus') ?>Créer un ticket <kbd>C</kbd></button><?php endif; ?></div></section>
<?php if($error): ?><div class="notice error"><?= e($error) ?> Les filtres invalides n’ont pas été appliqués.</div><?php endif; ?>
<?php if(in_array($section,['dashboard','list','all','board','timeline','charts'],true)) require ROOT.'/app/Views/partials/filters.php'; ?>
<?php require ROOT.'/app/Views/sections/'.$pageLinks[$section].'.php'; ?>
