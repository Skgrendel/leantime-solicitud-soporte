<?php

namespace Leantime\Plugins\SolicitudSoporte\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte;

/**
 * Configuracion (solo owner/admin). URL: /SolicitudSoporte/settings
 */
class Settings extends Controller
{
    private SolicitudSoporte $service;

    private ProjectRepository $projects;

    public function init(SolicitudSoporte $service, ProjectRepository $projects): void
    {
        Auth::authOrRedirect([Roles::$owner, Roles::$admin], true);
        $this->service = $service;
        $this->projects = $projects;
    }

    public function get(): \Symfony\Component\HttpFoundation\Response
    {
        $users = app('db')->connection()->table('zp_user')
            ->select('id', 'firstname', 'lastname', 'username')->orderBy('firstname')->get()
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->firstname.' '.$u->lastname).' ('.$u->username.')'])
            ->all();

        $this->tpl->assign('counts', $this->service->countByStatus());
        $this->tpl->assign('config', $this->service->getConfig());
        $this->tpl->assign('projects', $this->projects->getAll(true));
        $this->tpl->assign('users', $users);
        $this->tpl->assign('formUrl', BASE_URL.'/SolicitudSoporte/index');

        return $this->tpl->display('solicitudsoporte.settings');
    }

    public function post($params): \Symfony\Component\HttpFoundation\Response
    {
        $config = $this->service->getConfig();

        $config['creatorUserId'] = (int) ($params['creatorUserId'] ?? 0);
        $config['confirmRequester'] = ! empty($params['confirmRequester']);
        $config['approverEmails'] = implode(', ', SolicitudSoporte::parseEmails((string) ($params['approverEmails'] ?? '')));
        $logoUrl = trim((string) ($params['logoUrl'] ?? ''));
        $config['logoUrl'] = filter_var($logoUrl, FILTER_VALIDATE_URL) ? $logoUrl : '';

        $companies = SolicitudSoporte::parseLines((string) ($params['companies'] ?? ''));
        if ($companies) {
            $config['companies'] = implode("\n", $companies);
        }

        $types = SolicitudSoporte::parseLines((string) ($params['requestTypes'] ?? ''));
        if ($types) {
            $config['requestTypes'] = implode("\n", $types);
        }

        foreach (array_keys($config['areas']) as $key) {
            $config['areas'][$key]['projectId'] = (int) ($params['areas'][$key]['projectId'] ?? 0);
            $config['areas'][$key]['notify'] = implode(', ', SolicitudSoporte::parseEmails((string) ($params['areas'][$key]['notify'] ?? '')));
        }

        $this->service->saveConfig($config);
        $this->tpl->setNotification('Configuración guardada.', 'success');

        return Frontcontroller::redirect(BASE_URL.'/SolicitudSoporte/settings');
    }
}
