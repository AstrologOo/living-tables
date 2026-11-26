<?php

namespace App\Modules\Execution\Exceptions;

use RuntimeException;

/**
 * A run exceeded its time budget; the node fails without retries.
 */
class RunBudgetExceededException extends RuntimeException {}
