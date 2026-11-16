<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Correlation;

/** Client transports a capability issued by the server, never an arbitrary operation ID. */
final class SignedOperation
{
    public const TTL = 600;

    public function __construct(private string $secret)
    {
    }

    public function issue(int $employeeId, string $session, int $productId, array $shops, int $now): string
    {
        sort($shops, SORT_NUMERIC);
        if ($employeeId < 1 || $session === '' || $productId < 1 || !$shops) {
            throw new \InvalidArgumentException('Invalid operation scope.');
        }
        $payload = $this->encode(json_encode([
            'v' => 1, 'nonce' => bin2hex(random_bytes(16)), 'employee' => $employeeId,
            'session' => hash_hmac('sha256', $session, $this->secret),
            'product' => $productId, 'shops' => array_values(array_unique(array_map('intval', $shops))),
            'issued' => $now, 'expires' => $now + self::TTL,
        ], JSON_THROW_ON_ERROR));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    public function verify(string $token, int $employeeId, string $session, array $shops, int $now): ?array
    {
        if (strlen($token) > 2048 || $session === '' || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) {
            return null;
        }
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        try {
            $data = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return null;
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['employee'] ?? null) !== $employeeId
            || !is_string($data['session'] ?? null) || !hash_equals(hash_hmac('sha256', $session, $this->secret), $data['session'])
            || !is_int($data['product'] ?? null) || $data['product'] < 1
            || !is_int($data['issued'] ?? null) || !is_int($data['expires'] ?? null)
            || $data['issued'] > $now || $data['expires'] <= $now || $data['expires'] - $data['issued'] !== self::TTL
            || !is_string($data['nonce'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['nonce'])
            || !is_array($data['shops'] ?? null) || !$data['shops']) {
            return null;
        }
        foreach ($data['shops'] as $shop) {
            if (!is_int($shop) || $shop < 1 || !in_array($shop, array_map('intval', $shops), true)) {
                return null;
            }
        }
        return $data;
    }

    private function encode(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
