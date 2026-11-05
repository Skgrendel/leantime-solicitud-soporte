@extends($layout)

@section('content')
<?php $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); ?>
<x-global::pageheader :icon="'fa fa-life-ring'">
    <h1>Solicitudes de soporte</h1>
</x-global::pageheader>

<div class="maincontent">
    <div class="maincontentinner">
        <p>
            Formulario público:
            <a href="<?= $h($formUrl) ?>" target="_blank"><?= $h($formUrl) ?></a>
            (no requiere iniciar sesión).
        </p>

        <form method="post" action="<?= BASE_URL ?>/SolicitudSoporte/settings">
            @foreach ($config['areas'] as $key => $area)
                <fieldset style="margin-bottom:24px;">
                    <legend>Área: {{ $area['label'] }}</legend>
                    <div class="form-group">
                        <label>Proyecto donde se crean los tickets</label>
                        <select name="areas[{{ $key }}][projectId]" class="form-control">
                            <option value="0">Automático (proyecto llamado "{{ $area['projectName'] }}")</option>
                            @foreach ($projects as $p)
                                <option value="{{ $p['id'] }}" <?= (int) $area['projectId'] === (int) $p['id'] ? 'selected' : '' ?>>{{ $p['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Correos del equipo a notificar (uno por línea o separados por coma)</label>
                        <textarea name="areas[{{ $key }}][notify]" class="form-control" rows="3" placeholder="ti@proderi.com">{{ $area['notify'] }}</textarea>
                    </div>
                </fieldset>
            @endforeach

            <div class="form-group">
                <label>
                    <input type="checkbox" name="confirmRequester" value="1" <?= ! empty($config['confirmRequester']) ? 'checked' : '' ?>>
                    Enviar correo de confirmación a quien hace la solicitud
                </label>
            </div>

            <div class="form-group">
                <label>Usuario que figura como creador de los tickets</label>
                <select name="creatorUserId" class="form-control">
                    <option value="0">Automático (administrador)</option>
                    @foreach ($users as $u)
                        <option value="{{ $u['id'] }}" <?= (int) $config['creatorUserId'] === $u['id'] ? 'selected' : '' ?>>{{ $u['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label>Tipos de solicitud (uno por línea)</label>
                <textarea name="requestTypes" class="form-control" rows="12">{{ $config['requestTypes'] }}</textarea>
            </div>

            <input type="submit" value="Guardar" class="btn btn-primary">
        </form>
    </div>
</div>
@endsection
