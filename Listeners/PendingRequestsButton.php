<?php

namespace Leantime\Plugins\SolicitudSoporte\Listeners;

use Illuminate\Support\Facades\Log;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte;

/**
 * Boton en la barra superior (junto a la campana) que lleva a la bandeja de aprobacion
 * y muestra cuantas solicitudes estan pendientes. Solo lo ven owner y admin.
 */
class PendingRequestsButton
{
    public function __construct(private SolicitudSoporte $service) {}

    public function handle(array $payload = []): void
    {
        try {
            if (! Auth::userHasRole([Roles::$owner, Roles::$admin], true)) {
                return;
            }

            $pending = (int) ($this->service->countByStatus()[SolicitudSoporte::STATUS_PENDING] ?? 0);
        } catch (\Throwable $e) {
            // La barra superior nunca debe romperse por este boton.
            Log::error('SolicitudSoporte: no se pudo pintar el boton de pendientes: '.$e->getMessage());

            return;
        }

        $url = htmlspecialchars(BASE_URL.'/SolicitudSoporte/solicitudes', ENT_QUOTES, 'UTF-8');
        $tip = $pending > 0
            ? "Solicitudes de soporte: $pending pendiente".($pending === 1 ? '' : 's').' de aprobar'
            : 'Solicitudes de soporte: no hay pendientes';
        $badge = $pending > 0
            ? '<span class="notificationCounter">'.($pending > 99 ? '99+' : $pending).'</span>'
            : '';

        echo '<li class="notificationDropdown">'
            .'<a href="'.$url.'" class="profileHandler" data-tippy-content="'.htmlspecialchars($tip, ENT_QUOTES, 'UTF-8').'">'
            .'<span class="fa-solid fa-inbox"></span>'.$badge
            .'</a></li>';
    }
}
