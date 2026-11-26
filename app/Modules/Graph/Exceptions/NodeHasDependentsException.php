<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

/** A node other nodes still depend on can't be deleted. */
class NodeHasDependentsException extends RuntimeException {}
