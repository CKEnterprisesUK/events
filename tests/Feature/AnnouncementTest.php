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
 * Feature: super-admin platform announcements.
 *
 * Covers the additions to the compose/send flow:
 *   - personalisation placeholders in the body ({{name}}, {{company}},
 *     {{platform}}, {{dashboard_url}}) resolve per recipient and never inject
 *     markup;
 *   - the sending super admin always receives their own copy;
 *   - the live preview renders the composed body into the real email template.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['email' => 'ops@platform.test']);
    }

    public function test_sending_queues_a_job_per_recipient_and_emails_a_copy_to_the_operator(): void
    {
        Queue::fake();
        Mail::fake();

        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);
        User::factory()->owner()->create(['company_id' => Company::factory()->create()->id]);

        $operator = $this->superAdmin();

        $this->actingAs($operator)
            ->post(route('admin.announcements.send'), [
                'subject' => 'Set up data retention',
                'body' => 'Hi {{name}}, please visit {{dashboard_url}}.',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertRedirect(route('admin.announcements.create'));

        // One delivery job per matching owner (super admin excluded from the audience).
        Queue::assertPushed(SendAnnouncementEmailJob::class, 2);

        // The operator always gets their own copy, flagged in the subject.
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

        $renderer = app(AnnouncementRenderer::class);
        $html = $renderer->renderForUser(
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

        // The raw <script> tag is escaped, never emitted as live markup.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_preview_renders_the_body_into_the_email_template(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->postJson(route('admin.announcements.preview'), [
                'subject' => 'Preview subject',
                'body' => '**Bold** and {{name}}.',
            ]);

        $response->assertOk();
        $html = $response->json('html');

        // Markdown rendered, placeholder sampled, and the real template chrome present.
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('Alex Taylor', $html);
        $this->assertStringContainsString('Preview subject', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function test_preview_is_denied_to_non_super_admins(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)
            ->postJson(route('admin.announcements.preview'), ['body' => 'x'])
            ->assertForbidden();
    }
}
