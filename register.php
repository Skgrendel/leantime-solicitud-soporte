<?php

use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\Plugins\Services\Registration;

$registration = app()->makeWith(Registration::class, ['pluginId' => 'SolicitudSoporte']);
$registration->registerLanguageFiles(['es-ES', 'en-US']);
$registration->addMenuItem([
    'title' => 'solicitudsoporte.menu.title',
    'icon' => 'fa fa-life-ring',
    'tooltip' => 'solicitudsoporte.menu.tooltip',
    'href' => '/SolicitudSoporte/settings',
], 'company', [16]);

// El formulario es publico: no exige iniciar sesion en Leantime.
// La comparacion de rutas distingue mayusculas, por eso se declaran ambas variantes.
EventDispatcher::add_filter_listener('leantime.core.middleware.authcheck.*publicActions', function (array $actions) {
    $actions[] = 'SolicitudSoporte.index';
    $actions[] = 'solicitudsoporte.index';

    return $actions;
});
