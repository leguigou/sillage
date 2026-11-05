<?php
declare(strict_types=1);
namespace App\Services;
final class GitAccessException extends \InvalidArgumentException
{
    public function __construct(public readonly int $httpStatus,string $message) {parent::__construct($message);}
}
