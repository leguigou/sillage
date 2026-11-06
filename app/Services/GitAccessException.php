<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Thrown by Development when GitHub/Bitbucket answers with a non-2xx HTTP status.
 * Carries that status so callers (e.g. GitAccessTest) can show it; the message is a controlled
 * French text, never the provider's response body.
 */
final class GitAccessException extends \InvalidArgumentException
{
    public function __construct(public readonly int $httpStatus,string $message) {parent::__construct($message);}
}
