<?php

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * The LLM provider gave no usable answer. The message may quote the provider, so it is only for the log.
 */
class LlmUnavailableException extends RuntimeException {}
