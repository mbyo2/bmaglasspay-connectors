<?php

namespace BMaGlass\Pay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Reads the module's config values the way they are USED at runtime.
 *
 * The api_key field declares Magento's Encrypted backend model in
 * system.xml, so a value saved through the admin (or `config:set`) is
 * stored as versioned ciphertext ("<keyVersion>:<cipherVersion>:...").
 * scopeConfig->getValue() returns that ciphertext verbatim at runtime —
 * it is only decrypted for display in the admin form. Every runtime
 * consumer therefore goes through here and gets the real key/secret.
 * Plaintext values (e.g. written straight into core_config_data) pass
 * through unchanged.
 */
class Config
{
    private $scopeConfig;
    private $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function apiKey()
    {
        return $this->decrypt((string) $this->scopeConfig->getValue('payment/bmaglasspay/api_key'));
    }

    public function webhookSecret()
    {
        return $this->decrypt((string) $this->scopeConfig->getValue('payment/bmaglasspay/webhook_secret'));
    }

    private function decrypt($raw)
    {
        // Encrypted backend-model payloads are versioned
        // ("0:3:<base64>"); anything else is plaintext already.
        if (preg_match('/^\d+:\d+:/', $raw)) {
            $plain = $this->encryptor->decrypt($raw);
            return is_string($plain) ? $plain : '';
        }
        return $raw;
    }
}
