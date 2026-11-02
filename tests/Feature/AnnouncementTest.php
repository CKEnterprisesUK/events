<?php

namespace Tests\Feature;

use App\Jobs\SendAnnouncementEmailJob;
use App\Mail\PlatformAnnouncementMail;
use App\Models\Company;
use App\Models\User;
use App\Services\AnnouncementRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: super-admin platform announcements — the compose → review → send
 * flow and its additions.
 *
 * Covers:
 *   - the mandatory review step renders the real email and reports the audience;
 *   - a send only proceeds with the review acknowledgement (never straight from
 *     compose);
 *   - personalisation placeholders resolve per recipient and never inject markup;
 *   - the sending super admin always receives their own copy.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['email' => 'ops@platform.test']);
    }

    public function test_review_step_renders_the_email_and_reports_the_audience(): void
    {
        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.announcements.review'), [
                'subject' => 'Set up data retention',
                'body' => '**Bold** and {{name}}.',
                'roles' => [User::ROLE_OWNER],
            ]);

        $response->assertOk();
        $response->assertViewIs('admin.announcements.review');
        $response->assertViewHas('recipientCount', 1);
        // The rendered email carries the markdown, a sample placeholder value
        // and the real template chrome.
        $response->assertViewHas('previewHtml', function (string $html) {
            return str_contains($html, '<strong>Bold</strong>')
                && str_contains($html, 'Alex Taylor')
                && str_contains($html, '<!DOCTYPE html>');
        });
    }

    public function test_send_requires_the_review_acknowledgement(): void
    {
        Queue::fake();
        Mail::fake();

        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);

        // A send without `reviewed` is bounced back to compose and sends nothing.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.announcements.send'), [
                'subject' => 'Hi',
                'body' => 'Body {{name}}',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertRedirect(route('admin.announcements.create'));

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_reviewed_send_queues_a_job_per_recipient_and_copies_the_operator(): void
    {
        Queue::fake();
        Mail::fake();

        User::factory()->owner()->create(['company_id' => Company::factory()->create()->id]);
        User::factory()->owner()->create(['company_id' => Company::factory()->create()->id]);

        $operator = $this->superAdmin();

        $this->actingAs($operator)
            ->post(route('admin.announcements.send'), [
                'subject' => 'Set up data retention',
                'body' => 'Hi {{name}}, visit {{dashboard_url}}.',
                'roles' => [User::ROLE_OWNER],
                'reviewed' => '1',
            ])
            ->assertRedirect(route('admin.announcements.create'));

        Queue::assertPushed(SendAnnouncementEmailJob::class, 2);

        Mail::assertSent(PlatformAnnouncementMail::class, function (PlatformAnnouncementMail $mail) use ($operator) {
            return $mail->hasTo($operator->email) && str_contains($mail->subjectLine, '[Copy]');
        });
    }

    public function test_placeholders_resolve_to_the_recipients_own_values(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Tickets']);
        $user = User::factory()->owner()->create([
            'company_id' => $company->id,
            'name' => 'Jordan Blake',
        ]);

        $html = app(AnnouncementRenderer::class)->renderForUser(
            'Hello {{name}} from {{company}} on {{platform}}.',
            $user->load('company')
        );

        $this->assertStringContainsString('Jordan Blake', $html);
        $this->assertStringContainsString('Acme Tickets', $html);
        $this->assertStringContainsString((string) config('app.name'), $html);
    }

    public function test_placeholder_values_cannot_inject_markup(): void
    {
        $company = Company::factory()->create(['name' => '<script>alert(1)</script>']);
        $user = User::factory()->owner()->create(['company_id' => $company->id]);

        $html = app(AnnouncementRenderer::class)
            ->renderForUser('Company: {{company}}', $user->load('company'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_review_is_denied_to_non_super_admins(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)
            ->post(route('admin.announcements.review'), [
                'subject' => 'x',
                'body' => 'y',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertForbidden();
    }
}
