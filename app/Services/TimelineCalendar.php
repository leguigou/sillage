<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Security;

final class TimelineCalendar
{
    private const MONTHS = [
        1 => 'janvier',
        'février',
        'mars',
        'avril',
        'mai',
        'juin',
        'juillet',
        'août',
        'septembre',
        'octobre',
        'novembre',
        'décembre',
    ];
    public static function build(string $scale, ?string $from = null, ?string $today = null): array
    {
        $scale = in_array($scale, ['weeks', 'months', 'quarters'], true) ? $scale : 'weeks';
        $today = new \DateTimeImmutable(
            Security::date($today ?? gmdate('Y-m-d')),
            new \DateTimeZone('UTC'),
        );
        $anchor = $from
            ? new \DateTimeImmutable(Security::date($from), new \DateTimeZone('UTC'))
            : $today->modify('-14 days');
        $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
        $days = match ($scale) {
            'months' => 112,
            'quarters' => 364,
            default => 42,
        };
        $end = $start->modify('+' . $days . ' days');
        $weeks = [];
        $dates = [];
        $bands = [];
        for ($offset = 0; $offset < $days; $offset += 7) {
            $date = $start->modify('+' . $offset . ' days');
            $last = $date->modify('+6 days');
            $weeks[] = [
                'key' => $date->format('o-\WW'),
                'number' => $date->format('W'),
                'year' => $date->format('o'),
                'range' => $date->format('d/m') . '–' . $last->format('d/m'),
                'title' =>
                    'Semaine ' .
                    $date->format('W') .
                    ' de ' .
                    $date->format('o') .
                    ' · ' .
                    $date->format('d/m/Y') .
                    ' au ' .
                    $last->format('d/m/Y'),
                'left' => ($offset / $days) * 100,
                'width' => (7 / $days) * 100,
            ];
        }
        $weekdays = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->modify('+' . $offset . ' days');
            $dates[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('d'),
                'weekday' => $weekdays[(int) $date->format('N') - 1],
                'weekend' => (int) $date->format('N') >= 6,
                'today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
                'left' => ($offset / $days) * 100,
                'width' => 100 / $days,
            ];
        }
        $cursor = $start;
        while ($cursor < $end) {
            $month = (int) $cursor->format('n');
            $year = (int) $cursor->format('Y');
            $quarter = intdiv($month - 1, 3) + 1;
            $next =
                $scale === 'quarters'
                    ? $cursor->setDate($year, ($quarter - 1) * 3 + 1, 1)->modify('+3 months')
                    : $cursor->modify('first day of next month');
            if ($next > $end) {
                $next = $end;
            }
            $offset = (int) $start->diff($cursor)->format('%r%a');
            $length = (int) $cursor->diff($next)->format('%a');
            $bands[] = [
                'label' =>
                    ($scale === 'quarters' ? 'T' . $quarter : self::MONTHS[$month]) . ' ' . $year,
                'left' => ($offset / $days) * 100,
                'width' => ($length / $days) * 100,
            ];
            $cursor = $next;
        }
        $todayOffset = (int) $start->diff($today)->format('%r%a');
        return compact('scale', 'start', 'end', 'days', 'weeks', 'dates', 'bands') + [
            'origin' => $start->format('Y-m-d'),
            'last' => $end->modify('-1 day')->format('d/m/Y'),
            'todayLeft' =>
                $todayOffset >= 0 && $todayOffset < $days
                    ? (($todayOffset + 0.5) / $days) * 100
                    : null,
            'step' => match ($scale) {
                'months' => 28,
                'quarters' => 91,
                default => 7,
            },
            'todayOrigin' => $today->modify('-14 days')->format('Y-m-d'),
        ];
    }
    public static function bar(array $calendar, string $start, ?string $due): ?array
    {
        $start = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        $due = new \DateTimeImmutable($due ?: $start->format('Y-m-d'), new \DateTimeZone('UTC'));
        $left = (int) $calendar['start']->diff($start)->format('%r%a');
        $end = (int) $calendar['start']->diff($due)->format('%r%a') + 1;
        $first = max(0, $left);
        $last = min($calendar['days'], $end);
        if ($last <= $first) {
            return null;
        }
        return [
            'left' => ($first / $calendar['days']) * 100,
            'width' => (($last - $first) / $calendar['days']) * 100,
            'clippedStart' => $left < 0,
            'clippedEnd' => $end > $calendar['days'],
            'range' => $start->format('d/m/Y') . ' → ' . $due->format('d/m/Y'),
        ];
    }
}
