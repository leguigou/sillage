<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Human-readable durations; persistence and numeric APIs stay in decimal hours.
 * Used by the view layer and ticket time/history models to display hours as e.g. "1 h 30 min".
 */
final class Duration
{
    /** Formats decimal hours, rounded up to 5 minutes; null gives "—" and any positive value at least "5 min". */
    public static function label(int|float|string|null $value): string
    {
        if($value===null) return '—';
        // Stabilize floating point noise before rounding up to a five-minute display step.
        $minutes=(int)ceil(round((float)$value*12,9))*5;
        if($minutes===0) {if((float)$value<=0) return '0 min';$minutes=5;}
        $hours=intdiv($minutes,60);$rest=$minutes%60;
        return ($hours?number_format($hours,0,',',' ').' h':'').($hours && $rest?' ':'').($rest?$rest.' min':'');
    }
}
