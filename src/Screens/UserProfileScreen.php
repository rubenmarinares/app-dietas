<?php declare(strict_types=1);

namespace App\Screens;

use App\Core\ScreenBase;
use App\Core\Csrf;
use App\Domain\AuthDomain;

/**
 * Asistente de configuración de 2FA del usuario conectado.
 * Módulo "userprofile": en PermissionDomain::ALWAYSALLOWED.
 */
class UserProfileScreen extends ScreenBase
{
    /** Clave de sesión con el secreto TOTP pendiente de confirmar (nunca viaja en el formulario). */
    private const PENDINGTOTPKEY = 'pendingtotpsecret';

    public function setup2fa(): void
    {
        $email   = (string) ($this->app->user['email'] ?? '');
        $userid  = (int) $this->app->user['iduser'];
        // Sustituir un 2FA ya configurado exige la contraseña actual (una sesión robada no basta).
        $reconfigure = (bool) AuthDomain::hasConfigured2FA($this->app->db, $userid);

        // GET: paso 1 (elegir método)
        if ($this->app->request->getRealMethod() === 'GET') {
            $this->rendertemplate('profile/setup_2fa_step1.html', ['reconfigure' => $reconfigure]);
            return;
        }

        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $this->rendertemplate('profile/setup_2fa_step1.html', [
                'error' => 'El formulario ha caducado. Vuelve a empezar.',
                'reconfigure' => $reconfigure,
            ]);
            return;
        }

        $step    = $_POST['step'] ?? 1;
        $primary = $_POST['primary_method'] ?? null;

        if ($step == 1) {
            if (!in_array($primary, ['totp', 'email'], true)) {
                $this->rendertemplate('profile/setup_2fa_step1.html', [
                    'error' => 'Selecciona un método válido',
                    'reconfigure' => $reconfigure,
                ]);
                return;
            }
            if ($reconfigure && !AuthDomain::passwordok($this->app->db, $userid, (string) ($_POST['currentpassword'] ?? ''))) {
                $this->rendertemplate('profile/setup_2fa_step1.html', [
                    'error' => 'La contraseña actual no es correcta.',
                    'reconfigure' => $reconfigure,
                ]);
                return;
            }

            if ($primary === 'totp') {
                $secret = AuthDomain::generateTOTPSecret();
                $this->app->session->set(self::PENDINGTOTPKEY, $secret);
                $this->rendertemplate('profile/setup_2fa_totp.html', [
                    'secret'  => $secret,
                    'qr_code' => AuthDomain::generateQRCode($secret, $email),
                    'otpauth' => AuthDomain::provisioninguri($secret, $email),
                ]);
            } else {
                AuthDomain::send2FAEmail($this->app->db, $userid);
                $this->rendertemplate('profile/setup_2fa_email.html', []);
            }
            return;
        }

        // Paso 2: validar el método elegido
        $testCode = (string) ($_POST['test_code'] ?? '');

        $secret = null;
        if ($primary === 'totp') {
            $secret = (string) ($this->app->session->get(self::PENDINGTOTPKEY) ?? '');
            if ($secret === '') {
                $this->rendertemplate('profile/setup_2fa_step1.html', [
                    'error' => 'La configuración ha caducado. Vuelve a empezar.',
                ]);
                return;
            }
            if (!AuthDomain::validateTOTP($secret, $testCode)) {
                $this->rendertemplate('profile/setup_2fa_totp.html', [
                    'error'   => 'Código inválido. Intenta de nuevo.',
                    'secret'  => $secret,
                    'qr_code' => AuthDomain::generateQRCode($secret, $email),
                    'otpauth' => AuthDomain::provisioninguri($secret, $email),
                ]);
                return;
            }
        } else {
            $primary = 'email';
            try {
                $valid = AuthDomain::validateEmailCode($this->app->db, $userid, $testCode);
            } catch (\Exception $e) {
                $valid = false;
            }
            if (!$valid) {
                $this->rendertemplate('profile/setup_2fa_email.html', [
                    'error' => 'Código inválido.',
                ]);
                return;
            }
        }

        // Método principal validado: el de respaldo es el contrario.
        $backup = $primary === 'totp' ? 'email' : 'totp';

        AuthDomain::setup2FA(
            $this->app->db,
            $userid,
            $primary,
            $backup,
            $primary === 'totp' ? $secret : null,
            $email
        );
        $this->app->session->delete(self::PENDINGTOTPKEY);

        $this->rendertemplate('profile/setup_2fa_success.html', [
            'primary' => $primary,
        ]);
    }
}
