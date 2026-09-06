<?php

namespace Tests\Feature;

use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform-level Trust & Legal Centre: Super_Admin-managed policies exposed
 * at the public `/trust` surface, plus the Terms & Conditions agreement gate on
 * organiser sign-up.
 */
class TrustLegalCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_hub_lists_only_published_documents_with_content(): void
    {
        LegalDocument::create([
            'slug' => 'terms',
            'title' => 'Terms & Conditions',
            'body' => 'These are the terms.',
            'is_published' => true,
            'sort_order' => 1,
        ]);
        LegalDocument::create([
            'slug' => 'privacy',
            'title' => 'Privacy Notice',
            'body' => 'Draft privacy body.',
            'is_published' => false, // draft — must not show
            'sort_order' => 2,
        ]);
        LegalDocument::create([
            'slug' => 'pci',
            'title' => 'PCI DSS Standard',
            'body' => null, // published but empty — must not show
            'is_published' => true,
            'sort_order' => 3,
        ]);

        $response = $this->get('/trust');

        $response->assertOk();
        $response->assertSee('Terms &amp; Conditions', false);
        $response->assertDontSee('Privacy Notice');
        $response->assertDontSee('PCI DSS Standard');
    }

    public function test_published_document_renders_and_drafts_404(): void
    {
        LegalDocument::create([
            'slug' => 'terms',
            'title' => 'Terms & Conditions',
            'body' => "# Heading\n\nBody paragraph.",
            'is_published' => true,
        ]);
        LegalDocument::create([
            'slug' => 'privacy',
            'title' => 'Privacy Notice',
            'body' => 'Secret draft.',
            'is_published' => false,
        ]);

        $this->get('/trust/terms')
            ->assertOk()
            ->assertSee('Body paragraph.', false);

        // Draft and unknown slugs are not exposed.
        $this->get('/trust/privacy')->assertNotFound();
        $this->get('/trust/does-not-exist')->assertNotFound();
    }

    public function test_markdown_html_is_escaped_on_the_public_page(): void
    {
        LegalDocument::create([
            'slug' => 'terms',
            'title' => 'Terms',
            'body' => 'Safe text <script>alert(1)</script>',
            'is_published' => true,
        ]);

        $this->get('/trust/terms')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_super_admin_can_manage_documents_and_defaults_are_seeded(): void
    {
        $admin = User::factory()->superAdmin()->create();

        // The index ensures the well-known defaults exist as drafts.
        $this->actingAs($admin)->get(route('admin.legal.index'))
            ->assertOk()
            ->assertSee('Terms &amp; Conditions', false)
            ->assertSee('PCI DSS Standard', false);

        $this->assertDatabaseHas('legal_documents', [
            'slug' => LegalDocument::SLUG_TERMS,
            'is_published' => false,
        ]);

        $terms = LegalDocument::where('slug', LegalDocument::SLUG_TERMS)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.legal.update', $terms), [
            'title' => 'Terms & Conditions',
            'body' => 'The published terms.',
            'is_published' => '1',
            'sort_order' => '1',
        ])->assertRedirect(route('admin.legal.index'));

        $this->assertDatabaseHas('legal_documents', [
            'slug' => LegalDocument::SLUG_TERMS,
            'body' => 'The published terms.',
            'is_published' => true,
        ]);

        // Now public.
        $this->get('/trust/terms')->assertOk()->assertSee('The published terms.', false);
    }

    public function test_non_super_admin_cannot_manage_documents(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('admin.legal.index'))->assertForbidden();
    }

    public function test_signup_requires_agreeing_to_terms(): void
    {
        $payload = [
            'company_name' => 'Acme Events',
            'slug' => 'acme-events',
            'legal_name' => 'Acme Events Ltd',
            'organisation_type' => \App\Models\Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'organisation_email' => 'hello@acme.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'gb',
            'name' => 'Olivia Owner',
            'email' => 'olivia@acme.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            // agree_terms omitted
        ];

        $this->from('/register')->post('/register', $payload)
            ->assertRedirect('/register')
            ->assertSessionHasErrors('agree_terms');

        $this->assertDatabaseMissing('users', ['email' => 'olivia@acme.test']);

        // With agreement, the account is created and acceptance is stamped.
        $this->post('/register', array_merge($payload, ['agree_terms' => '1']))
            ->assertRedirect('/dashboard');

        $user = User::where('email', 'olivia@acme.test')->firstOrFail();
        $this->assertNotNull($user->agreed_to_terms_at);
    }
}
