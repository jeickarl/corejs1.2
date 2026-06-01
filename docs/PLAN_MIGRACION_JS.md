# Plan de migración a JS (Vue + Nest)

## Objetivo

- Migrar el sistema de `legacy/` a una arquitectura separada: frontend (Vue) + backend (Nest) vía API REST.
- Mantener el diseño visual lo más similar posible reutilizando CSS/Bootstrap/Assets del sistema actual.
- Organizar código por módulos (screaming) y por capas dentro del módulo (endpoint/controller/daos/modelo).
- Incluir el módulo `super_admin` (panel maestro) como área separada con permisos/rol dedicado.
- Mantener un solo login y redirigir según rol: `SUPER_ADMIN` → menú maestro, `ADMIN/USER` → menú normal.
- 2FA/MFA: no es necesario por ahora; se deja como fase posterior.

## Entregables iniciales

- Monorepo con `apps/frontend` y `apps/backend`.
- API base con documentación (Swagger) y endpoint de salud.
- Front base con layout (header/sidebar) y carga de assets legacy.
- Esqueleto `super-admin`: rutas, layout y endpoints base.
