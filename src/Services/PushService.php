<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $auth = [
            'VAPID' => [
                'subject'    => Config::get('VAPID_SUBJECT', 'mailto:admin@atankalama.cl'),
                'publicKey'  => Config::require('VAPID_PUBLIC_KEY'),
                'privateKey' => Config::require('VAPID_PRIVATE_KEY'),
            ],
        ];
        $this->webPush = new WebPush($auth);
        $this->webPush->setReuseVAPIDHeaders(true);
        $this->webPush->setDefaultOptions(['TTL' => 3600]);
    }

    public function suscribir(int $usuarioId, string $endpoint, string $p256dh, string $auth): void
    {
        Database::execute(
            'INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(usuario_id, endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth',
            [$usuarioId, $endpoint, $p256dh, $auth]
        );
    }

    public function desuscribir(int $usuarioId, string $endpoint): void
    {
        Database::execute(
            'DELETE FROM push_subscriptions WHERE usuario_id = ? AND endpoint = ?',
            [$usuarioId, $endpoint]
        );
    }

    public function desuscribirTodo(int $usuarioId): void
    {
        Database::execute('DELETE FROM push_subscriptions WHERE usuario_id = ?', [$usuarioId]);
    }

    /**
     * Envía una notificación push a todos los dispositivos de los usuarios indicados.
     */
    public function notificar(array $usuarioIds, string $titulo, string $cuerpo, string $url = '/home', array $acciones = [], bool $requireInteraction = false): void
    {
        if (empty($usuarioIds)) return;

        $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));
        $suscripciones = Database::fetchAll(
            "SELECT * FROM push_subscriptions WHERE usuario_id IN ({$placeholders})",
            $usuarioIds
        );

        if (empty($suscripciones)) return;

        $payload = json_encode([
            'title'               => $titulo,
            'body'                => $cuerpo,
            'url'                 => $url,
            'actions'             => $acciones,
            'requireInteraction'  => $requireInteraction,
        ]);

        $caidas = [];

        foreach ($suscripciones as $sub) {
            $subscription = Subscription::create([
                'endpoint'        => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh'],
                    'auth'   => $sub['auth'],
                ],
            ]);
            $this->webPush->queueNotification($subscription, $payload);
        }

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $caidas[] = $report->getEndpoint();
                // Limpiar suscripciones con endpoint inválido (410 Gone)
                if ($report->getResponse() !== null && $report->getResponse()->getStatusCode() === 410) {
                    Database::execute(
                        'DELETE FROM push_subscriptions WHERE endpoint = ?',
                        [$report->getEndpoint()]
                    );
                }
            }
        }

        if (!empty($caidas)) {
            Logger::warning('push', 'Fallos enviando notificaciones', ['endpoints_caidos' => count($caidas)]);
        }
    }

    /**
     * Atajos semánticos para tipos de alerta.
     */
    public function notificarRechazo(array $supervisoraIds, string $numeroHab, string $hotelCodigo): void
    {
        $hotel = $hotelCodigo === '1_sur' ? 'Atankalama' : 'Atankalama INN';
        $this->notificar(
            $supervisoraIds,
            '⚠️ Habitación rechazada',
            "Hab. #{$numeroHab} ({$hotel}) fue rechazada y necesita reasignación.",
            '/auditoria',
            [],
            true
        );
    }

    public function notificarTrabajadorEnRiesgo(array $supervisoraIds, string $nombreTrabajador, int $pendientes): void
    {
        $this->notificar(
            $supervisoraIds,
            '🕐 Trabajadora en riesgo',
            "{$nombreTrabajador} tiene {$pendientes} hab. pendientes y puede no terminar a tiempo.",
            '/home',
            [],
            true
        );
    }
}
