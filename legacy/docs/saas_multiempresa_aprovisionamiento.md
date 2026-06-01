# SaaS Multiempresa Sin Subdominios (Dominio Unico)

## Objetivo
Implementar un modelo SaaS multiempresa en `mycore.com.co` bajo rutas estandar (`/login`, `/dashboard`) donde:

- Existe una base central `core_master` para autenticar y administrar empresas.
- Cada empresa usa su propia base de datos operativa.
- El contexto de empresa se resuelve en login, sin subdominios ni slugs en URL.
- El alta por licencia crea automaticamente la BD de la empresa y la deja enlazada.

## Alcance
- Login centralizado por `email + password`.
- Resolucion de `empresa_id` en `core_master`.
- Conexion dinamica a BD de empresa por request.
- Provisionamiento automatico cuando se registra una empresa por licencia.
- Seguridad defensiva para evitar cruces de datos, fuga de credenciales y errores sensibles.

## Arquitectura Propuesta

### 1) Base central: `core_master`
Tablas minimas:

- `empresas`
  - `id` (PK)
  - `nombre`
  - `estado` (`active|suspended|deleted`)
  - `db_host`
  - `db_port`
  - `db_name`
  - `db_user`
  - `db_password_enc` (cifrada)
  - `db_password_iv`
  - `db_password_tag`
  - `schema_version`
  - `created_at`, `updated_at`

- `usuarios_master`
  - `id` (PK)
  - `empresa_id` (FK -> empresas.id)
  - `email` (UNIQUE global)
  - `password_hash`
  - `rol`
  - `activo` (1/0)
  - `ultimo_login_at`
  - `created_at`, `updated_at`

- `licencias`
  - `id`
  - `codigo` (UNIQUE)
  - `plan`
  - `estado` (`disponible|usada|revocada`)
  - `empresa_id` (nullable hasta activacion)
  - `used_at`

### 2) Capa de servicios
- `TenantManager`
  - Valida usuario y empresa en `core_master`.
  - Resuelve empresa activa para el usuario.
  - Restaura contexto por request.

- `DatabaseManager`
  - `getMasterConnection()` para `core_master`.
  - `getTenantConnection(empresa_id)` para BD dinamica de empresa.
  - Cachea conexion en memoria por request.
  - Nunca expone password en excepciones.

- `ProvisioningService`
  - Crea base de datos de empresa.
  - Crea usuario SQL con permisos minimos para esa BD.
  - Ejecuta esquema base (migraciones iniciales).
  - Crea usuario admin inicial en la BD de empresa.
  - Registra metadatos en `core_master.empresas`.

## Flujo Login (Operacion Normal)
1. Usuario entra a `/login` y envia `email/password`.
2. App consulta `core_master.usuarios_master` + `empresas`.
3. Valida:
   - `usuario.activo = 1`
   - `empresa.estado = active`
   - `password_verify(password, password_hash)`
4. Guarda sesion:
   - `master_user_id`
   - `empresa_id`
   - `empresa_estado`
   - `last_activity`
5. En cada request, `DatabaseManager` restablece la conexion de esa empresa.
6. Todas las consultas operativas usan la BD de empresa (no una BD global fija).

## Flujo Registro Por Licencia (Autoaprovisionamiento)
1. Cliente ingresa `codigo_licencia` + datos de empresa + admin.
2. Sistema valida licencia en `core_master.licencias`.
3. Inicia transaccion logica de provisionamiento:
   - Inserta empresa en `core_master.empresas` en estado `provisioning`.
   - Genera nombre de BD segura (ej: `core_tenant_000123`).
   - Genera usuario SQL y password aleatoria robusta.
   - Crea BD y usuario SQL con permisos solo sobre su BD.
   - Ejecuta esquema base (migraciones seed inicial).
   - Inserta `usuarios_master` (admin) asociado a `empresa_id`.
   - Marca licencia como `usada` y la enlaza con `empresa_id`.
   - Cambia empresa a estado `active`.
4. Si falla algun paso:
   - Rollback logico y limpieza (drop de BD/usuario si aplica).
   - Registro de auditoria del error sin exponer secretos.

## Seguridad (Modo Defensa)
- Password de usuarios con `password_hash` / `password_verify`.
- Password de BD cifrada en `core_master` (AES-256-GCM recomendado).
- Clave maestra en variable de entorno (`MASTER_DB_KEY`), nunca hardcodeada.
- Errores genericos al cliente; detalle solo en logs seguros.
- Validar estado activo de usuario y empresa en login y por request.
- Session hardening:
  - `httponly`, `secure`, `samesite=lax/strict`.
  - `session_regenerate_id()` tras login.
