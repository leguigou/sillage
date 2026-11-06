<?php
declare(strict_types=1);
namespace App\Mcp;
/**
 * Exception carrying an OAuth 2.0 error code (RFC 6749, e.g. "invalid_request", "invalid_client")
 * and the HTTP status to answer with. Thrown by App\Models\OAuth so OAuthController can turn it
 * into a standard OAuth error response. The message is the bare code, never user data.
 */
final class OAuthError extends \RuntimeException
{
    public function __construct(public readonly string $error,public readonly int $status=400)
    { parent::__construct($error); }
}
