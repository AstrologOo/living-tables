<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

/** A declared parent belongs to another user. */
class ParentNotOwnedException extends RuntimeException {}
