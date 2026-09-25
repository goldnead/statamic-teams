<?php

namespace Goldnead\Teams\Integrations\EmailTemplates;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Goldnead\EmailTemplates\Support\EmailTemplateData;

/**
 * Lets `php please email-templates:import` pick up this addon's mails.
 *
 * Only tagged when email-templates is installed; this file names its
 * interface and must not be loaded otherwise.
 */
class TeamsTemplateSource implements EmailTemplateSource
{
    public function __construct(protected MailTemplates $templates) {}

    public function label(): string
    {
        return 'Teams';
    }

    public function all(): array
    {
        /** @var array<int, EmailTemplateData> */
        return $this->templates->templates();
    }
}
