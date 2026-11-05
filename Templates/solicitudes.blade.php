@extends($layout)

@section('content')
<?php
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$svc = \Leantime\Plugins\SolicitudSoporte\Services\SolicitudSoporte::class;
$labels = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];
$colors = ['pending' => '#b26a00', 'approved' => '#1a7f37', 'rejected' => '#b42318'];
$tabs = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'all' => 'Todas'];
?>
<x-global::pageheader :icon="'fa fa-life-ring'">
    <h1>Solicitudes de soporte</h1>
</x-global::pageheader>

<div class="maincontent">
    <div class="maincontentinner">
        {!! $tpl->displayNotification() !!}

        <style>
            .ss-tabs { display:flex; flex-wrap:wrap; gap:6px; border-bottom:1px solid #ddd; margin-bottom:20px; }
            .ss-tabs a { padding:10px 16px; border-radius:6px 6px 0 0; text-decoration:none; color:#444; }
            .ss-tabs a.active { background:#fff; border:1px solid #ddd; border-bottom:1px solid #fff; margin-bottom:-1px; font-weight:bold; color:#006d9f; }
            .ss-tabs .ss-right { margin-left:auto; }
        </style>
        <nav class="ss-tabs">
            @foreach ($tabs as $key => $name)
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado={{ $key }}">
                    {{ $name }}@if ($key !== 'all') ({{ $counts[$key] ?? 0 }})@endif
                </a>
            @endforeach
            <a class="ss-right" href="<?= BASE_URL ?>/SolicitudSoporte/settings"><i class="fa fa-cog"></i> Configuración</a>
        </nav>

        @if ($selected)
            <?php
            $s = $selected;
            $area = $areas[$s['area']]['label'] ?? $s['area'];
            $links = array_filter($svc::parseLines((string) $s['links']), [$svc, 'isAllowedLink']);
            ?>
            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:24px;">
                <h3 style="margin-top:0;">
                    Solicitud #{{ $s['id'] }} - {{ $s['subject'] }}
                    <span style="font-size:13px;color:<?= $colors[$s['status']] ?>;">[{{ $labels[$s['status']] ?? $s['status'] }}]</span>
                </h3>
                <table class="table" style="max-width:900px;">
                    <tr><th style="width:200px;">Solicitante</th><td>{{ $s['name'] }} ({{ $s['company'] }})<br>{{ $s['email'] }}@if (trim((string) $s['position']) !== '') - {{ $s['position'] }}@endif</td></tr>
                    <tr><th>Área</th><td>{{ $area }}</td></tr>
                    <tr><th>Tipo de solicitud</th><td>{{ $s['type'] }}</td></tr>
                    <tr><th>Prioridad</th><td>{{ $s['priority'] }}</td></tr>
                    <tr><th>Enviada</th><td><?= $h($svc::formatDate($s['created_at'])) ?></td></tr>
                    <tr><th>Descripción</th><td><?= nl2br($h($s['description'])) ?></td></tr>
                    @if ($links)
                        <tr><th>Archivos (OneDrive)</th><td>
                            @foreach ($links as $l)
                                <a href="{{ $l }}" target="_blank" rel="noopener noreferrer">{{ $l }}</a><br>
                            @endforeach
                        </td></tr>
                    @endif
                    @if ($s['status'] !== 'pending')
                        <tr><th><?= $s['status'] === 'approved' ? 'Comentario del aprobador' : 'Motivo del rechazo' ?></th><td><?= nl2br($h($s['decision_comment'])) ?></td></tr>
                        <tr><th>Decidida</th><td><?= $h($svc::formatDate($s['decided_at'])) ?></td></tr>
                        @if ($s['ticket_id'])
                            <tr><th>Ticket</th><td><a href="<?= BASE_URL ?>/dashboard/home#/tickets/showTicket/{{ $s['ticket_id'] }}">#{{ $s['ticket_id'] }}</a></td></tr>
                        @endif
                    @endif
                </table>

                @if ($s['status'] === 'pending')
                    <form method="post" action="<?= BASE_URL ?>/SolicitudSoporte/solicitudes" style="max-width:900px;">
                        <input type="hidden" name="id" value="{{ $s['id'] }}">
                        <div class="form-group">
                            <label for="comment">Comentario / motivo</label>
                            <small class="help-block">Al <strong>aprobar</strong> es opcional y viaja en el aviso al área. Al <strong>rechazar</strong> es obligatorio y se envía al solicitante.</small>
                            <textarea name="comment" id="comment" class="form-control" rows="4"><?= $h($draftComment) ?></textarea>
                        </div>
                        <button type="submit" name="decision" value="approve" class="btn btn-primary">Aprobar</button>
                        <button type="submit" name="decision" value="reject" class="btn btn-danger">Rechazar</button>
                        <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado={{ $status }}" class="btn btn-default">Cerrar</a>
                    </form>
                @else
                    <a href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado={{ $status }}" class="btn btn-default">Volver al listado</a>
                @endif
            </div>
        @endif

        @if (empty($requests))
            <p>No hay solicitudes en esta vista.</p>
        @else
            <table class="table table-bordered">
                <thead>
                    <tr><th>#</th><th>Fecha</th><th>Solicitante</th><th>Área</th><th>Tipo</th><th>Prioridad</th><th>Asunto</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td>{{ $r['id'] }}</td>
                            <td><?= $h($svc::formatDate($r['created_at'])) ?></td>
                            <td>{{ $r['name'] }}<br><small>{{ $r['company'] }}</small></td>
                            <td>{{ $areas[$r['area']]['label'] ?? $r['area'] }}</td>
                            <td>{{ $r['type'] }}</td>
                            <td>{{ $r['priority'] }}</td>
                            <td>{{ $r['subject'] }}</td>
                            <td style="color:<?= $colors[$r['status']] ?? '#333' ?>;font-weight:bold;">{{ $labels[$r['status']] ?? $r['status'] }}</td>
                            <td><a class="btn btn-default btn-sm" href="<?= BASE_URL ?>/SolicitudSoporte/solicitudes?estado={{ $status }}&id={{ $r['id'] }}">{{ $r['status'] === 'pending' ? 'Revisar' : 'Ver' }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
