<?php

namespace App\Domain\Messaging;

/**
 * Turn a template into a message.
 *
 * Substitution and nothing else: `{event}` becomes the event's name, and a `{whatever}` nobody
 * declared is left alone rather than blanked, so a typo looks like a typo instead of like a
 * missing fact. There is no expression language here and there will not be one — a template is
 * written by a customer, and a template language is a way to run their code on our server.
 */
class MessageRenderer
{
    public static function render(string $template, array $variables): string
    {
        $known = [];

        foreach ($variables as $name => $value) {
            $known['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $known);
    }

    /** Which declared placeholders a body actually uses — shown to the author, not enforced. */
    public static function used(string $template, array $placeholders): array
    {
        return array_values(array_filter(
            $placeholders,
            fn (string $name) => str_contains($template, '{'.$name.'}')
        ));
    }
}
