<?php
// Minimal WebAuthn (passkey) support for "Sign in with fingerprint / screen lock".
// Only ES256 (ECDSA P-256) credentials are accepted — that is what Windows Hello,
// Android, iOS/macOS and security keys use by default. Attestation is not requested
// ("none"), which is the normal choice for a sign-in feature.
//
// WebAuthn only works on https:// pages or on http://localhost.

function b64u_enc(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function b64u_dec(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

function webauthnRpId(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host);
}

function webauthnOrigin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Tiny CBOR decoder (enough for attestation objects and COSE keys). */
function cborDecode(string $data, int &$o = 0) {
    $len = strlen($data);
    if ($o >= $len) throw new RuntimeException('CBOR: unexpected end');
    $ib = ord($data[$o++]);
    $major = $ib >> 5;
    $info = $ib & 0x1f;

    $readUint = function (int $info) use (&$o, $data, $len) {
        if ($info < 24) return $info;
        $n = match ($info) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new RuntimeException('CBOR: bad length') };
        if ($o + $n > $len) throw new RuntimeException('CBOR: truncated');
        $v = 0;
        for ($i = 0; $i < $n; $i++) { $v = ($v << 8) | ord($data[$o++]); }
        return $v;
    };

    switch ($major) {
        case 0: return $readUint($info);
        case 1: return -1 - $readUint($info);
        case 2:
        case 3:
            $n = $readUint($info);
            if ($o + $n > $len) throw new RuntimeException('CBOR: truncated string');
            $s = substr($data, $o, $n);
            $o += $n;
            return $s;
        case 4:
            $n = $readUint($info);
            $arr = [];
            for ($i = 0; $i < $n; $i++) { $arr[] = cborDecode($data, $o); }
            return $arr;
        case 5:
            $n = $readUint($info);
            $map = [];
            for ($i = 0; $i < $n; $i++) {
                $k = cborDecode($data, $o);
                $map[$k] = cborDecode($data, $o);
            }
            return $map;
        case 7:
            return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => null };
        default:
            throw new RuntimeException('CBOR: unsupported type');
    }
}

function webauthnParseAuthData(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('authData too short');
    $r = [
        'rpIdHash' => substr($ad, 0, 32),
        'flags' => ord($ad[32]),
        'signCount' => unpack('N', substr($ad, 33, 4))[1],
        'credId' => null,
        'cose' => null,
    ];
    if ($r['flags'] & 0x40) {
        $o = 37 + 16; // skip AAGUID
        $idLen = unpack('n', substr($ad, $o, 2))[1];
        $o += 2;
        $r['credId'] = substr($ad, $o, $idLen);
        $o += $idLen;
        $r['cose'] = cborDecode($ad, $o);
    }
    return $r;
}

function webauthnCoseToPem(array $cose): string {
    if (($cose[1] ?? null) !== 2 || ($cose[3] ?? null) !== -7 || ($cose[-1] ?? null) !== 1) {
        throw new RuntimeException('Only ES256 (P-256) passkeys are supported.');
    }
    $x = $cose[-2] ?? ''; $y = $cose[-3] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('Bad public key.');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function webauthnCheckClientData(string $clientDataRaw, string $expectedType, string $expectedChallenge): void {
    $cd = json_decode($clientDataRaw, true);
    if (!is_array($cd)) throw new RuntimeException('Bad client data.');
    if (($cd['type'] ?? '') !== $expectedType) throw new RuntimeException('Wrong ceremony type.');
    if (!hash_equals($expectedChallenge, (string)($cd['challenge'] ?? ''))) throw new RuntimeException('Challenge mismatch.');
    if (($cd['origin'] ?? '') !== webauthnOrigin()) throw new RuntimeException('Origin mismatch.');
}

/** @return array{credId:string, pem:string, signCount:int} */
function webauthnVerifyRegistration(string $clientDataB64, string $attestationB64, string $expectedChallenge): array {
    $clientRaw = b64u_dec($clientDataB64);
    webauthnCheckClientData($clientRaw, 'webauthn.create', $expectedChallenge);

    $att = cborDecode(b64u_dec($attestationB64));
    $ad = webauthnParseAuthData((string)($att['authData'] ?? ''));
    if (!hash_equals(hash('sha256', webauthnRpId(), true), $ad['rpIdHash'])) throw new RuntimeException('RP mismatch.');
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('User presence required.');
    if (!($ad['flags'] & 0x04)) throw new RuntimeException('User verification (fingerprint / screen lock) required.');
    if ($ad['credId'] === null || !is_array($ad['cose'])) throw new RuntimeException('No credential data.');

    return ['credId' => $ad['credId'], 'pem' => webauthnCoseToPem($ad['cose']), 'signCount' => $ad['signCount']];
}

/** Returns the new sign counter on success; throws on any failure. */
function webauthnVerifyAssertion(array $a, string $pem, int $storedCount, string $expectedChallenge): int {
    $clientRaw = b64u_dec((string)$a['clientDataJSON']);
    webauthnCheckClientData($clientRaw, 'webauthn.get', $expectedChallenge);

    $authData = b64u_dec((string)$a['authenticatorData']);
    $ad = webauthnParseAuthData($authData);
    if (!hash_equals(hash('sha256', webauthnRpId(), true), $ad['rpIdHash'])) throw new RuntimeException('RP mismatch.');
    if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) throw new RuntimeException('User verification required.');

    $signed = $authData . hash('sha256', $clientRaw, true);
    $ok = openssl_verify($signed, b64u_dec((string)$a['signature']), $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Signature check failed.');

    // Many platform authenticators always report 0; only enforce when counters are in use.
    if (($ad['signCount'] !== 0 || $storedCount !== 0) && $ad['signCount'] <= $storedCount) {
        throw new RuntimeException('Possible cloned authenticator.');
    }
    return $ad['signCount'];
}
