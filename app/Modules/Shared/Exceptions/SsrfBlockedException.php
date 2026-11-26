<?php

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * A URL or redirect target points at a forbidden address.
 */
class SsrfBlockedException extends RuntimeException {}
