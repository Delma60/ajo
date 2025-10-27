<?php

namespace App\Classes;

use RuntimeException;

class Encryptor
{
    /**
     * Encrypt plaintext using AES-256-GCM and return base64(ciphertext || tag).
     *
     * @param string $data  Plaintext to encrypt
     * @param string $b64Key  Dashboard key (base64 string from Flutterwave)
     * @param string $nonce  12-character ASCII nonce/IV
     * @return string  base64 encoded (ciphertext + tag)
     * @throws RuntimeException
     */
    public static function encryptAES(string $data, string $b64Key, string $nonce): string
    {
        // Validate nonce length
        if (strlen($nonce) !== 12) {
            throw new RuntimeException("Nonce must be exactly 12 characters long.");
        }

        // Decode base64 key
        $key = base64_decode($b64Key, true);
        if ($key === false) {
            throw new RuntimeException("Invalid base64 key provided.");
        }

        // Key must be 32 bytes (256 bits)
        if (strlen($key) !== 32) {
            throw new RuntimeException("Encryption key must decode to 32 bytes (256 bits). Got " . strlen($key));
        }

        $iv = $nonce; // treat nonce string as raw 12-byte IV
        $tag = '';
        $cipher = 'aes-256-gcm';
        // tag length 16 bytes (128 bits) is recommended and compatible
        $tagLength = 16;

        $ciphertextRaw = openssl_encrypt(
            $data,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            // optional aad; not used here
            '',
            $tagLength
        );

        if ($ciphertextRaw === false) {
            throw new RuntimeException("openssl_encrypt failed: " . openssl_error_string());
        }

        // Append tag to ciphertext to match WebCrypto/Node output: ciphertext || tag
        $combined = $ciphertextRaw . $tag;

        // Base64 encode combined bytes and return (safe ASCII string)
        return base64_encode($combined);
    }

    /**
     * Optional: decrypt base64(ciphertext||tag) for local roundtrip testing.
     *
     * @param string $b64Combined
     * @param string $b64Key
     * @param string $nonce
     * @return string
     */
    public static function decryptAES(string $b64Combined, string $b64Key, string $nonce): string
    {
        $key = base64_decode($b64Key, true);
        if ($key === false) {
            throw new RuntimeException("Invalid base64 key provided.");
        }
        if (strlen($key) !== 32) {
            throw new RuntimeException("Encryption key must decode to 32 bytes (256 bits).");
        }
        if (strlen($nonce) !== 12) {
            throw new RuntimeException("Nonce must be exactly 12 characters long.");
        }

        $combined = base64_decode($b64Combined, true);
        if ($combined === false) {
            throw new RuntimeException("Invalid base64 combined ciphertext.");
        }

        // Split tag (last 16 bytes) and ciphertext
        $tag = substr($combined, -16);
        $ciphertextRaw = substr($combined, 0, -16);

        $plaintext = openssl_decrypt(
            $ciphertextRaw,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException("openssl_decrypt failed (auth tag mismatch?). " . openssl_error_string());
        }

        return $plaintext;
    }

    
    public static function nonce($length=12){
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $nonce;
    }
}