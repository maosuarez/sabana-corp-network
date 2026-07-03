<?php
// Implementación mínima de JWT (HS256) para el sistema de sesiones del helpdesk.
//
// VULN: JWT inseguro (Reto 1, vulnerabilidad #5b). jwt_decode_unverified() deliberadamente NO recalcula
// ni compara la firma HMAC contra la firma recibida en el token — solo decodifica el payload. Cualquier
// participante puede tomar su propio JWT, cambiar el claim `role` (user -> it -> admin), reconstruir el
// token con la firma que quiera (o incluso la vieja) y el backend lo aceptará igual. No "arreglar" esto
// sin que el usuario lo pida explícitamente (ver CLAUDE.md).

function jwt_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_base64url_decode(string $data): string
{
    $b64 = strtr($data, '-_', '+/');
    $padding = strlen($b64) % 4;
    if ($padding > 0) {
        $b64 .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($b64) ?: '';
}

function jwt_encode(array $payload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        jwt_base64url_encode(json_encode($header)),
        jwt_base64url_encode(json_encode($payload)),
    ];
    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, $secret, true);
    $segments[] = jwt_base64url_encode($signature);
    return implode('.', $segments);
}

function jwt_decode_unverified(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    $payloadJson = jwt_base64url_decode($parts[1]);
    $payload = json_decode($payloadJson, true);

    return is_array($payload) ? $payload : null;
}