- Bloqueo de acceso cruzado:
  - Cada request usa solo la conexion de su `empresa_id`.
  - No reutilizar credenciales de otra empresa.

## Estrategia de Migracion Desde Modelo Actual
Fase 1:
- Implementar `core_master`, `TenantManager`, `DatabaseManager` y login central.
- Mantener operacion actual mientras se habilita modo por feature flag.

Fase 2:
- Activar conexion por empresa para modulos nuevos/criticos.
- Ejecutar pruebas de aislamiento y rendimiento.

Fase 3:
- Migrar todos los modulos a BD por empresa.
- Eliminar dependencia de `tenant_id` en tablas operativas (refactor gradual y controlado).

## Criterios de Aceptacion
- Un usuario autenticado solo opera en la BD de su empresa.
- Registro por licencia crea empresa funcional de extremo a extremo.
- No existen credenciales en texto plano en respuestas ni logs.
- Si una empresa esta suspendida, no puede iniciar sesion.
- En reinicio de request, la sesion restablece correctamente la conexion tenant.

## Respuesta a Tu Pregunta
Si, se puede realizar exactamente asi: al registrar una nueva empresa por licencia, el sistema puede crear automaticamente su base de datos, crear su usuario SQL, cargar estructura inicial, relacionarla en `core_master` y dejarla lista para operar sin intervencion manual.

## Siguiente Paso Recomendado
Implementar primero el esqueleto tecnico:
- `config/database_manager.php`
- `config/tenant_manager.php`
- `config/provisioning_service.php`
- Refactor de `login/process.php` para autenticacion en `core_master`.

## Activacion y Variables de Entorno

Para activar el modo multiempresa por base de datos:

- `SAAS_DB_MODE=per_database`

### XAMPP / Modo simple (recomendado en desarrollo)

Puedes crear un archivo local:

- `config/.env.local`

El sistema lo carga automaticamente en cada request (sin depender de Apache `SetEnv`).

Plantilla:

- `config/.env.local.example`

Conexion a `core_master`:

- `MASTER_DB_HOST`
- `MASTER_DB_PORT`
- `MASTER_DB_NAME` (default: `core_master`)
- `MASTER_DB_USER`
- `MASTER_DB_PASS`

Cifrado de credenciales de BD (obligatorio):

- `MASTER_DB_KEY` (recomendado: 32 bytes en base64)

Aprovisionamiento (crear base de datos y usuario SQL del tenant):

- `PROVISION_DB_ADMIN_USER`
- `PROVISION_DB_ADMIN_PASS`
- `TENANT_DB_HOST` (default: `MASTER_DB_HOST`)
- `TENANT_DB_PORT` (default: `MASTER_DB_PORT`)
- `TENANT_DB_PREFIX` (default: `core_tenant_`)
- `TENANT_DB_USER_PREFIX` (default: `core_u_`)
- `TENANT_DB_USER_HOST` (default: `localhost`)

## Migracion de Datos (core_db -> BDs por empresa)

Se agregó un script CLI seguro en:

- `saas/migrate_single_db_to_per_database.php`

Uso recomendado:

```bash
C:\xampp\php\php.exe saas\migrate_single_db_to_per_database.php
```

Eso ejecuta `dry-run` (simulacion, no escribe cambios).

Para aplicar migracion real:

```bash
C:\xampp\php\php.exe saas\migrate_single_db_to_per_database.php --apply
```

Bootstrap automatico de empresas destino (cuando aun no existen en `core_master`):

```bash
C:\xampp\php\php.exe saas\migrate_single_db_to_per_database.php --apply --auto-bootstrap-empresas --provision-user=root --provision-pass=
```

Opciones utiles:

- `--tenant=1,2,3` migra solo esos tenant_id legacy.
- `--auto-bootstrap-empresas` crea empresa+BD destino si no existe.
- `--source-db=core_db` define base origen.
- `--source-host`, `--source-port`, `--source-user`, `--source-pass` para ajustar origen.

Importante de seguridad:

- `MASTER_DB_KEY` debe ser estable y persistente entre ejecuciones.
- No cambies `MASTER_DB_KEY` luego de cifrar credenciales en `core_master.empresas`.

## Validacion Post-Migracion

Script CLI:

- `saas/validate_per_database.php`

Ejemplo:

```bash
C:\xampp\php\php.exe saas\validate_per_database.php
```

Requiere `MASTER_DB_KEY` configurada.

Variables de entorno opcionales para origen:

- `LEGACY_DB_HOST`
- `LEGACY_DB_PORT`
- `LEGACY_DB_NAME`
- `LEGACY_DB_USER`
- `LEGACY_DB_PASS`
