<?php

namespace App\Services\Mail\Graph;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * A Symfony mail transport that delivers through the Microsoft Graph `sendMail`
 * API instead of SMTP. Registered with Laravel's mail manager via
 * `Mail::extend('graph', ...)`, so it plugs in as the `graph` mailer with no
 * change to any Mailable or to the {@see \App\Services\Mail\SmtpTicketMailer}
 * (which sends through whichever mailer Laravel resolves as the default).
 *
 * The transport's only job is translation: take the fully-rendered Symfony
 * {@see Email} (recipients, subject, HTML/text bodies, attachments) and shape it
 * into the Graph `message` resource, then delegate the HTTP/auth concern to the
 * {@see GraphMailClient}. All outgoing mail — ticket emails, support
 * notifications and the Super_Admin diagnostic test email — flows through here
 * when Graph is the active transport.
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly GraphMailClient $client,
        private readonly GraphMailConfig $config,
    ) {
        parent::__construct();
    }

    /**
     * A stable DSN-ish identifier for logs/tooling.
     */
    public function __toString(): string
    {
        return 'graph://microsoft-graph';
    }

    /**
     * Convert the queued/rendered message to an {@see Email}, build the Graph
     * payload and send it.
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $this->client->sendMail($this->toGraphMessage($email));
    }

    /**
     * Map a Symfony {@see Email} onto the Graph `message` resource. Covers the
     * fields the platform's mailables actually use: subject, HTML body (with a
     * plain-text fallback), to/cc/bcc, reply-to, and file attachments (the
     * ticket PDF).
     *
     * @return array<string, mixed>
     */
    private function toGraphMessage(Email $email): array
    {
        [$contentType, $body] = $this->body($email);

        $message = [
            'subject' => (string) $email->getSubject(),
            'body' => [
                'contentType' => $contentType,
                'content' => $body,
            ],
            'toRecipients' => $this->recipients($email->getTo()),
        ];

        if ($cc = $this->recipients($email->getCc())) {
            $message['ccRecipients'] = $cc;
        }

        if ($bcc = $this->recipients($email->getBcc())) {
            $message['bccRecipients'] = $bcc;
        }

        if ($replyTo = $this->recipients($email->getReplyTo())) {
            $message['replyTo'] = $replyTo;
        }

        // Honour an explicit From when the mailable set one; otherwise Graph
        // sends as the configured mailbox, which is the desired default.
        if ($from = $email->getFrom()) {
            $message['from'] = $this->recipients($from)[0];
        }

        if ($attachments = $this->attachments($email)) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    /**
     * Prefer the HTML body; fall back to text. Returns the Graph contentType
     * ("HTML" or "Text") alongside the content string.
     *
     * @return array{0: string, 1: string}
     */
    private function body(Email $email): array
    {
        $html = $email->getHtmlBody();

        if ($html !== null) {
            return ['HTML', $this->asString($html)];
        }

        return ['Text', $this->asString($email->getTextBody())];
    }

    /**
     * Map a list of Symfony {@see Address}es to Graph `emailAddress` recipients.
     *
     * @param  array<int, Address>  $addresses
     * @return array<int, array{emailAddress: array{address: string, name?: string}}>
     */
    private function recipients(array $addresses): array
    {
        $recipients = [];

        foreach ($addresses as $address) {
            $entry = ['address' => $address->getAddress()];

            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            $recipients[] = ['emailAddress' => $entry];
        }

        return $recipients;
    }

    /**
     * Map Symfony attachments (e.g. the ticket PDF) to Graph
     * `#microsoft.graph.fileAttachment` entries with base64 content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename')
                ?? $headers->getHeaderParameter('Content-Type', 'name')
                ?? 'attachment';

            $attachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $filename,
                'contentType' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'contentBytes' => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    /**
     * Coerce a body part (string or Stringable) to a string, tolerating null.
     */
    private function asString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
