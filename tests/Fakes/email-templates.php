<?php

/*
 * Declarations of goldnead/statamic-email-templates that this addon names,
 * for static analysis only (see phpstan.neon). Same signatures as the real
 * package at 2.7.x.
 */

namespace Goldnead\EmailTemplates\Support {
    if (! class_exists(EmailTemplateData::class)) {
        class EmailTemplateData
        {
            public function __construct(
                public string $slug,
                public string $title,
                public string $subject = '',
                public string $preview = '',
                public string $body = '',
                public ?string $plainText = null,
                public ?string $description = null,
                public ?string $layout = null,
                public string $source = 'entry',
            ) {}

            /** @param  array<string, mixed>  $data */
            public static function fromArray(array $data): self
            {
                return new self((string) ($data['slug'] ?? ''), (string) ($data['title'] ?? ''));
            }
        }
    }
}

namespace Goldnead\EmailTemplates\Contracts {
    use Goldnead\EmailTemplates\Support\EmailTemplateData;

    if (! interface_exists(EmailTemplateSource::class)) {
        interface EmailTemplateSource
        {
            public function label(): string;

            /** @return array<int, EmailTemplateData> */
            public function all(): array;
        }
    }
}
