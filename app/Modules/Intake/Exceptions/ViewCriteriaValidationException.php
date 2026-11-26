<?php

namespace App\Modules\Intake\Exceptions;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use RuntimeException;

class ViewCriteriaValidationException extends RuntimeException
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
     * @param  array<int, string>  $availableNames
     */
    public static function unknownColumn(string $name, array $availableNames): self
    {
        $available = $availableNames === [] ? '(none)' : implode(', ', $availableNames);

        return new self([
            "Unknown column \"{$name}\". Available columns: {$available}.",
        ]);
    }
}
