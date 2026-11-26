<?php

namespace App\Modules\Shared\Exceptions;

/**
 * The host didn't resolve. Refused like a blocked address, but worth retrying and reported differently.
 */
class HostUnresolvedException extends SsrfBlockedException {}
