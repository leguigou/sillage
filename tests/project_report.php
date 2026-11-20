<?php
declare(strict_types=1);

use App\Core\DB;
use App\Models\{Ticket, TicketHistory};
use App\Services\{ProjectReport, ReportPeriod};

(static function (int $actor): void {
    $today = new DateTimeImmutable('2026-03-06 UTC');
    $week = new ReportPeriod(['report_mode'=>'week'], $today);
    check($week->intervals()[0] === ['2026-03-02','2026-03-07'] && $week->next === null,
        'rapport de la semaine calendaire jusqu’à aujourd’hui');
    $month = new ReportPeriod(['report_mode'=>'month','report_anchor'=>'2024-02-18'], $today);
    check($month->intervals()[0] === ['2024-02-01','2024-03-01'] && $month->previous === '2024-01-01',
        'rapport mensuel pendant une année bissextile');
    rejects(fn() => new ReportPeriod(['report_mode'=>'custom','report_start'=>['2026-03-01'],'report_end'=>'2026-03-05'], $today),
        'refus d’une date de rapport mal formée');

    $project = DB::insert("INSERT INTO projects(name,project_key) VALUES('Rapport activité','REPORTQA')");
    $migration = '019_time_history_releases.sql';
    $appliedAt = DB::one('SELECT applied_at FROM migrations WHERE version=?', [$migration])['applied_at'];
    DB::run("UPDATE migrations SET applied_at='2026-03-02 00:00:00' WHERE version=?", [$migration]);
    $ticket = Ticket::create($project, ['title'=>'Demande suivie'], $actor);
    DB::run("UPDATE tickets SET created_at='2026-03-01 10:00:00',progress_percent=90,completed_at=NULL WHERE id=?", [$ticket]);
    DB::run('DELETE FROM ticket_history WHERE ticket_id=?', [$ticket]);
    foreach ([
        ['2026-03-02 09:00:00', [['field'=>'progress_percent','before'=>0,'after'=>25]]],
        ['2026-03-02 10:00:00', [['field'=>'title','before'=>'Demande','after'=>'Demande suivie']]],
        ['2026-03-03 00:00:00', [['field'=>'progress_percent','before'=>25,'after'=>50], ['field'=>'resolution','before'=>null,'after'=>'fixed']]],
        ['2026-03-04 12:00:00', [['field'=>'resolution','before'=>'fixed','after'=>null]]],
        ['2026-03-05 12:00:00', [['field'=>'resolution','before'=>null,'after'=>'fixed']]],
        ['2026-03-06 11:00:00', [['field'=>'progress_percent','before'=>50,'after'=>90], ['field'=>'resolution','before'=>'fixed','after'=>null]]],
    ] as $i=>[$date,$changes]) {
        TicketHistory::event($ticket, $actor, $i+2, 'updated', $changes);
        DB::run('UPDATE ticket_history SET created_at=? WHERE ticket_id=? AND ticket_version=?', [$date,$ticket,$i+2]);
    }
    $legacy = Ticket::create($project, ['title'=>'Ancienne demande'], $actor);
    DB::run("DELETE FROM ticket_history WHERE ticket_id=?", [$legacy]);
    DB::run("UPDATE tickets SET created_at='2026-03-02 08:00:00',completed_at='2026-03-03 10:00:00' WHERE id=?", [$legacy]);
    DB::run("INSERT INTO ticket_worklogs(ticket_id,user_id,worked_on,hours,note,deleted) VALUES
        (?,?,'2026-03-03',2.25,'Temps saisi',0),(?,?,'2026-03-03',4,'Temps supprimé',1),
        (?,?,'2026-03-05',1,'Hors plage',0)", [$ticket,$actor,$ticket,$actor,$ticket,$actor]);

    $period = new ReportPeriod(['report_mode'=>'custom','report_start'=>'2026-03-02','report_end'=>'2026-03-04'], $today);
    $report = ProjectReport::activity($project, ['q'=>'suivie'], $period);
    $total = $report['total'];
    check((int)$total['created'] === 0 && (int)$total['modified'] === 1 && (int)$total['treated'] === 1
        && (int)$total['closed'] === 1 && (int)$total['reopened'] === 1 && (float)$total['hours'] === 2.25,
        'rapport filtré, tickets dédupliqués, clôture conservée après réouverture et temps de la période');
    check(array_map('floatval', array_column($report['series'], 'progress')) === [25.0,50.0,50.0]
        && (int)$report['series'][0]['closed'] === 0 && (int)$report['series'][1]['closed'] === 1
        && (int)$report['series'][2]['treated'] === 0 && (float)$total['progress'] === 50.0,
        'avancement historique et clôture à minuit dans le bon intervalle');
    $report = ProjectReport::activity($project, [], $week);
    check((int)$report['total']['closed'] === 2 && (int)$report['total']['created'] === 1
        && (int)$report['total']['reopened'] === 1 && (int)$report['series'][3]['closed'] === 1,
        'la clôture historique compte une fois sur la période et la dernière clôture héritée reste visible');
    $legacyPeriod = new ReportPeriod(['report_mode'=>'custom','report_start'=>'2026-03-01','report_end'=>'2026-03-01'], $today);
    $report = ProjectReport::activity($project, [], $legacyPeriod);
    check($report['partialHistory'] && $report['total']['progress'] === null && (int)$report['total']['created'] === 1,
        'couverture partielle signalée avant la journalisation des champs');
    DB::run('UPDATE migrations SET applied_at=? WHERE version=?', [$appliedAt,$migration]);
})($uid);
