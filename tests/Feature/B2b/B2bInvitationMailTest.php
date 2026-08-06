<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;
use App\Notifications\B2bInvitationNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

/**
 * What the invitation email actually says.
 *
 * Rendered rather than mocked: the point of the redesign is that this mail
 * uses the shared `emails.layout` shell like every other production email, and
 * only a real render can show that the logo, footer and button survived. The
 * plain-text part is rendered too — a mail with no readable text alternative
 * is a deliverability problem, not a cosmetic one.
 */
class B2bInvitationMailTest extends TestCase
{
    use RefreshDatabase;

    /** Invitations go to a bare address, exactly as the service sends them. */
    private function notifiable(): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route('mail', 'privat@example.com');
    }

    private function notification(array $overrides = []): B2bInvitationNotification
    {
        return new B2bInvitationNotification(
            companyName: $overrides['companyName'] ?? 'Alpha GmbH',
            acceptUrl: $overrides['acceptUrl'] ?? 'https://portal.test/invitations/tok3n',
            invitedByName: $overrides['invitedByName'] ?? 'Maria Muster',
            roleLabel: $overrides['roleLabel'] ?? B2bRole::Member->label(),
            expiresInDays: 14,
            invitedEmail: $overrides['invitedEmail'] ?? 'privat@example.com',
            expiresAt: $overrides['expiresAt'] ?? Carbon::parse('2026-09-01 12:00:00'),
            vehicleScope: $overrides['vehicleScope'] ?? B2bVehicleScope::All,
            permissionLabels: $overrides['permissionLabels'] ?? ['Fahrzeuge ansehen', 'Fahrzeuge anlegen'],
        );
    }

    private function renderHtml(array $overrides = []): string
    {
        return (string) $this->notification($overrides)->toMail($this->notifiable())->render();
    }

    public function test_html_mail_carries_every_required_element(): void
    {
        $html = $this->renderHtml();

        $this->assertStringContainsString('Alpha GmbH', $html, 'inviting company name');
        $this->assertStringContainsString('Maria Muster', $html, 'inviter name');
        $this->assertStringContainsString('Mitglied', $html, 'invited role');
        $this->assertStringContainsString('Einladung annehmen', $html, 'CTA label');
        $this->assertStringContainsString('https://portal.test/invitations/tok3n', $html, 'accept URL / plain fallback');
        $this->assertStringContainsString('01.09.2026', $html, 'expiry date');
        $this->assertStringContainsString('Sicherheitshinweis', $html, 'security note');
        $this->assertStringContainsString('privat@example.com', $html, 'invited address the link is bound to');
    }

    public function test_html_mail_reuses_the_shared_leasyback_shell(): void
    {
        $html = $this->renderHtml();

        // Brand chrome from emails.layout, not Laravel's default mail theme.
        $this->assertStringContainsString('#0b4f49', $html, 'brand header colour');
        $this->assertStringContainsString('#0bb995', $html, 'brand button colour');
        $this->assertStringContainsString(config('mail_notifications.branding.company_name'), $html);
        $this->assertStringContainsString(config('mail_notifications.support.email'), $html);
        $this->assertStringContainsString('leasyback-stacked.png', $html, 'shared logo asset');

        // Client-safety basics the shell provides.
        $this->assertStringContainsString('role="presentation"', $html, 'table-based layout');
        $this->assertStringContainsString('max-width:600px', $html, 'fixed-width container');
        $this->assertStringContainsString('@media only screen and (max-width: 620px)', $html, 'responsive rules');
        $this->assertStringNotContainsString('Laravel', $html);
    }

    public function test_permission_lines_are_listed_for_a_member(): void
    {
        $html = $this->renderHtml();

        $this->assertStringContainsString('Ihre Berechtigungen', $html);
        $this->assertStringContainsString('Fahrzeuge anlegen', $html);
    }

    public function test_owner_invitation_omits_the_permission_list(): void
    {
        $html = $this->renderHtml([
            'roleLabel' => B2bRole::Owner->label(),
            'permissionLabels' => [],
        ]);

        $this->assertStringContainsString('Inhaber', $html);
        $this->assertStringNotContainsString('Ihre Berechtigungen', $html);
    }

    public function test_vehicle_scope_is_spelled_out(): void
    {
        $this->assertStringContainsString(
            'Nur selbst angelegte Fahrzeuge',
            $this->renderHtml(['vehicleScope' => B2bVehicleScope::Own])
        );
    }

    public function test_plain_text_part_is_rendered_and_complete(): void
    {
        $mail = $this->notification()->toMail($this->notifiable());

        // ->text() folds both parts into view as an html/text pair.
        $this->assertSame('emails.b2b.invitation', $mail->view['html']);
        $this->assertSame('emails.b2b.invitation-text', $mail->view['text']);

        $text = (string) view($mail->view['text'], $mail->viewData)->render();

        $this->assertStringContainsString('Alpha GmbH', $text);
        $this->assertStringContainsString('Maria Muster', $text);
        $this->assertStringContainsString('Mitglied', $text);
        $this->assertStringContainsString('https://portal.test/invitations/tok3n', $text);
        $this->assertStringContainsString('01.09.2026', $text);
        $this->assertStringContainsString('SICHERHEITSHINWEIS', $text);
        $this->assertStringContainsString('privat@example.com', $text);
        $this->assertStringNotContainsString('<', $text, 'the text part must not contain markup');
    }

    public function test_subject_names_the_company(): void
    {
        $this->assertSame(
            'Einladung zu Alpha GmbH bei '.config('mail.from.name'),
            $this->notification()->toMail($this->notifiable())->subject
        );
    }
}
