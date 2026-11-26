<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

/**
 * This run no longer owns its node (claim or Redis lock lost) and must stop without writing.
 */
class LockLostException extends RuntimeException {}
