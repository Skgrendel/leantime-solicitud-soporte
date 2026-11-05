<?php

use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\Plugins\Services\Registration;

$registration = app()->makeWith(Registration::class, ['pluginId' => 'SolicitudSoporte']);
$registration->registerLanguageFiles(['es-ES', 'en-US']);
$registration->addMenuItem([
    'title' => 'solicitudsoporte.menu.inbox',
    'icon' => 'fa fa-inbox',
    'tooltip' => 'solicitudsoporte.menu.inbox_tooltip',
    'href' => '/SolicitudSoporte/solicitudes',
], 'company', [16]);
// La configuracion es una pestana dentro de la misma pantalla (no tiene entrada propia en el menu).

// Avisa al solicitante cuando su ticket pasa a "Hecho" (tablero Kanban y cambio rapido de estado).
EventDispatcher::add_event_listener(
    'leantime.domain.tickets.repositories.tickets.*.ticketStatusUpdate',
    \Leantime\Plugins\SolicitudSoporte\Listeners\NotifyRequesterDone::class
);

// El formulario es publico: no exige iniciar sesion en Leantime.
// La comparacion de rutas distingue mayusculas, por eso se declaran ambas variantes.
EventDispatcher::add_filter_listener('leantime.core.middleware.authcheck.*publicActions', function (array $actions) {
    $actions[] = 'SolicitudSoporte.index';
    $actions[] = 'solicitudsoporte.index';

    return $actions;
});
