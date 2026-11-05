<?php

namespace Leantime\Plugins\SolicitudSoporte\Services;

use Illuminate\Support\Facades\Log;
use Leantime\Core\Mailer;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Setting\Services\Setting as SettingService;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketRepository;

class SolicitudSoporte
{
    public const SETTING_KEY = 'plugins.solicitudsoporte.config';

    public const COMPANIES = ['Proderi', 'Acoficum'];

    public const PRIORITIES = ['Urgente' => 1, 'Alta' => 2, 'Media' => 3, 'Baja' => 4];

    /** Solo se aceptan enlaces de OneDrive / SharePoint (evita enlaces maliciosos en un formulario publico). */
    public const ALLOWED_LINK_HOSTS = ['1drv.ms', 'onedrive.live.com', 'sharepoint.com', 'onedrive.com'];

    public const MAX_LINKS = 10;

    public function __construct(
        private SettingService $settings,
        private ProjectRepository $projects,
        private TicketRepository $tickets,
        private Mailer $mailer,
    ) {}

    public static function defaultConfig(): array
    {
        return [
            'creatorUserId' => 0,
            'confirmRequester' => true,
            'requestTypes' => implode("\n", [
                'Automatizaciones en Microsoft', 'Soporte Técnico', 'Soporte Técnico de Plataforma',
                'Solicitud Área de Datos', 'Mantenimiento Correctivo', 'Mantenimiento Preventivo',
                'Formateo de equipo', 'Instalación de software', 'Impresora', 'Creación de Usuario Alena',
                'Creación de Usuario Alena Compliance', 'Creación de Empresa/Usuario en Vixor',
                'Creación de correo corporativo (buzón)', 'Creación de correo corporativo (Licencia)',
                'Apoyo al área comercial', 'Apoyo al área de operaciones Surtigas', 'Proyecto', 'Reuniones', 'Otras',
            ]),
            'areas' => [
                'soporte' => ['label' => 'Ticket de Soporte', 'projectName' => 'Ticket de soporte', 'projectId' => 0, 'notify' => ''],
                'datos' => ['label' => 'Análisis de Datos', 'projectName' => 'Analisis de Datos', 'projectId' => 0, 'notify' => ''],
            ],
        ];
    }

    public function getConfig(): array
    {
        $default = self::defaultConfig();
        $saved = json_decode((string) $this->settings->getSetting(self::SETTING_KEY, ''), true);
        if (! is_array($saved)) {
            return $default;
        }

        $config = array_merge($default, array_intersect_key($saved, $default));
        foreach ($default['areas'] as $key => $area) {
            $config['areas'][$key] = array_merge($area, $saved['areas'][$key] ?? []);
        }

        return $config;
    }

    public function saveConfig(array $config): void
    {
        $this->settings->saveSetting(self::SETTING_KEY, json_encode($config));
    }

    /** @return string[] */
    public function getRequestTypes(): array
    {
        $lines = preg_split('/\R/', (string) $this->getConfig()['requestTypes']) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    }

