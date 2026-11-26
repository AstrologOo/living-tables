<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

/** The broker's queues were declared by an earlier version of the app. */
class TopologyOutdatedException extends RuntimeException {}
