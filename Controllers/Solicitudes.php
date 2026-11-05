<?php

namespace Leantime\Plugins\SolicitudSoporte\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte;

/**
 * Bandeja de aprobacion (solo owner/admin). URL: /SolicitudSoporte/solicitudes
 */
class Solicitudes extends Controller
{
    private SolicitudSoporte $service;

    public function init(SolicitudSoporte $service): void
    {
        Auth::authOrRedirect([Roles::$owner, Roles::$admin], true);
        $this->service = $service;
    }

    public function get(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render((string) ($_GET['estado'] ?? SolicitudSoporte::STATUS_PENDING), (int) ($_GET['id'] ?? 0));
    }

    public function post($params): \Symfony\Component\HttpFoundation\Response
    {
        $id = (int) ($params['id'] ?? 0);
        $decision = (string) ($params['decision'] ?? '');
        $comment = (string) ($params['comment'] ?? '');
        $userId = (int) session('userdata.id');
        $back = BASE_URL.'/SolicitudSoporte/solicitudes';

        try {
            if ($decision === 'approve') {
                $ticketId = $this->service->approve($id, $userId, $comment);
                $this->tpl->setNotification("Solicitud #{$id} aprobada. Se creó el ticket #{$ticketId} y se avisó al área.", 'success');
            } elseif ($decision === 'reject') {
                $this->service->reject($id, $userId, $comment);
                $this->tpl->setNotification("Solicitud #{$id} rechazada. Se envió el motivo al solicitante.", 'success');
            } else {
                $this->tpl->setNotification('Acción no válida.', 'error');
            }
        } catch (\InvalidArgumentException $e) {
            // Falta el motivo: se vuelve a mostrar el detalle conservando lo escrito.
            $this->tpl->setNotification($e->getMessage(), 'error');

            return $this->render(SolicitudSoporte::STATUS_PENDING, $id, $comment);
        } catch (\Throwable $e) {
            $this->tpl->setNotification($e->getMessage(), 'error');
        }

        return Frontcontroller::redirect($back);
    }

    private function render(string $status, int $id, string $draftComment = ''): \Symfony\Component\HttpFoundation\Response
    {
        $valid = [SolicitudSoporte::STATUS_PENDING, SolicitudSoporte::STATUS_APPROVED, SolicitudSoporte::STATUS_REJECTED, 'all'];
        $status = in_array($status, $valid, true) ? $status : SolicitudSoporte::STATUS_PENDING;

        $config = $this->service->getConfig();
        $this->tpl->assign('status', $status);
        $this->tpl->assign('counts', $this->service->countByStatus());
        $this->tpl->assign('requests', $this->service->listRequests($status === 'all' ? '' : $status));
        $this->tpl->assign('selected', $id > 0 ? $this->service->getRequest($id) : null);
        $this->tpl->assign('draftComment', $draftComment);
        $this->tpl->assign('areas', $config['areas']);

        return $this->tpl->display('solicitudsoporte.solicitudes');
    }
}
