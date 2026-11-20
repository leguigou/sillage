<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Security;
use DateTimeImmutable;
use InvalidArgumentException;

final class ReportPeriod
{
    public const KEYS = ['report_mode'=>1, 'report_anchor'=>1, 'report_start'=>1, 'report_end'=>1];

    public readonly string $mode;
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;
    public readonly ?string $previous;
    public readonly ?string $next;

    public function __construct(array $input, ?DateTimeImmutable $today = null)
    {
        $today ??= new DateTimeImmutable('today UTC');
        $mode = $input['report_mode'] ?? 'month';
        if (!in_array($mode, ['week', 'month', 'custom'], true)) {
            throw new InvalidArgumentException('Période de rapport invalide.');
        }
        $this->mode = $mode;
        if ($mode === 'custom') {
            $this->start = self::date($input['report_start'] ?? null);
            $this->end = self::date($input['report_end'] ?? null);
            if ($this->start > $this->end || $this->end > $today || $this->start->diff($this->end)->days >= 366) {
                throw new InvalidArgumentException('Choisissez deux dates dans l’ordre, jusqu’à aujourd’hui, sur 366 jours maximum.');
            }
            $this->previous = $this->next = null;
            return;
        }
        $anchor = isset($input['report_anchor']) ? self::date($input['report_anchor']) : $today;
        if ($anchor > $today) {
            throw new InvalidArgumentException('Choisissez une période jusqu’à aujourd’hui.');
        }
        $week = $mode === 'week';
        $start = $anchor->modify($week ? 'monday this week' : 'first day of this month');
        $this->start = max($start, new DateTimeImmutable('1000-01-01 UTC'));
        $next = $start->modify($week ? '+1 week' : '+1 month');
        $this->end = min($today, $next->modify('-1 day'));
        $previous = $this->start->modify($week ? '-1 week' : '-1 month');
        $this->previous = (int)$previous->format('Y') >= 1000 ? $previous->format('Y-m-d') : null;
        $this->next = $next <= $today ? $next->format('Y-m-d') : null;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !Security::date($value) || (int)$value < 1000) {
            throw new InvalidArgumentException('Indiquez une date valide pour le rapport.');
        }
        return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function query(): array
    {
        return ['report_mode'=>$this->mode] + ($this->mode === 'custom'
            ? ['report_start'=>$this->start->format('Y-m-d'), 'report_end'=>$this->end->format('Y-m-d')]
            : ['report_anchor'=>$this->start->format('Y-m-d')]);
    }

    /** Index zero is the total. Other rows are consecutive, non-overlapping intervals with exclusive ends. */
    public function intervals(): array
    {
        $end = $this->end->modify('+1 day');
        $days = $this->start->diff($end)->days;
        $step = match (true) {
            $this->mode === 'week' || ($this->mode === 'custom' && $days <= 14) => '+1 day',
            $days <= 120 => 'next monday',
            default => 'first day of next month',
        };
        $intervals = [[$this->start->format('Y-m-d'), $end->format('Y-m-d')]];
        for ($start = $this->start; $start < $end; $start = $stop) {
            $stop = min($end, $start->modify($step));
            $intervals[] = [$start->format('Y-m-d'), $stop->format('Y-m-d')];
        }
        return $intervals;
    }
}
