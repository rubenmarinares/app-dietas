<?php
namespace App\Screens;
use App\Core;
use App\Core\Csrf;
use App\Domain\AuthDomain;

/**
 * Login en dos fases (credenciales → código 2FA). Pantalla pública: está en
 * App::PUBLICSCREENS, así que no pasa por el gate de permisos.
 */
Class LoginScreen extends Core\ScreenBase
{
    /** Segundos de validez de la fase 2 tras validar credenciales. */
    private const PENDINGTTL = 300;

    public function index()
    {
        if ($this->app->session->get("logado")) {
            header("Location: " . APP_PATH . "index");
            die;
        }

        $step = 1;
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
                $error = 'La sesión del formulario ha caducado. Vuelve a intentarlo.';
                $this->app->session->delete('2fa_pending');
                $this->render(1, $error);
                return;
            }
        }

        // Fase 1: usuario + contraseña
        if (isset($_POST['user']) && !isset($_POST['2fa_code'])) {
            $username = trim($_POST["user"] ?? '');
            $password = $_POST["password"] ?? '';

            $user = AuthDomain::verifycredentials($this->app, $username, $password);

            if ($user) {
                $userid = (int) $user['iduser'];

                if (AuthDomain::hasConfigured2FA($this->app->db, $userid)) {
                    // 2FA configurado: NO se abre sesión todavía; se pide el código.
                    $this->app->session->set('2fa_pending', [
                        'userid'    => $userid,
                        'timestamp' => time(),
                    ]);
                    $pending = AuthDomain::pendinguser($this->app->db, $userid);
                    if (($pending['twofa']['primary_method'] ?? '') === 'email') {
                        // Antes nunca se enviaba el código en el login: el método email no funcionaba.
                        AuthDomain::send2FAEmail($this->app->db, $userid);
                    }
                    $step = 2;
                } elseif (AuthDomain::isInGracePeriod($this->app->db, $userid)) {
                    AuthDomain::completelogin($this->app, $user);
                    $this->app->addmessage(['type' => 'warning', 'text' => 'Por favor, configura la autenticación de dos factores desde "Mi perfil".']);
                    header("Location: " . APP_PATH . "index");
                    die;
                } else {
                    $error = 'Debes configurar autenticación de dos factores para acceder. Contacta con un administrador.';
                }
            } else {
                $error = "Usuario o contraseña incorrectos";
            }
        }

        // Fase 2: código 2FA
        if (isset($_POST['2fa_code'])) {
            $pending = $this->app->session->get('2fa_pending');

            if (!$pending || time() - $pending['timestamp'] > self::PENDINGTTL) {
                $error = 'Sesión expirada. Intenta de nuevo.';
                $this->app->session->delete('2fa_pending');
            } else {
                $userid = (int) $pending['userid'];
                $code = trim($_POST['2fa_code'] ?? '');
                $data = AuthDomain::pendinguser($this->app->db, $userid);

                if (!$data['user'] || !$data['twofa']) {
                    $error = 'Configuración de 2FA no encontrada.';
                    $this->app->session->delete('2fa_pending');
                } else {
                    try {
                        if (AuthDomain::validate2FA($this->app->db, $userid, $code, $data['twofa']['primary_method'])) {
                            $this->app->session->delete('2fa_pending');
                            AuthDomain::completelogin($this->app, $data['user']);
                            header("Location: " . APP_PATH . "index");
                            die;
                        }
                        $error = 'Código inválido o expirado. Intenta de nuevo.';
                        $step = 2;
                    } catch (\Exception $e) {
                        // Bloqueo por intentos u otros errores
                        $error = $e->getMessage();
                        $step = 2;
                    }
                }
            }
        }

        $this->render($step, $error);
    }

    private function render(int $step, string $error): void
    {
        $this->rendertemplate('login.html', [
            'step'  => $step,
            'error' => $error,
        ]);
    }

    public function logout(){
      AuthDomain::logout($this->app);
      header("Location: ".APP_PATH."login");die;
    }
 }
