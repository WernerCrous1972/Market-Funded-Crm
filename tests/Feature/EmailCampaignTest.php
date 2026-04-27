<?php

declare(strict_types=1);

use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\EmailUnsubscribe;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('EmailTemplate', function () {

    it('applies merge tags to subject and body', function () {
        $template = EmailTemplate::factory()->create([
            'subject'   => 'Hello {{first_name}}!',
            'body_html' => '<p>Dear {{full_name}}, your email is {{email}}.</p></body>',
        ]);

        $person = Person::factory()->create([
            'first_name' => 'Werner',
            'last_name'  => 'Crous',
            'email'      => 'werner@market-funded.com',
        ]);

        ['subject' => $subject, 'html' => $html] = $template->render(
            $person,
            unsubscribeUrl: 'https://example.com/unsub',
            trackingPixelUrl: 'https://example.com/pixel',
        );

        expect($subject)->toBe('Hello Werner!');
        expect($html)->toContain('Dear Werner Crous');
        expect($html)->toContain('werner@market-funded.com');
    });

    it('injects tracking pixel before </body>', function () {
        $template = EmailTemplate::factory()->create([
            'body_html' => '<p>Hello</p></body>',
        ]);

        $person = Person::factory()->create();

        ['html' => $html] = $template->render(
            $person,
            unsubscribeUrl: 'https://example.com/unsub',
            trackingPixelUrl: 'https://example.com/pixel/123',
        );

        expect($html)->toContain('https://example.com/pixel/123');
        expect($html)->toContain('<img src="https://example.com/pixel/123"');
    });

});

describe('EmailUnsubscribe', function () {

    it('detects unsubscribed email', function () {
        EmailUnsubscribe::record('test@example.com', 'unsubscribe_link');
        expect(EmailUnsubscribe::isUnsubscribed('test@example.com'))->toBeTrue();
        expect(EmailUnsubscribe::isUnsubscribed('other@example.com'))->toBeFalse();
    });

    it('is case insensitive', function () {
        EmailUnsubscribe::record('Test@Example.COM');
        expect(EmailUnsubscribe::isUnsubscribed('test@example.com'))->toBeTrue();
    });

    it('does not duplicate on re-record', function () {
        EmailUnsubscribe::record('test@example.com');
        EmailUnsubscribe::record('test@example.com');
        expect(EmailUnsubscribe::where('email', 'test@example.com')->count())->toBe(1);
    });

});

describe('Email tracking routes', function () {

    it('tracking pixel returns a gif', function () {
        $recipient = \App\Models\EmailCampaignRecipient::factory()->create();

        $response = $this->get(route('email.track.open', $recipient->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');
    });

    it('unsubscribe form loads with valid token', function () {
        $recipient = \App\Models\EmailCampaignRecipient::factory()->create();
        $mailer    = new \App\Services\Email\CampaignMailer();
        $token     = $mailer->unsubscribeToken($recipient);

        $response = $this->get(route('email.unsubscribe', [
            'recipient' => $recipient->id,
            'token'     => $token,
        ]));

        $response->assertOk();
        $response->assertSee('Unsubscribe');
    });

    it('unsubscribe form rejects invalid token', function () {
        $recipient = \App\Models\EmailCampaignRecipient::factory()->create();

        $response = $this->get(route('email.unsubscribe', [
            'recipient' => $recipient->id,
            'token'     => 'invalid-token',
        ]));

        $response->assertForbidden();
    });

    it('confirm unsubscribe records the unsubscribe', function () {
        $recipient = \App\Models\EmailCampaignRecipient::factory()->create([
            'email' => 'werner@example.com',
        ]);
        $mailer = new \App\Services\Email\CampaignMailer();
        $token  = $mailer->unsubscribeToken($recipient);

        $this->post(route('email.unsubscribe.confirm', $recipient->id), ['token' => $token]);

        expect(EmailUnsubscribe::isUnsubscribed('werner@example.com'))->toBeTrue();
    });

});

describe('Filament email pages', function () {

    beforeEach(function () {
        $user = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($user);
    });

    it('email templates list loads', function () {
        $this->get('/admin/email-templates')->assertOk();
    });

    it('email campaigns list loads', function () {
        $this->get('/admin/email-campaigns')->assertOk();
    });

    it('create template page loads', function () {
        $this->get('/admin/email-templates/create')->assertOk();
    });

    it('create campaign page loads', function () {
        $this->get('/admin/email-campaigns/create')->assertOk();
    });

});
