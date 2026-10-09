<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bRolePreset;
use App\Enums\B2bVehicleScope;
use App\Models\B2B;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Notifications\B2bInvitationNotification;
use App\Support\MailLogo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Logos that used to show as broken images: the company logo on the
 * invitation page (its stored URL froze APP_URL at upload), and the LeasyBack
 * logo in emails (a remote URL on APP_URL the mail client could not load).
 */
class B2bLogoTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private function companyWithLogo(): B2B
    {
        $company = $this->makeCompany('Garah');
        $company->forceFill([
            'logo_path' => 'b2b-logos/42-logo.png',
            'logo_url' => 'http://localhost/storage/b2b-logos/42-logo.png',
        ])->save();

        return $company;
    }

    public function test_the_company_logo_is_served_from_its_file_not_the_url_frozen_at_upload(): void
    {
        $company = $this->companyWithLogo();
        $owner = $this->makeOwner($company);
        $token = Str::random(64);

        B2bInvitation::create([
            'invitation_id' => (string) Str::uuid(),
            'b2b_id' => $company->b2b_id,
            'email' => 'neu@firma.test',
            'role' => 'member',
            'permissions' => B2bRolePreset::StandardUser->permissions()->toArray(),
            'vehicle_scope' => 'all',
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->get(route('b2b.invitations.show', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation.company_logo_url', '/storage/b2b-logos/42-logo.png'));

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.b2b.active.logo_url', '/storage/b2b-logos/42-logo.png')->etc());

        $this->assertSame('/storage/b2b-logos/42-logo.png', $company->fresh()->logo_url);
    }

    public function test_a_logo_given_only_as_a_url_is_kept(): void
    {
        $company = $this->makeCompany();
        $company->forceFill(['logo_path' => null, 'logo_url' => 'https://cdn.example.com/logo.png'])->save();

        $this->assertSame('https://cdn.example.com/logo.png', $company->fresh()->logo_url);
    }

    public function test_the_admin_customer_list_shows_the_same_logo_address(): void
    {
        $company = $this->companyWithLogo();
        $this->makeOwner($company);

        $page = $this->actingAs($this->makeAdmin())->get(route('admin.customers.index', ['type' => 'b2b']))->viewData('page');

        $this->assertStringContainsString('/storage/b2b-logos/42-logo.png', json_encode($page['props'], JSON_UNESCAPED_SLASHES));
        $this->assertStringNotContainsString('http://localhost/storage/b2b-logos', json_encode($page['props'], JSON_UNESCAPED_SLASHES));
    }

    /** The LeasyBack logo travels inside the email, so it shows whatever APP_URL says. */
    public function test_a_sent_invitation_email_carries_the_logo_embedded(): void
    {
        config(['mail.default' => 'array', 'app.url' => 'http://localhost', 'mail_notifications.branding.logo_url' => null]);

        Notification::route('mail', 'neu@firma.test')->notifyNow(new B2bInvitationNotification(
            companyName: 'Garah',
            acceptUrl: 'https://portal.leasyback.de/invitations/abc',
            invitedByName: 'hf4',
            roleLabel: 'Standardnutzer',
            expiresInDays: 7,
            invitedEmail: 'neu@firma.test',
            expiresAt: Carbon::now()->addDays(7),
            vehicleScope: B2bVehicleScope::All,
        ));

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $sent->getHtmlBody();

        $this->assertMatchesRegularExpression('/<img class="logo"[^>]*src="cid:[^"]+"/', $html);
        $this->assertStringNotContainsString('http://localhost/leasyback-stacked.png', $html);
        $this->assertCount(1, array_filter($sent->getAttachments(), fn ($part) => $part->getDisposition() === 'inline'));
    }

    public function test_a_configured_logo_url_still_wins(): void
    {
        config(['mail_notifications.branding.logo_url' => 'https://cdn.leasyback.com/logo.png']);

        $this->assertSame('https://cdn.leasyback.com/logo.png', MailLogo::src());
    }
}
