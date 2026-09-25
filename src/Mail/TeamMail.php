<?php

namespace Goldnead\Teams\Mail;

use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * One mail of this addon, already rendered by {@see MailTemplates}.
 *
 * `$mail` is the key under `teams.mail` (`invitation`, `member_joined` ...),
 * kept for tests and for anyone listening to MessageSent.
 */
class TeamMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $mail,
        public string $renderedSubject,
        public string $renderedHtml,
    ) {}

    public function build(): self
    {
        return $this->subject($this->renderedSubject)->html($this->renderedHtml);
    }
}
