<?php

namespace Leantime\Plugins\SolicitudSoporte\Listeners;

use Illuminate\Support\Facades\Log;
use Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte;

/**
 * Avisa al solicitante cuando su ticket pasa a un estado de tipo "Hecho".
 * Se engancha al evento del repositorio de tickets (tablero Kanban y cambio de estado rapido).
 */
class NotifyRequesterDone
{
    public function __construct(private SolicitudSoporte $service) {}

    public function handle(array $payload): void
    {
        $ticketId = (int) ($payload['ticketId'] ?? 0);
        if ($ticketId <= 0 || ! isset($payload['status']) || ! is_numeric($payload['status'])) {
            return;
        }

        try {
            $result = $this->service->notifyRequesterDone($ticketId, (int) $payload['status']);
            Log::info('SolicitudSoporte aviso Hecho: ticket '.$ticketId.' estado '.(int) $payload['status'].' -> '.$result);
        } catch (\Throwable $e) {
            // Nunca debe romper el cambio de estado del tablero.
            Log::warning('SolicitudSoporte: fallo al avisar cierre del ticket '.$ticketId.': '.$e->getMessage());
        }
    }
}
