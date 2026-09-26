<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de una fila de importación; el mensaje se muestra tal cual al usuario.
 */
class ImportRowException extends RuntimeException {}
