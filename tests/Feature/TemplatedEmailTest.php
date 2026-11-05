<?php

namespace Tests\Feature;

use App\Jobs\SendAnnouncementEmailJob;
use App\Mail\PlatformAnnouncementMail;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Services\TemplatedEmailLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: super-admin manual, templated CLIENT emails.
 *
 * These reuse the announcement delivery pipeline but start from a prebuilt
 * template the operator picks (pre-event scanning guidance, or a per-event
 * sales report built from the live figures). Covers:
 *   - picking a template pre-fills the compose form with its subject + body;
 *   - the sales report body is built from the chosen event (its name appears);
 *   - the mandatory review step renders the real email and reports the audience;
 *   - a send only proceeds with the review acknowledgement;
 *   - a reviewed send fans out one job per recipient and copies the operator;
 *   - the surface is denied to non-super-admins.
 */
class TemplatedEmailTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['email' => 'ops@platform.test']);
    }

    public function test_choosing_the_pre_event_template_prefills_the_compose_form(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.templated-emails.create', [
                'template' => TemplatedEmailLibrary::PRE_EVENT_INFO,
            ]));

        $response->assertOk();
        $response->assertViewIs('admin.templated-emails.create');
        $response->assertViewHas('selectedTemplate', TemplatedEmailLibrary::PRE_EVENT_INFO);
        $response->assertViewHas('defaultBody', fn (?string $body) => $body !== null && str_contains($body, 'Scanner'));
        $response->assertViewHas('defaultSubject', fn (?string $subject) => $subject !== null && $subject !== '');
    }

    public function test_sales_report_template_builds_the_body_from_the_chosen_event(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Tickets']);
        $event = Event::factory()->published()->create([
            'company_id' => $company->id,
            'name' => 'Autumn Bonfire & Fireworks Night',
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.templated-emails.create', [
                'template' => TemplatedEmailLibrary::SALES_REPORT,
                'event_id' => $event->id,
            ]));

        $response->assertOk();
        // The report body names the event and carries the headline figures.
        $response->assertViewHas('defaultBody', function (?string $body) {
            return $body !== null
                && str_contains($body, 'Autumn Bonfire & Fireworks Night')
                && str_contains($body, 'Tickets sold');
        });
        // The audience defaults to the event's own company.
        $response->assertViewHas('defaultCompanyId', $company->id);
    }

    public function test_review_step_renders_the_email_and_reports_the_audience(): void
    {
        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.templated-emails.review'), [
                'template' => TemplatedEmailLibrary::PRE_EVENT_INFO,
                'subject' => 'Getting ready to scan',
                'body' => 'Hi {{name}}, invite your **Scanner** staff.',
                'roles' => [User::ROLE_OWNER],
            ]);

        $response->assertOk();
        $response->assertViewIs('admin.templated-emails.review');
        $response->assertViewHas('recipientCount', 1);
        $response->assertViewHas('previewHtml', function (string $html) {
            return str_contains($html, '<strong>Scanner</strong>')
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

        $this->actingAs($this->superAdmin())
            ->post(route('admin.templated-emails.send'), [
                'template' => TemplatedEmailLibrary::PRE_EVENT_INFO,
                'subject' => 'Hi',
                'body' => 'Body {{name}}',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertRedirect(route('admin.templated-emails.create'));

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
            ->post(route('admin.templated-emails.send'), [
                'template' => TemplatedEmailLibrary::PRE_EVENT_INFO,
                'subject' => 'Getting ready to scan',
                'body' => 'Hi {{name}}, visit {{dashboard_url}}.',
                'roles' => [User::ROLE_OWNER],
                'reviewed' => '1',
            ])
            ->assertRedirect(route('admin.templated-emails.create'));

        Queue::assertPushed(SendAnnouncementEmailJob::class, 2);

        Mail::assertSent(PlatformAnnouncementMail::class, function (PlatformAnnouncementMail $mail) use ($operator) {
            return $mail->hasTo($operator->email) && str_contains($mail->subjectLine, '[Copy]');
        });
    }

    public function test_an_unknown_template_is_rejected(): void
    {
        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.templated-emails.review'), [
                'template' => 'not_a_real_template',
                'subject' => 'x',
                'body' => 'y',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertSessionHasErrors('template');
    }

    public function test_the_surface_is_denied_to_non_super_admins(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)
            ->get(route('admin.templated-emails.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.templated-emails.review'), [
                'template' => TemplatedEmailLibrary::PRE_EVENT_INFO,
                'subject' => 'x',
                'body' => 'y',
                'roles' => [User::ROLE_OWNER],
            ])
            ->assertForbidden();
    }
}
