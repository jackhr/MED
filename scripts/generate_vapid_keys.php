<?php

declare(strict_types=1);

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);

if ($key === false) {
    fwrite(STDERR, "Unable to create EC key pair.\n");
    exit(1);
}

$privateKeyPem = '';
if (!openssl_pkey_export($key, $privateKeyPem)) {
    fwrite(STDERR, "Unable to export private key.\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
    fwrite(STDERR, "Unable to extract public key coordinates.\n");
    exit(1);
}

$publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
$publicKey = base64UrlEncode($publicRaw);
$privateKeyOneLine = str_replace("\n", '\n', trim($privateKeyPem));

echo "PUSH_VAPID_PUBLIC_KEY=" . $publicKey . PHP_EOL;
echo "PUSH_VAPID_PRIVATE_KEY=" . $privateKeyOneLine . PHP_EOL;
echo "PUSH_VAPID_SUBJECT=mailto:you@example.com" . PHP_EOL;
