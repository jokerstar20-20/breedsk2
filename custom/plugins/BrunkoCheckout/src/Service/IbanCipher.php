<?php declare(strict_types=1);

namespace Brunko\Checkout\Service;

/**
 * Sifrovanie IBAN v databaze (libsodium secretbox). Kluc sa odvodzuje z APP_SECRET (je len v .env servera, nie v DB).
 * Pozor: zmena APP_SECRET znamena, ze uz ulozene IBAN sa nedaju desifrovat.
 */
class IbanCipher
{
    private const PREFIX = 'enc1:';

    public function __construct(private readonly string $appSecret)
    {
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '' || $this->isEncrypted($plain)) {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '(nedá sa dešifrovať)';
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );

        return $plain === false ? '(nedá sa dešifrovať - zmenený kľúč?)' : $plain;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private function key(): string
    {
        return sodium_crypto_generichash('brunko-iban|' . $this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
