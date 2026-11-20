<?php
$ticketQuery = array_diff_key($baseQuery, App\Services\ReportPeriod::KEYS) + ['project'=>(int)$project['id']];
$formatDate = static fn(string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
$total = $projectReport['total'];
$metrics = [
    'created'=>'Créés', 'closed'=>'Clôturés', 'treated'=>'Traités', 'modified'=>'Modifiés', 'reopened'=>'Rouverts',
];
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
    <dl class="report-metrics report-metrics-primary">
        <div class="report-metric-created">
            <dt>Tickets créés</dt><dd><?= (int)$total['created'] ?></dd>
        </div>
        <div class="report-metric-closed">
            <dt>Tickets clôturés</dt><dd><?= (int)$total['closed'] ?></dd>
        </div>
        <div class="report-metric-progress">
            <dt>Avancement moyen en fin de période</dt>
            <dd>
                <svg class="report-gauge" viewBox="0 0 120 64" aria-hidden="true">
                    <path d="M 6 60 A 54 54 0 0 1 114 60" pathLength="100" class="report-gauge-track"/>
                    <?php if ((float)$total['progress'] > 0): ?>
                        <path d="M 6 60 A 54 54 0 0 1 114 60" pathLength="100" class="report-gauge-value"
                            stroke-dasharray="<?= (float)$total['progress'] ?> 100"/>
                    <?php endif; ?>
                </svg>
                <span><?= e($total['progress'] === null ? 'Indisponible' : $progressLabel($total['progress'])) ?></span>
            </dd>
        </div>
    </dl>
    <dl class="report-metrics report-metrics-secondary">
        <?php foreach (['treated','modified','reopened'] as $key): ?>
            <div><dt>Tickets <?= e(mb_strtolower($metrics[$key])) ?></dt><dd><?= (int)$total[$key] ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Temps passé</dt><dd><?= e(App\Core\View::hours($total['hours'])) ?></dd></div>
    </dl>
    <p class="help-text">Un ticket traité a changé d’état ou d’avancement, ou a été clôturé.
        Chaque ticket compte une fois par indicateur. Les filtres utilisent les données actuelles des tickets actifs.</p>
    <details class="report-breakdown">
        <summary id="report-breakdown-label">Voir le détail par intervalle</summary>
        <div class="table-scroll" role="region" aria-labelledby="report-breakdown-label" tabindex="0">
            <table aria-labelledby="report-breakdown-label">
                <thead>
                    <tr>
                        <th scope="col">Dates</th>
                        <?php foreach ($metrics as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?>
                        <th scope="col">Avancement moyen</th><th scope="col">Temps passé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projectReport['series'] as $row): ?>
                        <tr>
                            <th scope="row"><?= e($formatDate($row['starts'])) ?><?= $row['starts'] === $row['ends'] ? '' : ' – '.e($formatDate($row['ends'])) ?></th>
                            <?php foreach ($metrics as $key=>$label): ?><td><?= (int)$row[$key] ?></td><?php endforeach; ?>
                            <td><?= e($progressLabel($row['progress'])) ?></td>
                            <td><?= e(App\Core\View::hours($row['hours'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="help-text">L’avancement est la moyenne des pourcentages renseignés sur les tickets existant à la fin de chaque intervalle.
            Un même ticket peut apparaître dans plusieurs intervalles.</p>
    </details>
</section>
