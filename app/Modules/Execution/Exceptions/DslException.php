<?php

namespace App\Modules\Execution\Exceptions;

use InvalidArgumentException;

/**
 * The user's pipeline asks for something impossible. The message is shown to the user, so never use this for
 * internal failures.
 */
class DslException extends InvalidArgumentException {}
