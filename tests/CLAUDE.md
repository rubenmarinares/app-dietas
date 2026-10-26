# tests

| Suite | BBDD | Contenido |
|---|---|---|
| `Unit/` | No | Arquitectura (capas, sin SQL en Domain), rutas/permisos, Csrf, Session, TokenGenerator, filtros SQL de ModelBase, filtros Twig, PermissionDomain, hashing de User, TOTP, deploy |
| `Integration/` | Sí (real, sin mocks de PDO) | Login (incluida la migración md5→password_hash), 2FA, permisos desde typesuser, SettingsDomain, dbschema ↔ BBDD |

- Config de BBDD de test: `tests/.env.local` (copiar de `.env.local.example`; no se versiona). Debe ser una BBDD **dedicada**: se borran datos.
- Preparar: `php migrations/apply.php --test`.
- Sin BBDD disponible, los tests de integración se marcan *skipped* (no fallan). `tests/bootstrap.php` toma un `GET_LOCK` para que dos ejecuciones no se pisen.
- `Tests\TestCase` (integración): `$this->db`, `$this->app()` (objeto con `->db`, `->session`, `->setUser`), `fixtureAdminUser($password='x', $override=[])` (crea vía `User::insert`, así el hash es el real y con plazo 2FA vigente). Usa emails `@test.local`: se purgan en cada `setUp`.
- Comandos: `vendor/bin/phpunit tests/Unit`, `vendor/bin/phpunit`, `vendor/bin/phpunit --filter nombre tests/Integration/Domain/AuthLoginTest.php`.
- Al añadir un GET que muta estado, añádelo a `RoutesPermTest::MUTATINGGETPATTERNS`.
