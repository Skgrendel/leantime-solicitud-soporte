@extends($layout)

@section('content')
<?php
$old = $old ?? [];
$v = fn (string $k) => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$sel = fn (string $k, string $val) => (($old[$k] ?? '') === $val) ? 'selected' : '';
?>
<div class="pageheader">
    <div class="pagetitle"><h1>Solicitud de Soporte Técnico</h1></div>
</div>

<style>
    /* Mismo ancho para todos los campos y alturas fijas para las areas de texto */
    .regcontent .form-control { width:100% !important; max-width:100% !important; box-sizing:border-box !important; }
    .regcontent textarea.form-control { resize:vertical; overflow-x:hidden !important; overflow-y:auto; white-space:pre-wrap; min-height:0 !important; }
    .regcontent textarea.ss-ta-desc { height:150px !important; }
    .regcontent textarea.ss-ta-links { height:84px !important; }
</style>
<div class="regcontent">
    @if ($sentTicket > 0)
        <div class="alert alert-success" style="padding:12px;margin-bottom:16px;">
            <strong>¡Solicitud enviada!</strong> Su referencia es <strong>#{{ $sentTicket }}</strong>.
            Será revisada para aprobación y le informaremos la decisión por correo.
        </div>
        <p><a href="<?= BASE_URL ?>/SolicitudSoporte/index">Enviar otra solicitud</a></p>
    @else
        <p>Complete los siguientes campos para que nuestro equipo de TI pueda atender su requerimiento.</p>

        @if (! empty($errors))
            <div class="alert alert-danger" style="padding:12px;margin-bottom:16px;">
                <ul style="margin:0;padding-left:18px;">
                    @foreach ($errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="<?= BASE_URL ?>/SolicitudSoporte/index">
            {{-- Honeypot anti-spam: debe quedar vacio --}}
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
            </div>

            <h4>Solicitante</h4>
            <div class="form-group">
                <label for="company">Empresa *</label>
                <select name="company" id="company" class="form-control" required>
                    <option value="">Selecciona la respuesta</option>
                    @foreach ($companies as $c)
                        <option value="{{ $c }}" <?= $sel('company', $c) ?>>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="name">Nombre completo *</label>
                <input type="text" name="name" id="name" class="form-control" maxlength="120" value="<?= $v('name') ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Correo electrónico *</label>
                <input type="email" name="email" id="email" class="form-control" maxlength="150" value="<?= $v('email') ?>" required>
            </div>
            <div class="form-group">
                <label for="position">Cargo / Área</label>
                <input type="text" name="position" id="position" class="form-control" maxlength="120" value="<?= $v('position') ?>">
            </div>

            <h4>Solicitud</h4>
            <div class="form-group">
                <label for="area">1. ¿A qué área dirige su solicitud? *</label>
                <select name="area" id="area" class="form-control" required>
                    <option value="">Selecciona la respuesta</option>
                    @foreach ($areas as $key => $area)
                        <option value="{{ $key }}" <?= $sel('area', (string) $key) ?>>{{ $area['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="type">2. Tipo de solicitud *</label>
                <select name="type" id="type" class="form-control" required>
                    <option value="">Selecciona la respuesta</option>
                    @foreach ($requestTypes as $t)
                        <option value="{{ $t }}" <?= $sel('type', $t) ?>>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="priority">3. Nivel de prioridad *</label>
                <select name="priority" id="priority" class="form-control" required>
                    <option value="">Selecciona la respuesta</option>
                    @foreach ($priorities as $p)
                        <option value="{{ $p }}" <?= $sel('priority', $p) ?>>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="subject">4. Motivo del problema o solicitud *</label>
                <small class="help-block">Resuma en una frase el inconveniente (ej: "Fallo en conexión de impresora").</small>
                <input type="text" name="subject" id="subject" class="form-control" maxlength="250" value="<?= $v('subject') ?>" required>
            </div>
            <div class="form-group">
                <label for="description">5. Descripción del problema o solicitud *</label>
                <small class="help-block">Explique qué estaba haciendo cuando ocurrió el error, incluya mensajes de error y si el problema es persistente o aleatorio.</small>
                <textarea name="description" id="description" class="form-control ss-ta-desc" rows="5" required><?= $v('description') ?></textarea>
            </div>
            <div class="form-group">
                <label for="links">6. Archivos adjuntos (enlaces de OneDrive)</label>
                <small class="help-block">Suba sus archivos, capturas o vídeos a su OneDrive, copie el enlace para compartir (asegúrese de que el equipo de TI tenga acceso) y péguelo aquí, uno por línea. Máximo 10 enlaces.</small>
                <textarea name="links" id="links" class="form-control ss-ta-links" rows="3" placeholder="https://1drv.ms/..."><?= $v('links') ?></textarea>
            </div>

            <input type="submit" value="Enviar solicitud" class="btn btn-primary">
        </form>
    @endif
</div>
@endsection
