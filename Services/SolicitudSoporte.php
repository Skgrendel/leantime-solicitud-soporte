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

    public const TABLE = 'zp_solicitudsoporte_requests';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const COMPANIES = ['Proderi', 'Acoficum'];

    public const PRIORITIES = ['Urgente' => 1, 'Alta' => 2, 'Media' => 3, 'Baja' => 4];

    /** Solo se aceptan enlaces de OneDrive / SharePoint (evita enlaces maliciosos en un formulario publico). */
    public const ALLOWED_LINK_HOSTS = ['1drv.ms', 'onedrive.live.com', 'sharepoint.com', 'onedrive.com'];

    public const MAX_LINKS = 10;

    private static bool $tableChecked = false;

    public function __construct(
        private SettingService $settings,
        private ProjectRepository $projects,
        private TicketRepository $tickets,
        private Mailer $mailer,
    ) {}

    // ------------------------------------------------------------------ instalacion

    public function install(): void
    {
        $this->ensureTable();
    }

    /** Crea la tabla del plugin si no existe (se llama al instalar y tambien bajo demanda). */
    public function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }

        $schema = app('db')->connection()->getSchemaBuilder();
        if (! $schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($t) {
                $t->increments('id');
                $t->string('area', 30);
                $t->string('company', 30);
                $t->string('name', 150);
                $t->string('email', 190);
                $t->string('position', 150)->nullable();
                $t->string('type', 190);
                $t->string('priority', 20);
                $t->string('subject', 250);
                $t->text('description');
                $t->text('links')->nullable();
                $t->string('status', 20)->default(self::STATUS_PENDING)->index();
                $t->text('decision_comment')->nullable();
                $t->unsignedInteger('decided_by')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->unsignedInteger('ticket_id')->nullable();
                $t->dateTime('created_at');
            });
        }

        self::$tableChecked = true;
    }

    private function table()
    {
        $this->ensureTable();

        return app('db')->connection()->table(self::TABLE);
    }

    // ------------------------------------------------------------------ configuracion

    public static function defaultConfig(): array
    {
        return [
            'creatorUserId' => 0,
            'confirmRequester' => true,
            'approverEmails' => '',
            'logoUrl' => '',
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
        return self::parseLines((string) $this->getConfig()['requestTypes']);
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

    // ------------------------------------------------------------------ validacion

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

    // ------------------------------------------------------------------ flujo de solicitudes

    /** Guarda la solicitud como pendiente y avisa. Devuelve el id (referencia). */
    public function submitRequest(array $in): int
    {
        $config = $this->getConfig();

        $id = (int) $this->table()->insertGetId([
            'area' => $in['area'],
            'company' => $in['company'],
            'name' => mb_substr(strip_tags($in['name']), 0, 150),
            'email' => $in['email'],
            'position' => mb_substr(strip_tags((string) ($in['position'] ?? '')), 0, 150),
            'type' => $in['type'],
            'priority' => $in['priority'],
            'subject' => mb_substr(trim(strip_tags($in['subject'])), 0, 250),
            'description' => $in['description'],
            'links' => implode("\n", array_filter(self::parseLines((string) ($in['links'] ?? '')), [self::class, 'isAllowedLink'])),
            'status' => self::STATUS_PENDING,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $request = $this->getRequest($id);

        // Aviso a aprobadores
        $approvers = $this->getApproverEmails($config);
        if ($approvers) {
            $this->send($approvers, "Solicitud #{$id} pendiente de aprobación: ".$request['subject'],
                $this->renderEmail([
                    'title' => 'Nueva solicitud pendiente de aprobación',
                    'icon' => '📥',
                    'greeting' => 'Hola,',
                    'intro' => 'Se recibió una solicitud para <strong>'.$this->e($config['areas'][$request['area']]['label']).'</strong> que necesita ser aprobada o rechazada. Estos son los detalles:',
                    'rows' => $this->requestRows($request, $config),
                    'buttonText' => 'Revisar solicitud',
                    'buttonUrl' => BASE_URL.'/SolicitudSoporte/solicitudes?id='.$id,
                ], $config));
        }

        // Acuse de recibo al solicitante
        if (! empty($config['confirmRequester'])) {
            $this->send([$request['email']], "Hemos recibido su solicitud #{$id}",
                $this->renderEmail([
                    'title' => 'Hemos recibido su solicitud',
                    'icon' => '✅',
                    'greeting' => 'Hola '.$this->e($request['name']).',',
                    'intro' => 'Recibimos su solicitud. Será revisada y le informaremos cuando se tome una decisión.',
                    'rows' => [
                        ['Referencia', '#'.$id],
                        ['Área', $this->e($config['areas'][$request['area']]['label'])],
                        ['Tipo de solicitud', $this->e($request['type'])],
                        ['Prioridad', $this->e($request['priority'])],
                        ['Asunto', $this->e($request['subject'])],
                    ],
                    'footer' => 'Este mensaje fue generado automáticamente.',
                ], $config));
        }

        return $id;
    }

    /** Aprueba: crea el ticket, avisa al area. Devuelve el id del ticket. */
    public function approve(int $id, int $userId, string $comment): int
    {
        $config = $this->getConfig();
        $request = $this->getRequest($id);
        if (! $request || $request['status'] !== self::STATUS_PENDING) {
            throw new \RuntimeException('La solicitud ya fue procesada o no existe.');
        }

        $area = $config['areas'][$request['area']] ?? null;
        if (! $area) {
            throw new \RuntimeException('El área de la solicitud ya no existe en la configuración.');
        }
        $projectId = $this->resolveProjectId($area);
        if ($projectId === 0) {
            throw new \RuntimeException('El área "'.$area['label'].'" no tiene un proyecto configurado.');
        }

        $comment = trim($comment);
        $ticketId = $this->createTicket($request, $projectId, $this->resolveCreatorId($config, $userId), $comment);

        $updated = $this->table()->where('id', $id)->where('status', self::STATUS_PENDING)->update([
            'status' => self::STATUS_APPROVED,
            'decision_comment' => $comment !== '' ? $comment : null,
            'decided_by' => $userId ?: null,
            'decided_at' => gmdate('Y-m-d H:i:s'),
            'ticket_id' => $ticketId,
        ]);
        if (! $updated) {
            throw new \RuntimeException('La solicitud ya fue procesada por otra persona.');
        }

        $request = $this->getRequest($id);
        $team = self::parseEmails((string) $area['notify']);
        if ($team) {
            $rows = $this->requestRows($request, $config, true);
            $rows[] = ['Comentario del aprobador', nl2br($this->e($comment))];
            $this->send($team, "Nueva solicitud aprobada #{$id}: ".$request['subject'],
                $this->renderEmail([
                    'title' => 'Nueva solicitud aprobada para atención',
                    'icon' => '📩',
                    'greeting' => 'Hola equipo de '.$this->e(mb_strtolower($area['label'])).',',
                    'intro' => 'Se ha aprobado una solicitud que requiere seguimiento y atención. A continuación, los detalles:',
                    'rows' => $rows,
                    'outro' => 'Por favor, revisen y gestionen esta solicitud según las prioridades asignadas.',
                    'buttonText' => 'Ver solicitud en Leantime',
                    'buttonUrl' => BASE_URL.'/dashboard/home#/tickets/showTicket/'.$ticketId,
                ], $config));
        }

        return $ticketId;
    }

    /** Rechaza: guarda el motivo y avisa al solicitante. */
    public function reject(int $id, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Escriba el motivo del rechazo.');
        }

        $updated = $this->table()->where('id', $id)->where('status', self::STATUS_PENDING)->update([
            'status' => self::STATUS_REJECTED,
            'decision_comment' => $reason,
            'decided_by' => $userId ?: null,
            'decided_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (! $updated) {
            throw new \RuntimeException('La solicitud ya fue procesada o no existe.');
        }

        $config = $this->getConfig();
        $request = $this->getRequest($id);
        $this->send([$request['email']], "Su solicitud #{$id} no fue aprobada",
            $this->renderEmail([
                'title' => 'Su solicitud no fue aprobada',
                'icon' => '⛔',
                'greeting' => 'Hola '.$this->e($request['name']).',',
                'intro' => 'Revisamos su solicitud y, por el momento, no fue aprobada. Estos son los detalles:',
                'rows' => [
                    ['Referencia', '#'.$id],
                    ['Tipo de solicitud', $this->e($request['type'])],
                    ['Prioridad', $this->e($request['priority'])],
                    ['Asunto', $this->e($request['subject'])],
                    ['Motivo del rechazo', nl2br($this->e($reason))],
                ],
                'outro' => 'Si lo considera necesario, puede enviar una nueva solicitud con la información ajustada.',
                'footer' => 'Este mensaje fue generado automáticamente.',
            ], $config));
    }

    // ------------------------------------------------------------------ consultas

    public function getRequest(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    /** @return array<int, array> */
    public function listRequests(string $status = ''): array
    {
        $q = $this->table()->orderByDesc('id');
        if (in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            $q->where('status', $status);
        }

        return $q->limit(300)->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $counts = [self::STATUS_PENDING => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0];
        foreach ($this->table()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $r) {
            $counts[$r->status] = (int) $r->c;
        }

        return $counts;
    }

    public static function formatDate(?string $dbDate): string
    {
        if (! $dbDate) {
            return '';
        }

        try {
            return dtHelper()->parseDbDateTime($dbDate)->setToUserTimezone()->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $dbDate;
        }
    }

    // ------------------------------------------------------------------ internos

    private function createTicket(array $r, int $projectId, int $creatorId, string $approverComment): int
    {
        $e = fn ($v) => $this->e($v);
        $description = '<p><strong>Solicitante</strong></p><ul>'
            .'<li>Nombre: '.$e($r['name']).'</li>'
            .'<li>Correo: '.$e($r['email']).'</li>'
            .'<li>Empresa: '.$e($r['company']).'</li>'
            .(trim((string) $r['position']) !== '' ? '<li>Cargo/Área: '.$e($r['position']).'</li>' : '')
            .'</ul><p><strong>Tipo de solicitud:</strong> '.$e($r['type']).'<br><strong>Prioridad:</strong> '.$e($r['priority']).'<br><strong>Referencia:</strong> #'.(int) $r['id'].'</p>'
            .'<p><strong>Descripción</strong></p><p>'.nl2br($e($r['description'])).'</p>';

        $links = array_filter(self::parseLines((string) $r['links']), [self::class, 'isAllowedLink']);
        if ($links) {
            $description .= '<p><strong>Archivos adjuntos (OneDrive)</strong></p><ul>';
            foreach ($links as $link) {
                $description .= '<li><a href="'.$e($link).'" target="_blank" rel="noopener noreferrer">'.$e($link).'</a></li>';
            }
            $description .= '</ul>';
        }
        if ($approverComment !== '') {
            $description .= '<p><strong>Comentario del aprobador</strong></p><p>'.nl2br($e($approverComment)).'</p>';
        }

        $ticketId = $this->tickets->addTicket([
            'headline' => mb_substr(trim(strip_tags($r['subject'])), 0, 250),
            'type' => 'task',
            'description' => $description,
            'date' => gmdate('Y-m-d H:i:s'),
            'dateToFinish' => null,
            'projectId' => $projectId,
            'status' => 3,
            'userId' => $creatorId ?: null,
            'tags' => mb_substr($r['company'].','.$r['type'], 0, 255),
            'sprint' => null,
            'storypoints' => null,
            'priority' => (string) (self::PRIORITIES[$r['priority']] ?? 3),
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

    /** Usuario que figura como creador del ticket: el configurado, o el que aprueba. */
    private function resolveCreatorId(array $config, int $approverId): int
    {
        if ((int) $config['creatorUserId'] > 0) {
            return (int) $config['creatorUserId'];
        }

        return $approverId;
    }

    /** Correos de aprobadores: los configurados o, si no hay, todos los admin/owner. */
    private function getApproverEmails(array $config): array
    {
        $emails = self::parseEmails((string) $config['approverEmails']);
        if ($emails) {
            return $emails;
        }

        $rows = app('db')->connection()->table('zp_user')->whereIn('role', [40, 50])->pluck('username')->all();

        return self::parseEmails(implode(',', $rows));
    }

    private function requestRows(array $r, array $config, bool $withTicketNote = false): array
    {
        $rows = [
            ['Solicitante', $this->e($r['name']).' ('.$this->e($r['company']).')<br>'.$this->e($r['email'])],
            ['Tipo de solicitud', $this->e($r['type'])],
            ['Prioridad', $this->e($r['priority'])],
            ['Asunto', $this->e($r['subject'])],
            ['Descripción', nl2br($this->e($r['description']))],
        ];

        $links = array_filter(self::parseLines((string) $r['links']), [self::class, 'isAllowedLink']);
        if ($links) {
            $items = array_map(fn ($l) => '<a href="'.$this->e($l).'" style="color:#0078d4;">'.$this->e($l).'</a>', $links);
            $rows[] = ['Archivos (OneDrive)', implode('<br>', $items)];
        }

        return $rows;
    }

    private function e(mixed $v): string
    {
        return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
    }

    private function logoUrl(array $config): string
    {
        $url = trim((string) $config['logoUrl']);
        if ($url === '') {
            $path = (string) $this->settings->getSetting('companysettings.logoPath', '');
            $url = ($path !== '' && ! str_ends_with(strtolower($path), '.svg')) ? $path : '/dist/images/logo_blue.png';
        }

        return str_starts_with($url, 'http') ? $url : rtrim(BASE_URL, '/').'/'.ltrim($url, '/');
    }

    /**
     * Correo con el diseno de la plantilla (barra lateral oscura + tarjeta).
     * Claves: title, icon, greeting, intro, rows[[label, htmlValue]], outro, buttonText, buttonUrl, footer
     */
    private function renderEmail(array $m, array $config): string
    {
        $rows = '';
        foreach ($m['rows'] ?? [] as $i => [$label, $value]) {
            $bg = $i % 2 === 0 ? '#f2f2f2' : '#ffffff';
            $rows .= '<tr style="background:'.$bg.';"><td style="padding:10px 12px;border:1px solid #e1e1e1;font-weight:bold;width:32%;vertical-align:top;">'
                .$this->e($label).'</td><td style="padding:10px 12px;border:1px solid #e1e1e1;vertical-align:top;">'.$value.'</td></tr>';
        }

        $button = '';
        if (! empty($m['buttonUrl'])) {
            $button = '<p style="text-align:center;margin:24px 0 8px;"><a href="'.$this->e($m['buttonUrl']).'" style="background:#0078d4;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;font-weight:bold;display:inline-block;">'
                .$this->e($m['buttonText'] ?? 'Ver solicitud').'</a></p>';
        }

        $footer = $m['footer'] ?? 'Este mensaje fue generado automáticamente por el sistema de gestión de solicitudes.';

        return '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;font-family:Segoe UI,Arial,sans-serif;color:#333;"><tr><td align="center">'
            .'<table width="680" cellpadding="0" cellspacing="0" style="width:680px;max-width:100%;"><tr>'
            .'<td width="150" valign="top" style="background:#0b2540;padding:24px 12px;text-align:center;border-radius:8px 0 0 8px;">'
            .'<img src="'.$this->e($this->logoUrl($config)).'" alt="Logo" width="120" style="width:120px;background:#ffffff;padding:8px;border-radius:6px;"></td>'
            .'<td valign="top" style="background:#ffffff;padding:28px;border-radius:0 8px 8px 0;font-size:15px;line-height:1.5;">'
            .'<h2 style="margin:0 0 20px;text-align:center;font-size:22px;">'.($m['icon'] ?? '').' '.$this->e($m['title']).'</h2>'
            .'<p>'.($m['greeting'] ?? '').'</p><p>'.($m['intro'] ?? '').'</p>'
            .'<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;font-size:14px;">'.$rows.'</table>'
            .(! empty($m['outro']) ? '<p>'.$m['outro'].'</p>' : '')
            .$button
            .'<p style="text-align:center;color:#888;font-size:12px;margin-top:20px;">'.$this->e($footer).'</p>'
            .'</td></tr></table></td></tr></table>';
    }

    private function send(array $to, string $subject, string $html): void
    {
        try {
            $this->mailer->setContext('solicitud_soporte');
            $this->mailer->setSubject($subject);
            $this->mailer->setHtml($html, true);
            $this->mailer->sendMail($to, 'Solicitudes de soporte');
        } catch (\Throwable $ex) {
            Log::warning('SolicitudSoporte: no se pudo enviar correo: '.$ex->getMessage());
        }
    }
}
