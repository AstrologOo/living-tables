<?php

namespace App\Modules\Intake\Exceptions;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use RuntimeException;

class DslValidationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors  one message per schema violation, safe to
     *                                      show to the user or feed back into a retry prompt
     */
    private function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }

    public static function fromError(ValidationError $error): self
    {
        return new self((new ErrorFormatter)->formatFlat($error));
    }

    /**
     * @param  array<int, string>  $messages
     */
    public static function fromMessages(array $messages): self
    {
        return new self($messages);
    }

    /**
     * @param  array<int, string>  $availableNames
     */
    public static function unknownColumn(string $name, array $availableNames): self
    {
        $available = $availableNames === [] ? '(none)' : implode(', ', $availableNames);

        return new self([
            "Unknown column \"{$name}\". Available columns: {$available}.",
        ]);
    }

    /**
     * @param  array<int, string>  $availableNames
     */
    public static function unknownFunction(string $name, array $availableNames): self
    {
        $available = $availableNames === [] ? '(none)' : implode(', ', $availableNames);

        return new self([
            "Unknown function \"{$name}\" in expr. Available functions: {$available}.",
        ]);
    }
}