    /** @return string[] */
    public static function parseLines(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), fn ($l) => $l !== '')));
    }

    /** @return string[] */
    public static function parseEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    /** Valida los datos del formulario. Devuelve lista de errores (vacia = ok). */
    public function validate(array $in): array
    {
        $errors = [];
        $config = $this->getConfig();

        if (! isset($config['areas'][$in['area'] ?? ''])) {
            $errors[] = 'Seleccione el área a la que dirige la solicitud.';
        }
        if (! in_array($in['company'] ?? '', self::COMPANIES, true)) {
            $errors[] = 'Seleccione la empresa.';
        }
        if (trim((string) ($in['name'] ?? '')) === '') {
            $errors[] = 'Ingrese su nombre completo.';
        }
        if (! filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ingrese un correo electrónico válido.';
        }
        if (! in_array($in['type'] ?? '', $this->getRequestTypes(), true)) {
            $errors[] = 'Seleccione el tipo de solicitud.';
        }
        if (! isset(self::PRIORITIES[$in['priority'] ?? ''])) {
            $errors[] = 'Seleccione el nivel de prioridad.';
        }
        if (trim((string) ($in['subject'] ?? '')) === '') {
            $errors[] = 'Ingrese el motivo del problema o solicitud.';
        }
        if (trim((string) ($in['description'] ?? '')) === '') {
            $errors[] = 'Ingrese la descripción del problema o solicitud.';
        }
        $links = self::parseLines((string) ($in['links'] ?? ''));
        if (count($links) > self::MAX_LINKS) {
            $errors[] = 'Máximo '.self::MAX_LINKS.' enlaces de archivos.';
        }
        foreach ($links as $link) {
            if (! self::isAllowedLink($link)) {
                $errors[] = 'Enlace no válido (use enlaces de OneDrive o SharePoint, que empiecen por https://): '.$link;
            }
        }

        return $errors;
    }

    public static function isAllowedLink(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::ALLOWED_LINK_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Crea el ticket y devuelve su id. */
    public function createTicket(array $in): int
    {
        $config = $this->getConfig();
        $area = $config['areas'][$in['area']];
        $projectId = $this->resolveProjectId($area);
        if ($projectId === 0) {
            throw new \RuntimeException('El área "'.$area['label'].'" no tiene un proyecto configurado.');
        }
        $creatorId = $this->resolveCreatorId($config);

        $e = fn ($v) => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
        $description = '<p><strong>Solicitante</strong></p><ul>'
            .'<li>Nombre: '.$e($in['name']).'</li>'
            .'<li>Correo: '.$e($in['email']).'</li>'
            .'<li>Empresa: '.$e($in['company']).'</li>'
            .(trim((string) ($in['position'] ?? '')) !== '' ? '<li>Cargo/Área: '.$e($in['position']).'</li>' : '')
            .'</ul><p><strong>Tipo de solicitud:</strong> '.$e($in['type']).'<br><strong>Prioridad:</strong> '.$e($in['priority']).'</p>'
            .'<p><strong>Descripción</strong></p><p>'.nl2br($e($in['description'])).'</p>';

        $links = array_filter(self::parseLines((string) ($in['links'] ?? '')), [self::class, 'isAllowedLink']);
        if ($links) {
            $description .= '<p><strong>Archivos adjuntos (OneDrive)</strong></p><ul>';
            foreach ($links as $link) {
                $description .= '<li><a href="'.$e($link).'" target="_blank" rel="noopener noreferrer">'.$e($link).'</a></li>';
            }
            $description .= '</ul>';
        }

        $ticketId = $this->tickets->addTicket([
            'headline' => mb_substr(trim(strip_tags($in['subject'])), 0, 250),
            'type' => 'task',
            'description' => $description,
            'date' => gmdate('Y-m-d H:i:s'),
            'dateToFinish' => null,
            'projectId' => $projectId,
            'status' => 3,
            'userId' => $creatorId ?: null,
            'tags' => mb_substr($in['company'].','.$in['type'], 0, 255),
            'sprint' => null,
            'storypoints' => null,
            'priority' => (string) self::PRIORITIES[$in['priority']],
            'hourRemaining' => null,
            'planHours' => null,
            'acceptanceCriteria' => '',
            'editFrom' => null,
            'editTo' => null,
            'editorId' => '',
        ]);

        if ($ticketId === false) {
            throw new \RuntimeException('No se pudo crear el ticket.');
        }

        $this->sendNotifications($in, $area, $config, (int) $ticketId);

        return (int) $ticketId;
    }

    private function resolveProjectId(array $area): int
    {
        if ((int) $area['projectId'] > 0) {
            return (int) $area['projectId'];
        }
        foreach ($this->projects->getAll(true) as $p) {
            if (mb_strtolower(trim($p['name'])) === mb_strtolower(trim($area['projectName']))) {
                return (int) $p['id'];
            }
        }

        return 0;
    }

    private function resolveCreatorId(array $config): int
    {
        if ((int) $config['creatorUserId'] > 0) {
            return (int) $config['creatorUserId'];
        }
        $row = app('db')->connection()->table('zp_user')->whereIn('role', [50, 40])->orderByDesc('role')->orderBy('id')->first();

        return $row ? (int) $row->id : 0;
    }

    private function sendNotifications(array $in, array $area, array $config, int $ticketId): void
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $link = BASE_URL.'/dashboard/home#/tickets/showTicket/'.$ticketId;

        $team = self::parseEmails((string) $area['notify']);
        if ($team) {
            $this->send($team, "Nueva solicitud #{$ticketId}: ".$in['subject'],
                '<p>Se recibió una nueva solicitud para <strong>'.$e($area['label']).'</strong>.</p>'
                .'<p><strong>Solicitante:</strong> '.$e($in['name']).' ('.$e($in['company']).') - '.$e($in['email']).'<br>'
                .'<strong>Tipo:</strong> '.$e($in['type']).'<br><strong>Prioridad:</strong> '.$e($in['priority']).'<br>'
                .'<strong>Motivo:</strong> '.$e($in['subject']).'</p>'
                .'<p><a href="'.$e($link).'">Abrir el ticket #'.$ticketId.'</a></p>');
        }

        if (! empty($config['confirmRequester'])) {
            $this->send([$in['email']], "Hemos recibido su solicitud #{$ticketId}",
                '<p>Hola '.$e($in['name']).',</p>'
                .'<p>Recibimos su solicitud y la asignaremos a un especialista a la brevedad.</p>'
                .'<p><strong>Número de ticket:</strong> #'.$ticketId.'<br><strong>Área:</strong> '.$e($area['label']).'<br>'
                .'<strong>Tipo:</strong> '.$e($in['type']).'<br><strong>Prioridad:</strong> '.$e($in['priority']).'<br>'
                .'<strong>Motivo:</strong> '.$e($in['subject']).'</p>'
                .'<p>Este es un mensaje automático.</p>');
        }
    }

    private function send(array $to, string $subject, string $html): void
    {
        try {
            $this->mailer->setContext('solicitud_soporte');
            $this->mailer->setSubject($subject);
            $this->mailer->setHtml($html);
            $this->mailer->sendMail($to, 'Solicitudes de soporte');
        } catch (\Throwable $ex) {
            Log::warning('SolicitudSoporte: no se pudo enviar correo: '.$ex->getMessage());
        }
    }
}
