<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Http;

final class CurlTransport implements HttpTransport
{
    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpo = null,
        int $timeoutSegundos = 10,
        string $contentType = 'application/json',
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
        if ($cuerpo !== null) {
            // Cloudbeds API v1.1 exige form-urlencoded en los POST; el resto (Claude API) usa JSON.
            if ($contentType === 'application/x-www-form-urlencoded') {
                $headersArr[] = 'Content-Type: application/x-www-form-urlencoded';
                $opts[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
            } else {
                $headersArr[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE) ?: '';
            }
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
