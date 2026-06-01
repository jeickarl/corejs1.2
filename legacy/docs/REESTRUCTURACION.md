# Reestructuración (base Core)

Este proyecto fue tomado como base de `C:\xampp\htdocs\core` y se está preparando para que sea más fácil de mantener y evolucionar.

## Objetivo

- Estandarizar el “bootstrap” (carga de sesión, seguridad, entorno y DB) para que las páginas no repitan `require_once`.
- Reducir riesgo de filtrar secretos: `.env.local` no debe vivir en el repositorio.

## Puntos clave

- `config/init_public.php`: para páginas públicas (landing, login) que necesitan sesión + CSRF.
- `config/init_app.php`: para páginas internas que requieren DB (incluye `session.php` y `functions.php` vía `database.php`).
- `config/.env`: valores por defecto sin secretos.
- `config/.env.local.example`: ejemplo para tu máquina/ambiente.

## Regla práctica

- Si una página necesita `requireAuth()` o usa `$pdo`, usa `require_once '../config/init_app.php'`.
- Si es una página pública que solo necesita CSRF, usa `require_once '../config/init_public.php'`.

