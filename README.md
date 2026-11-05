# Solicitud de Soporte (plugin de Leantime)

Plugin de [Leantime](https://leantime.io) con un **formulario público de solicitudes** (sin login) y una **bandeja de aprobación**. Una solicitud solo se convierte en ticket cuando un administrador la aprueba.

- **Versión de Leantime probada:** 3.7.3 (PHP 8.2)
- **Áreas:** Ticket de Soporte y Análisis de Datos
- **Empresas del solicitante:** Proderi y Acoficum

## Flujo

```
Formulario público ──> Solicitud PENDIENTE ──┬─> Aprobar  ──> Se crea el ticket + correo al área
   (/SolicitudSoporte/index)   (tabla propia)  │
                                              └─> Rechazar ──> Correo al solicitante con el motivo
```

1. La persona llena el formulario: empresa, nombre, correo, cargo/área, área destino, tipo de solicitud, prioridad, motivo, descripción y enlaces de OneDrive.
2. La solicitud queda **pendiente** en una tabla del plugin. **No se crea ticket todavía.**
3. Correos al enviar:
   - **Aprobadores:** aviso de solicitud pendiente, con botón a la bandeja.
   - **Solicitante:** acuse de recibo (se puede desactivar).
4. Un admin/owner entra a **Empresa > Solicitudes de soporte** (`/SolicitudSoporte/solicitudes`), revisa el detalle y:
   - **Aprueba** (comentario opcional): se crea el ticket en el proyecto del área y se envía el correo *"Nueva solicitud aprobada para atención"* a los correos de TI de esa área. El comentario del aprobador va en el correo y en el ticket.
   - **Rechaza** (motivo **obligatorio**): se envía al solicitante el correo *"Su solicitud no fue aprobada"* con el motivo. No se crea ticket.
5. Una solicitud solo se puede decidir una vez; si dos personas intentan decidirla a la vez, la segunda recibe un aviso.

### Cómo queda el ticket al aprobar

| Dato | Dónde queda |
|---|---|
| Motivo | Título |
| Solicitante, tipo, referencia, descripción, enlaces y comentario del aprobador | Descripción (todo escapado) |
| Prioridad | Urgente=1, Alta=2, Media=3, Baja=4 |
| Empresa y tipo de solicitud | Etiquetas |
| Estado | Nuevo (3) |

El ticket figura como creado por el usuario configurado o, por defecto, **quien aprueba**.

## Correos

Todos usan el mismo diseño (barra lateral oscura con logo, tarjeta con tabla de detalles y botón).

| Momento | Destinatario | Asunto |
|---|---|---|
| Se envía la solicitud | Aprobadores | Solicitud #N pendiente de aprobación |
| Se envía la solicitud | Solicitante (opcional) | Hemos recibido su solicitud #N |
| Se aprueba | Correos de TI del área | Nueva solicitud aprobada #N |
| Se rechaza | Solicitante | Su solicitud #N no fue aprobada |

Los aprobadores avisados son los correos configurados o, si no hay ninguno, **todos los administradores y owners de Leantime**. Un fallo de correo nunca impide aprobar o rechazar; queda registrado en `storage/logs`.

Logo: se usa la URL configurada en el plugin; si está vacía, el logo de Leantime (solo si no es SVG, porque muchos clientes de correo no los muestran). Para que se vea en el correo debe ser accesible desde Internet.

## Adjuntos

No se suben archivos. La persona pega **enlaces de OneDrive/SharePoint** (uno por línea, máximo 10). Solo se aceptan enlaces `https` de `1drv.ms`, `onedrive.live.com`, `onedrive.com` y `*.sharepoint.com` (constante `ALLOWED_LINK_HOSTS` en `Services/SolicitudSoporte.php`).

## Anti-spam

- Campo oculto (honeypot) que atrapa bots.
- Máximo 5 envíos por IP cada 10 minutos.

## Instalación

El plugin debe quedar en `app/Plugins/SolicitudSoporte/` (nombre de carpeta exacto).

```bash
# 1. Copiar la carpeta del plugin a app/Plugins/SolicitudSoporte
# 2. Instalar y activar
php bin/leantime plugin:install proderi/solicitud-soporte
php bin/leantime plugin:enable proderi/solicitud-soporte
```

También desde **Administración de la empresa > Plugins**. Copiar la carpeta no activa nada.

**Base de datos:** el plugin crea su propia tabla `zp_solicitudsoporte_requests` al instalarse (y bajo demanda si falta). No modifica tablas de Leantime. Desinstalar el plugin **no** borra la tabla ni las solicitudes.

### Requisitos previos

- Proyectos de destino. Por defecto busca **"Ticket de soporte"** y **"Analisis de Datos"** (sin importar mayúsculas), o se eligen en la configuración. Sin proyecto, aprobar falla con un mensaje claro y la solicitud sigue pendiente.
- Servidor SMTP configurado en Leantime (`LEAN_EMAIL_*` en `config/.env`) para los correos. Sin SMTP el flujo funciona, solo que no salen correos.

## Configuración

**Empresa > Config. solicitudes** (`/SolicitudSoporte/settings`), solo owner/admin:

- Proyecto de destino y correos de TI de cada área.
- Correos de los aprobadores (vacío = todos los admin/owner).
- Acuse de recibo al solicitante (sí/no).
- URL del logo para los correos.
- Usuario creador de los tickets (vacío = quien aprueba).
- Lista de tipos de solicitud (uno por línea).

Se guarda en la tabla de ajustes de Leantime bajo `plugins.solicitudsoporte.config` (JSON).

## Estructura

```
register.php                     Menús, idiomas y ruta pública
composer.json                    Metadatos del plugin
Controllers/Index.php            Formulario público
Controllers/Solicitudes.php      Bandeja: listar, aprobar, rechazar (admin)
Controllers/Settings.php         Configuración (admin)
Services/SolicitudSoporte.php    Tabla, flujo, ticket y correos
Templates/                       index, solicitudes, settings
Language/                        es-ES y en-US
```

## Desarrollo local con Docker

El Docker local del proyecto Leantime monta esta carpeta en `app/Plugins/SolicitudSoporte` e incluye Mailpit para ver los correos en http://localhost:8025. Es solo local y no se sube al repo de Leantime.

## Solución de problemas

- **El formulario pide login:** verifica que el plugin esté activado (la ruta pública se declara en `register.php`).
- **`Invalid plugin name` al instalar:** usa el nombre de composer (`proderi/solicitud-soporte`). Si no aparece en `plugin:list`, revisa que `composer.json` tenga `homepage` y `authors`.
- **Cambié la configuración directo en la base de datos y no se refleja:** Leantime cachea los ajustes; borra `storage/framework/cache/*`. Guardar desde la pantalla de configuración no lo necesita.
- **No llegan correos:** revisa el SMTP de Leantime y `storage/logs`.
