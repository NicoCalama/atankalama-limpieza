<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Http;

final class CurlTransport implements HttpTransport
{
    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpoJson = null,
        int $timeoutSegundos = 10,
    ): HttpResponse {
        $ch = curl_init();

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSegundos, 5),
            CURLOPT_FOLLOWLOCATION => false,
        ];

        $headersArr = [];
        foreach ($headers as $n => $v) {
            $headersArr[] = $n . ': ' . $v;
        }
        if ($cuerpoJson !== null) {
            $headersArr[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpoJson, JSON_UNESCAPED_UNICODE) ?: '';
        }
        $opts[CURLOPT_HTTPHEADER] = $headersArr;

        curl_setopt_array($ch, $opts);
        $cuerpo = curl_exec($ch);
        $errno = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $cuerpo === false) {
            return new HttpResponse(status: 0, cuerpo: '', errorRed: $errMsg !== '' ? $errMsg : "curl errno {$errno}");
        }

        return new HttpResponse(status: $status, cuerpo: (string) $cuerpo);
    }
}
