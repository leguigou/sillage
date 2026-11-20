<?php
$ticketQuery = array_diff_key($baseQuery, App\Services\ReportPeriod::KEYS) + ['project'=>(int)$project['id']];
$formatDate = static fn(string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
$total = $projectReport['total'];
$series = $projectReport['series'];
$metrics = [
    'created'=>'Créés', 'modified'=>'Modifiés', 'treated'=>'Traités', 'closed'=>'Clôturés', 'reopened'=>'Rouverts',
];
$chartMetrics = ['created'=>'Créés', 'modified'=>'Modifiés', 'closed'=>'Clôturés'];
$volumeMax = max(1, ...array_map(static fn(array $row): int => max((int)$row['created'], (int)$row['modified'], (int)$row['closed']), $series));
$progressLabel = static fn(?string $value): string => $value === null ? '—' : rtrim(rtrim(number_format((float)$value, 1, ',', ''), '0'), ',').' %';
?>
<section class="panel project-report" aria-labelledby="report-period-title">
    <header class="panel-header">
        <div>
            <h2 id="report-period-title">Activité sur la période</h2>
            <p>Du <?= e($formatDate($total['starts'])) ?> au <?= e($formatDate($total['ends'])) ?> · UTC</p>
        </div>
        <nav class="report-period-nav" aria-label="Période du rapport">
            <?php foreach (['week'=>'Semaine', 'month'=>'Mois'] as $mode=>$label): ?>
                <a class="button subtle <?= $reportPeriod->mode === $mode ? 'selected' : '' ?>"
                    <?= $reportPeriod->mode === $mode ? 'aria-current="true"' : '' ?>
                    href="<?= e(query_url('/charts', $ticketQuery, ['report_mode'=>$mode])) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <div class="report-period-controls">
        <div class="report-period-nav">
            <?php foreach (['previous'=>'Précédent', 'next'=>'Suivant'] as $direction=>$label): ?>
                <?php if ($reportPeriod->$direction !== null): ?>
                    <a class="button subtle" href="<?= e(query_url('/charts', $ticketQuery, [
                        'report_mode'=>$reportPeriod->mode, 'report_anchor'=>$reportPeriod->$direction,
                    ])) ?>"><?= e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <form action="/charts" method="get" class="report-date-form">
            <?php foreach (explode('&', http_build_query($ticketQuery)) as $parameter):
                [$name, $value] = explode('=', $parameter, 2); ?>
                <input type="hidden" name="<?= e(urldecode($name)) ?>" value="<?= e(urldecode($value)) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="report_mode" value="custom">
            <label>Du <input type="date" name="report_start" value="<?= e($total['starts']) ?>" required max="<?= gmdate('Y-m-d') ?>"></label>
            <label>Au <input type="date" name="report_end" value="<?= e($total['ends']) ?>" required max="<?= gmdate('Y-m-d') ?>"></label>
            <button class="button primary">Appliquer les dates</button>
        </form>
    </div>
    <?php if ($reportError): ?>
        <p class="notice error" role="alert"><?= e($reportError) ?> Le mois en cours est affiché.</p>
    <?php endif; ?>
    <?php if ($projectReport['partialHistory']): ?>
        <p class="notice">Historique détaillé disponible depuis le <?= e($formatDate($projectReport['historySince'])) ?>.
            Les modifications, traitements, clôtures et réouvertures antérieurs peuvent manquer.</p>
    <?php endif; ?>
    <dl class="report-metrics">
        <?php foreach ($metrics as $key=>$label): ?>
            <div><dt><?= e($label) ?></dt><dd><?= (int)$total[$key] ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Avancement moyen en fin de période</dt><dd><?= e($progressLabel($total['progress'])) ?></dd></div>
        <div><dt>Temps passé</dt><dd><?= e(App\Core\View::hours($total['hours'])) ?></dd></div>
    </dl>
    <p class="help-text">Un ticket traité a changé d’état ou d’avancement, ou a été clôturé.
        Chaque ticket compte une fois par indicateur. Les filtres utilisent les données actuelles des tickets actifs.</p>
</section>

<section class="panel project-report" aria-labelledby="report-trend-title">
    <div class="panel-header"><h2 id="report-trend-title">Évolution de l’activité</h2></div>
    <div aria-hidden="true">
        <div class="report-chart-legend">
            <?php foreach ($chartMetrics as $key=>$label): ?>
                <span><i class="report-color-<?= e($key) ?>"></i><?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="report-chart-scroll">
            <div class="report-chart">
                <?php foreach ($series as $row): ?>
                    <div class="report-chart-group">
                        <svg class="report-chart-bars" viewBox="0 0 44 108">
                            <?php $x=2; foreach ($chartMetrics as $key=>$label): $height=108*(int)$row[$key]/$volumeMax; ?>
                                <rect x="<?= $x ?>" y="<?= 108-$height ?>" width="11" height="<?= $height ?>" rx="2"
                                    class="report-color-<?= e($key) ?>"><title><?= e($label) ?> · <?= (int)$row[$key] ?></title></rect>
                            <?php $x+=14; ?>
                            <?php endforeach; ?>
                        </svg>
                        <small><?= e(substr($formatDate($row['starts']), 0, 5)) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="table-scroll report-breakdown">
        <table>
            <caption>Détail par intervalle</caption>
            <thead>
                <tr>
                    <th scope="col">Dates</th>
                    <?php foreach ($metrics as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?>
                    <th scope="col">Avancement moyen</th><th scope="col">Temps passé</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($series as $row): ?>
                    <tr>
                        <th scope="row"><?= e($formatDate($row['starts'])) ?><?= $row['starts'] === $row['ends'] ? '' : ' – '.e($formatDate($row['ends'])) ?></th>
                        <?php foreach ($metrics as $key=>$label): ?><td><?= (int)$row[$key] ?></td><?php endforeach; ?>
                        <td>
                            <?php if ($row['progress'] !== null): ?>
                                <progress value="<?= (float)$row['progress'] ?>" max="100" aria-hidden="true"></progress>
                            <?php endif; ?>
                            <?= e($progressLabel($row['progress'])) ?>
                        </td>
                        <td><?= e(App\Core\View::hours($row['hours'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="help-text">L’avancement est la moyenne des pourcentages renseignés sur les tickets existant à la fin de chaque intervalle.
        Un même ticket peut apparaître dans plusieurs intervalles.</p>
</section>
