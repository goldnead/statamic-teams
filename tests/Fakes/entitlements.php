<?php

/*
 * Stand-in for goldnead/statamic-entitlements: the facade methods this addon
 * calls (`allows`, `activeProductSlugsFor`) and the SubjectReference value
 * object, with grants held in memory. Same signatures as the real package
 * at a22ae18.
 */

namespace Goldnead\Entitlements\Support {
    if (! class_exists(SubjectReference::class)) {
        final class SubjectReference
        {
            public function __construct(public string $type, public string $id) {}

            public function key(): string
            {
                return $this->type.':'.$this->id;
            }
        }
    }
}

namespace Goldnead\Entitlements\Facades {
    use Goldnead\Entitlements\Support\SubjectReference;

    if (! class_exists(Entitlements::class)) {
        class Entitlements
        {
            /** @var array<string, list<string>> */
            public static array $grants = [];

            public static function grant(SubjectReference $subject, string $slug): void
            {
                static::$grants[$subject->key()][] = $slug;
            }

            public static function allows(mixed $subject, string $slug): bool
            {
                return in_array($slug, static::activeProductSlugsFor($subject), true);
            }

            /** @return list<string> */
            public static function activeProductSlugsFor(mixed $subject): array
            {
                $key = $subject instanceof SubjectReference ? $subject->key() : (string) $subject;

                return static::$grants[$key] ?? [];
            }
        }
    }
}
