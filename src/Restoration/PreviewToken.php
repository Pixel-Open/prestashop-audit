<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Restoration;

/** Short-lived, signed preview; contains hashes and field identifiers, no historical values. */
final class PreviewToken
{
    public function __construct(private string $secret)
    {
    }

    public function issue(array $claims): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', 'pixel_audit_restore|' . $body, $this->secret);
    }

    public function verify(string $token, int $employee, string $session, int $now): array
    {
        if (strlen($token) > 8192 || count($parts = explode('.', $token)) !== 2
            || !hash_equals(hash_hmac('sha256', 'pixel_audit_restore|' . $parts[0], $this->secret), $parts[1])) {
            throw new RestoreException('Invalid preview.', 400);
        }
        $claims = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
        if (!is_array($claims) || ($claims['employee'] ?? null) !== $employee || $session === ''
            || !hash_equals(hash('sha256', $session), (string) ($claims['session'] ?? ''))
            || !is_int($claims['expires'] ?? null) || $claims['expires'] <= $now || $claims['expires'] > $now + 300
            || !preg_match('/^[a-f0-9]{32}$/D', (string) ($claims['nonce'] ?? ''))
            || !is_int($claims['event'] ?? null) || !is_int($claims['product'] ?? null) || !is_array($claims['fields'] ?? null)) {
            throw new RestoreException('The preview has expired or belongs to another session.', 409);
        }
        return $claims;
    }
}
