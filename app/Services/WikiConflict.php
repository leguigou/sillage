<?php
declare(strict_types=1);
namespace App\Services;

/** The page was saved by someone else since the editor loaded it. */
final class WikiConflict extends \RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('Cette page a été modifiée entre-temps (version ' . $currentRevision . ').');
    }
}
