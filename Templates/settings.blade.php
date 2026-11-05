@extends($layout)

@section('content')
<?php $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); ?>
<style>
    .ss-tabs { display:flex; flex-wrap:wrap; gap:6px; border-bottom:1px solid #ddd; margin-bottom:20px; }
    .ss-tabs a { padding:10px 16px; border-radius:6px 6px 0 0; text-decoration:none; color:#444; }
    .ss-tabs a.active { background:#fff; border:1px solid #ddd; border-bottom:1px solid #fff; margin-bottom:-1px; font-weight:bold; color:#006d9f; }
    .ss-tabs .ss-right { margin-left:auto; }
    .ss-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:20px; margin-bottom:20px; }
    .ss-card { background:#fff; border:1px solid #e1e1e1; border-radius:8px; padding:18px 20px; }
    .ss-card h4 { margin:0 0 14px; font-size:16px; }
    .ss-card .form-group { margin-bottom:14px; }
    .ss-card label { font-weight:600; display:block; margin-bottom:4px; }
    .ss-card .form-control, .ss-card select, .ss-card textarea, .ss-card input[type=url] { width:100%; box-sizing:border-box; }
    .ss-card .help-block { color:#777; font-size:12px; margin:4px 0 0; display:block; }
    .ss-actions { display:flex; gap:10px; align-items:center; margin-top:8px; }
</style>

<x-global::pageheader :icon="'fa fa-life-ring'">
    <h1>Solicitudes de soporte</h1>
</x-global::pageheader>

<div class="maincontent">
    <div class="maincontentinner">
        <nav class="ss-tabs">
            <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado=pending">Pendientes ({{ $counts['pending'] ?? 0 }})</a>
            <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado=approved">Aprobadas ({{ $counts['approved'] ?? 0 }})</a>
            <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado=rejected">Rechazadas ({{ $counts['rejected'] ?? 0 }})</a>
            <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado=all">Todas</a>
            <a class="active ss-right" href="<?= BASE_URL ?>/SolicitudSoporte/settings"><i class="fa fa-cog"></i> Configuración</a>
        </nav>

        <p>
            Formulario público para compartir:
            <a href="<?= $h($formUrl) ?>" target="_blank"><?= $h($formUrl) ?></a>
            (no requiere iniciar sesión).
        </p>

        <form method="post" action="<?= BASE_URL ?>/SolicitudSoporte/settings">

            <div class="ss-grid">
                @foreach ($config['areas'] as $key => $area)
                    <div class="ss-card">
                        <h4><i class="fa fa-folder-open"></i> Área: {{ $area['label'] }}</h4>
                        <div class="form-group">
                            <label>Proyecto donde se crean los tickets</label>
                            <select name="areas[{{ $key }}][projectId]" class="form-control">
                                <option value="0">Automático (por nombre: "{{ $area['projectName'] }}")</option>
                                @foreach ($projects as $p)
                                    <option value="{{ $p['id'] }}" <?= (int) $area['projectId'] === (int) $p['id'] ? 'selected' : '' ?>>{{ $p['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Correos a notificar cuando se aprueba una solicitud</label>
                            <textarea name="areas[{{ $key }}][notify]" class="form-control" rows="3" placeholder="ti@proderi.com">{{ $area['notify'] }}</textarea>
                            <small class="help-block">Uno por línea o separados por coma.</small>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ss-grid">
                <div class="ss-card">
                    <h4><i class="fa fa-envelope"></i> Notificaciones</h4>
                    <div class="form-group">
                        <label>Correos de los aprobadores</label>
                        <textarea name="approverEmails" class="form-control" rows="3" placeholder="Vacío = todos los administradores de Leantime">{{ $config['approverEmails'] }}</textarea>
                        <small class="help-block">Reciben el aviso al llegar una solicitud. Quien aprueba o rechaza debe ser administrador u owner de Leantime.</small>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="confirmRequester" value="1" <?= ! empty($config['confirmRequester']) ? 'checked' : '' ?>>
                            Enviar acuse de recibo al solicitante cuando envía la solicitud
                        </label>
                    </div>
                    <div class="form-group">
                        <label>URL del logo para los correos (opcional)</label>
                        <input type="url" name="logoUrl" class="form-control" value="{{ $config['logoUrl'] }}" placeholder="https://.../logo.png">
                        <small class="help-block">Imagen PNG/JPG accesible desde Internet. Vacío = logo configurado en Leantime.</small>
                    </div>
                </div>

                <div class="ss-card">
                    <h4><i class="fa fa-ticket"></i> Tickets y formulario</h4>
                    <div class="form-group">
                        <label>Usuario que figura como creador de los tickets</label>
                        <select name="creatorUserId" class="form-control">
                            <option value="0">Automático (quien aprueba la solicitud)</option>
                            @foreach ($users as $u)
                                <option value="{{ $u['id'] }}" <?= (int) $config['creatorUserId'] === $u['id'] ? 'selected' : '' ?>>{{ $u['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Empresas del solicitante</label>
                        <textarea name="companies" class="form-control" rows="4">{{ $config['companies'] }}</textarea>
                        <small class="help-block">Una por línea. Es la lista del desplegable "Empresa" del formulario. Las solicitudes ya recibidas conservan su empresa aunque la quites de la lista.</small>
                    </div>
                    <div class="form-group">
                        <label>Tipos de solicitud</label>
                        <textarea name="requestTypes" class="form-control" rows="10">{{ $config['requestTypes'] }}</textarea>
                        <small class="help-block">Uno por línea. Es la lista del desplegable del formulario.</small>
                    </div>
                </div>
            </div>

            <div class="ss-actions">
                <input type="submit" value="Guardar configuración" class="btn btn-primary">
                <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes" class="btn btn-default">Volver a solicitudes</a>
            </div>
        </form>
    </div>
</div>
@endsection
