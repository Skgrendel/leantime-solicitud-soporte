<?php

namespace Leantime\Plugins\SolicitudSoporte\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte;

/**
 * Formulario publico (sin login). URL: /SolicitudSoporte/index
 */
class Index extends Controller
{
    private SolicitudSoporte $service;

    public function init(SolicitudSoporte $service): void
    {
        $this->service = $service;
    }

    public function get(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render([], [], isset($_GET['enviado']) ? (int) $_GET['enviado'] : 0);
    }

    public function post($params): \Symfony\Component\HttpFoundation\Response
    {
        // Honeypot: los bots rellenan este campo oculto.
        if (! empty($params['website'])) {
            return Frontcontroller::redirect(BASE_URL.'/SolicitudSoporte/index');
        }

        $rateKey = 'solicitudsoporte:'.request()->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->render($params, ['Demasiadas solicitudes seguidas. Intente de nuevo en unos minutos.']);
        }

        $keys = ['area', 'company', 'name', 'email', 'position', 'type', 'priority', 'subject', 'description', 'links'];
        $input = [];
        foreach ($keys as $k) {
            $input[$k] = trim((string) ($params[$k] ?? ''));
        }

        $errors = $this->service->validate($input);
        if ($errors) {
            return $this->render($input, $errors);
        }

        try {
            RateLimiter::hit($rateKey, 600);
            $ticketId = $this->service->submitRequest($input);
        } catch (\Throwable $e) {
            Log::error('SolicitudSoporte: '.$e->getMessage());

            return $this->render($input, ['No se pudo registrar la solicitud. Intente de nuevo o contacte a TI.']);
        }

        return Frontcontroller::redirect(BASE_URL.'/SolicitudSoporte/index?enviado='.$ticketId);
    }

    private function render(array $old, array $errors, int $sent = 0): \Symfony\Component\HttpFoundation\Response
    {
        $config = $this->service->getConfig();
        $this->tpl->assign('old', $old);
        $this->tpl->assign('errors', $errors);
        $this->tpl->assign('sentTicket', $sent);
        $this->tpl->assign('areas', $config['areas']);
        $this->tpl->assign('companies', SolicitudSoporte::COMPANIES);
        $this->tpl->assign('requestTypes', $this->service->getRequestTypes());
        $this->tpl->assign('priorities', array_keys(SolicitudSoporte::PRIORITIES));

        return $this->tpl->display('solicitudsoporte.index', 'entry');
    }
}
