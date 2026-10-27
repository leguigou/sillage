<?php
use App\Services\TimelineCalendar;
$calendar = TimelineCalendar::build('weeks', '2020-12-30', '2021-01-01');
check(
    $calendar['origin'] === '2020-12-28' && $calendar['days'] === 42,
    'chronologie alignée au lundi sur six semaines',
);
check(
    $calendar['weeks'][0]['key'] === '2020-W53' && $calendar['weeks'][1]['key'] === '2021-W01',
    'numérotation ISO correcte au changement d’année',
);
$leap = TimelineCalendar::build('months', '2024-01-29', '2024-02-01');
$february = array_values(
    array_filter($leap['bands'], fn($band) => $band['label'] === 'février 2024'),
)[0];
check(
    abs(($february['width'] * $leap['days']) / 100 - 29) < 0.00001,
    'bandes mensuelles respectant février bissextile',
);
$single = TimelineCalendar::bar($calendar, '2021-01-01', '2021-01-01');
check(
    abs($single['width'] - 100 / 42) < 0.00001 && abs($single['left'] - (4 / 42) * 100) < 0.00001,
    'ticket d’un jour aligné exactement sur sa colonne',
);
$clipped = TimelineCalendar::bar($calendar, '2020-12-20', '2020-12-29');
check(
    abs($clipped['left']) < 0.00001 &&
        $clipped['clippedStart'] &&
        abs($clipped['width'] - (2 / 42) * 100) < 0.00001,
    'barre tronquée sans décalage des jours visibles',
);
check(
    TimelineCalendar::bar($calendar, '2020-11-01', '2020-11-04') === null,
    'ticket hors période sans barre trompeuse',
);
check(
    $calendar['todayLeft'] !== null &&
        count(array_filter($calendar['dates'], fn($d) => $d['today'])) === 1,
    'un seul jour courant et son repère dans la chronologie',
);
