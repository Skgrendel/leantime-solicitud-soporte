# Solicitud de Soporte (plugin de Leantime)

Plugin de [Leantime](https://leantime.io) que agrega un **formulario público de solicitudes** (sin login). Cada envío crea un ticket en el proyecto del área elegida y puede notificar por correo al equipo de TI y al solicitante.

- **Versión de Leantime probada:** 3.7.3 (PHP 8.2)
- **Áreas:** Ticket de Soporte y Análisis de Datos
- **Empresas del solicitante:** Proderi y Acoficum

## Qué hace

1. La persona abre `/SolicitudSoporte/index` y llena el formulario:
   empresa, nombre, correo, cargo/área, área destino, tipo de solicitud, prioridad, motivo, descripción y enlaces de OneDrive.
2. El plugin crea un ticket en el proyecto asociado al área.
3. Envía (opcionalmente) un correo de confirmación al solicitante y un aviso a los correos de TI configurados para esa área.

### Cómo se guarda cada solicitud

| Dato del formulario | Dónde queda en el ticket |
|---|---|
| Motivo | Título |
| Datos del solicitante, tipo, prioridad textual, descripción y enlaces | Descripción (todo escapado, sin HTML del usuario) |
| Prioridad | Prioridad de Leantime: Urgente=1, Alta=2, Media=3, Baja=4 |
| Empresa y tipo de solicitud | Etiquetas (tags) |
| Estado | Nuevo (3) |

El ticket figura como creado por un usuario de Leantime (por defecto el administrador; se puede cambiar en la configuración).

### Adjuntos

No se suben archivos. La persona pega **enlaces de OneDrive/SharePoint** (uno por línea, máximo 10) y quedan en la descripción del ticket. Solo se aceptan enlaces `https` de estos dominios: `1drv.ms`, `onedrive.live.com`, `onedrive.com` y `*.sharepoint.com`. La lista está en `ALLOWED_LINK_HOSTS` de `Services/SolicitudSoporte.php`.

### Anti-spam

- Campo oculto (honeypot) que atrapa bots.
- Máximo 5 envíos por IP cada 10 minutos.

## Instalación

El plugin debe quedar en `app/Plugins/SolicitudSoporte/` dentro de Leantime (el nombre de la carpeta debe ser exactamente ese).

```bash
# 1. Copiar la carpeta del plugin a app/Plugins/SolicitudSoporte
# 2. Instalar y activar
php bin/leantime plugin:install proderi/solicitud-soporte
php bin/leantime plugin:enable proderi/solicitud-soporte
```

También se puede activar desde Leantime en **Administración de la empresa > Plugins**.

Copiar la carpeta no activa nada: el plugin solo funciona cuando se instala y habilita.

### Requisitos previos

- Los proyectos de destino. Por defecto el plugin busca proyectos llamados **"Ticket de soporte"** y **"Analisis de Datos"** (sin importar mayúsculas), o puedes elegir otros en la configuración.
- Un servidor SMTP configurado en Leantime si quieres correos (`LEAN_EMAIL_*` en `config/.env`). Sin SMTP el ticket se crea igual; solo no se envían correos.

## Configuración

Menú **Empresa > Solicitudes de soporte** (`/SolicitudSoporte/settings`), solo para owner y admin:

- Proyecto de destino de cada área (o automático por nombre).
- Correos de TI a notificar por área (uno por línea o separados por coma).
- Activar/desactivar la confirmación al solicitante.
- Usuario que figura como creador de los tickets.
- Lista de tipos de solicitud (uno por línea).

Todo se guarda en la tabla de ajustes de Leantime bajo la clave `plugins.solicitudsoporte.config` (JSON).

## Estructura

```
register.php                     Menú, idiomas y ruta pública
composer.json                    Metadatos del plugin
Controllers/Index.php            Formulario público (GET/POST)
Controllers/Settings.php         Configuración (solo admin)
Services/SolicitudSoporte.php    Validación, creación del ticket y correos
Templates/index.blade.php        Formulario
Templates/settings.blade.php     Pantalla de configuración
Language/                        es-ES y en-US
```

## Desarrollo local con Docker

El Docker del proyecto Leantime monta esta carpeta como volumen en `app/Plugins/SolicitudSoporte` y trae Mailpit para ver los correos en http://localhost:8025. Esa configuración es solo local y no se sube al repo de Leantime.

## Notas y solución de problemas

- **El formulario pide login:** la ruta pública se declara en `register.php`. Verifica que el plugin esté activado.
- **`Invalid plugin name` al instalar:** usa el nombre de composer (`proderi/solicitud-soporte`), no el de la carpeta. Si no aparece en `plugin:list`, revisa que `composer.json` tenga `homepage` y `authors`.
- **Cambié la configuración directo en la base de datos y no se refleja:** Leantime cachea los ajustes; borra `storage/framework/cache/*`. Guardar desde la pantalla de configuración no lo necesita.
- **Los correos no salen:** revisa el SMTP de Leantime. Un fallo de correo nunca impide crear el ticket; queda registrado en `storage/logs`.
