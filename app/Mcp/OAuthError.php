<?php
declare(strict_types=1);
namespace App\Mcp;
final class OAuthError extends \RuntimeException
{
    public function __construct(public readonly string $error,public readonly int $status=400)
    { parent::__construct($error); }
}
