<?php

namespace App\Infrastructure\Providers\Support;

use App\Models\ProviderAccount;
use InvalidArgumentException;

/**
 * Certificados mTLS das contas (Efí Pix, Sicoob).
 *
 * São recebidos em base64 (.p12/.pfx ou .pem), convertidos para um único PEM
 * (certificado + chave privada) e guardados criptografados em `credentials.certificate`.
 * Em tempo de execução são materializados num arquivo 0600 fora do diretório público.
 */
final class Certificates
{
    public static function normalize(string $input, ?string $password = null): string
    {
        $input = trim($input);

        if (! str_contains($input, '-----BEGIN')) {
            $decoded = base64_decode($input, true);

            if ($decoded === false) {
                throw new InvalidArgumentException('Certificado deve ser enviado em base64 (.p12/.pfx) ou PEM.');
            }

            $input = $decoded;
        }

        if (str_contains($input, '-----BEGIN')) {
            if (! str_contains($input, 'PRIVATE KEY-----')) {
                throw new InvalidArgumentException('O PEM precisa conter o certificado e a chave privada.');
            }

            return $input;
        }

        $parts = [];
        if (! openssl_pkcs12_read($input, $parts, (string) $password)) {
            throw new InvalidArgumentException('Não foi possível abrir o certificado .p12/.pfx (senha incorreta ou arquivo inválido).');
        }

        return $parts['cert'].$parts['pkey'].implode('', $parts['extracerts'] ?? []);
    }

    /** Caminho do PEM da conta, ou null quando a conta não tem certificado. */
    public static function path(ProviderAccount $account): ?string
    {
        $pem = $account->credential('certificate');

        if (! $pem) {
            return null;
        }

        $dir = storage_path('app/private/certs');
        $path = $dir.'/'.$account->id.'-'.substr(hash('sha256', $pem), 0, 16).'.pem';

        if (! is_file($path)) {
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }

            $tmp = $path.'.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, $pem);
            chmod($tmp, 0600);
            rename($tmp, $path);
        }

        return $path;
    }
}
