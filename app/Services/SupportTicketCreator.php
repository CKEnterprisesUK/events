<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\SupportRequestReceivedMail;
use App\Models\Company;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Raises a support ticket on behalf of a Company_User and alerts the CK
 * Enterprises support inbox. Shared by the "Contact support" form and by flows
 * that need operator help automatically (e.g. cancelling an event that has
 * bookings, where bulk refunds are arranged by the support team).
 *
 * The ticket is persisted first. The email is a best-effort notification: a
 * mail-transport failure is logged and never fails the caller, because the
 * ticket is already visible in the super-admin support queue.
 */
class SupportTicketCreator
{
    /**
     * Persist a ticket for the Company and email the support inbox.
     */
    public function raise(
        Company $company,
        ?User $user,
        string $category,
        string $subject,
        string $message,
        bool $accessConsent = false,
    ): SupportRequest {
        $ticket = SupportRequest::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'user_id' => $user?->getKey(),
            'category' => $category,
            'subject' => $subject,
            'message' => $message,
            'status' => SupportRequest::STATUS_OPEN,
            'access_consent' => $accessConsent,
            'access_consent_at' => $accessConsent ? now() : null,
        ]);

        $this->notifySupportInbox($ticket, $company->name, $user);

        return $ticket;
    }

    private function notifySupportInbox(SupportRequest $ticket, string $companyName, ?User $user): void
    {
        $inbox = (string) config('mail.support.address');

        if ($inbox === '') {
            return;
        }

        try {
            Mail::to($inbox)->send(new SupportRequestReceivedMail(
                supportRequest: $ticket,
                companyName: $companyName,
                raiserName: $user?->name,
                raiserEmail: $user?->email,
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to send support-request notification email.', [
                'support_request_id' => $ticket->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
