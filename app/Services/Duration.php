<?php
declare(strict_types=1);
namespace App\Services;
/** Human-readable durations; persistence and numeric APIs stay in decimal hours. */
final class Duration
{
    public static function label(int|float|string|null $value): string
    {
        if($value===null) return '—';
        $minutes=(int)round((float)$value*60);
        if($minutes===0) return (float)$value>0?'< 1 min':'0 min';
        $hours=intdiv($minutes,60);$rest=$minutes%60;
        return ($hours?number_format($hours,0,',',' ').' h':'').($hours && $rest?' ':'').($rest?$rest.' min':'');
    }
}
