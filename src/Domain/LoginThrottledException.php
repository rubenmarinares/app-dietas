<?php
namespace App\Domain;

/** Login bloqueado temporalmente por exceso de intentos fallidos (mensaje genérico, apto para mostrar). */
class LoginThrottledException extends \RuntimeException
{
}
