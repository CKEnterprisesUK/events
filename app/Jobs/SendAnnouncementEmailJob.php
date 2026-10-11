<?php

namespace App\Jobs;

use App\Mail\PlatformAnnouncementMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers ONE platform announcement email to ONE Company_User, off the DB
 * queue, so a large audience is sent as many small, independently retryable
 * jobs (one flaky address never blocks the rest) drained by the same cron
 * `queue:work` as every other Platform email.
 *
 * The job carries the recipient's id plus the already-rendered, trusted subject
 * and HTML body (the Markdown was rendered to safe HTML once, at send time, by
 * the controller — not per job). The recipient is re-read at handle time so an
 * account that was disabled or deleted between composing and delivery is a safe
 * no-op and is never contacted. The transport (SMTP / Microsoft Graph) is
 * whatever Laravel resolves as the default, exactly like the ticket email.
 */
class SendAnnouncementEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $userId,
        private readonly string $subjectLine,
        private readonly string $bodyHtml,
    ) {}

    public function handle(): void
    {
        $user = User::query()
            ->where('is_super_admin', false)
            ->whereNull('disabled_at')
            ->find($this->userId);

        // The account was disabled or removed after the send was queued, or is
        // a super admin — nothing to deliver.
        if ($user === null) {
            return;
        }

        Mail::to($user->email)->send(new PlatformAnnouncementMail(
            subjectLine: $this->subjectLine,
            bodyHtml: $this->bodyHtml,
            recipientName: $user->name,
        ));
    }
}
