# src/Domain — lógica de negocio

Clases `XxxDomain` con métodos `static`. Sin estado. Orquestan Models; no escriben SQL.

## Reglas

- Cadena: `Screen → Domain → Domain\Models`. Domain sí usa Models; Screens no.
- **Sin SQL en Domain** (`$db->run/prepare/query/exec/update/insert/delete`): `ArchitectureTest` falla. Si falta una consulta, crea un método en el Model (`User::setdeadline2fa`, `TypeUser::clearpermissions`).
- Primer parámetro: la mayoría recibe `$app` y usa `$app->db` (CRUD de pantallas: `UserDomain`, `SettingsDomain`, `SettingDomain`). Los métodos de bajo nivel reciben `$db` (`AuthDomain::*2FA*`, `PermissionDomain::permissionsforuser`). **No pases un PDO a un método que espera `$app`**: `AppParamCallersTest` lo detecta.
- Errores de validación: lanzar `\InvalidArgumentException` (la Screen lo convierte en mensaje flash). No `echo`/`die`.
- Antes de llamar a un método, compruébalo: `grep -n "function nombre" src/Domain/*.php`.

## Clases incluidas

| Clase | Qué hace |
|---|---|
| `AuthDomain` | Login en fases: `verifycredentials()` (sin abrir sesión) → `completelogin()` (sesión + `users_logs`). 2FA: `generateTOTPSecret`, `validateTOTP`, `provisioninguri`, `generateQRCode` (SVG de texto, ver aprendizajes), `send2FAEmail`, `validateEmailCode` (5 min, 3 intentos → bloqueo 15 min), `setup2FA`, `reset2FA`, `isInGracePeriod`, `gracedeadline`, `pendinguser`. |
| `PermissionDomain` | `LEVELS` none<read<write; `modulefromclass`, `requiredlevelformethod`, `levelfor`, `can`, `parsepermissionsinput` (valida el JSON del formulario), `permissionsforuser` (por petición), `logdenied`, `ALWAYSALLOWED`, `MODULEALIASES`. |
| `UserDomain` | `list`, `get`, `save` (alta ⇒ plazo de gracia 2FA), `changepassword`, `delete`, combos. |
| `SettingsDomain` | Pantalla Configuración: `index` (users + typesuser), `user`, `user_save` (`newpassword` solo si viene rellena), `user_delete`, `typeuser`, `typeuser_save` (JSON → array; vacío ⇒ NULL = acceso total), `typeuser_delete`. |
| `SettingDomain` | CRUD de la tabla clave/valor `settings` (`props` JSON). Base para la configuración del CMS. |

## Formato de permisos (`typesuser.permissions`)

```json
{"*": "read", "pages": "write", "settings": "none"}
```
Clave = módulo (nombre de Screen sin `Screen`, en minúsculas) o `*`. Valor `none|read|write`. `NULL` = acceso total (retrocompatibilidad). JSON no-objeto ⇒ `[]` ⇒ sin acceso (fail closed).
