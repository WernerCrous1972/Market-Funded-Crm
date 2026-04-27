<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Activity;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use App\Models\EmailUnsubscribe;
use App\Models\Person;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles sending individual campaign emails with tracking and unsubscribe.
 *
 * Used by SendCampaignEmailJob — one call per recipient.
 */
class CampaignMailer
{
    /**
     * Send a single campaign email to one recipient.
     * Returns true on success, false on skip/failure.
     */
    public function send(EmailCampaign $campaign, EmailCampaignRecipient $recipient): bool
    {
        $person = $recipient->person;

        // ── Pre-send checks ───────────────────────────────────────────────────

        if (! $person || ! $recipient->email) {
            $this->markSkipped($recipient, 'No person or email');
            return false;
        }

        if (EmailUnsubscribe::isUnsubscribed($recipient->email)) {
            $this->markSkipped($recipient, 'Unsubscribed');
            return false;
        }

        if (! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            $this->markSkipped($recipient, 'Invalid email address');
            return false;
        }

        // ── Build URLs ────────────────────────────────────────────────────────

        $trackingPixelUrl = route('email.track.open', [
            'recipient' => $recipient->id,
        ]);

        $unsubscribeUrl = route('email.unsubscribe', [
            'recipient' => $recipient->id,
            'token'     => $this->unsubscribeToken($recipient),
        ]);

        // ── Render template ───────────────────────────────────────────────────

        $template = $campaign->template;
        $subject  = $campaign->subject_override ?? $template->subject;

        $subject = $this->applyMergeTags($subject, $person);

        ['subject' => $subject, 'html' => $html] = $template->render(
            $person,
            $unsubscribeUrl,
            $trackingPixelUrl,
        );

        // ── Send via Laravel Mail (Brevo SMTP) ────────────────────────────────

        try {
            Mail::send([], [], function (Message $message) use (
                $campaign, $recipient, $subject, $html, $unsubscribeUrl
            ) {
                $message
                    ->to($recipient->email, $recipient->first_name)
                    ->from($campaign->from_email, $campaign->from_name)
                    ->subject($subject)
                    ->html($html)
                    ->getHeaders()
                    ->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>")
                    ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click')
                    ->addTextHeader('X-Campaign-Id', $campaign->id)
                    ->addTextHeader('X-Recipient-Id', $recipient->id);
            });

            $recipient->update([
                'status'  => 'SENT',
                'sent_at' => now(),
            ]);

            EmailEvent::create([
                'campaign_id'  => $campaign->id,
                'recipient_id' => $recipient->id,
                'person_id'    => $person->id,
                'type'         => 'SENT',
                'occurred_at'  => now(),
            ]);

            Activity::record(
                personId: $person->id,
                type: Activity::TYPE_EMAIL_SENT,
                description: "Email sent: {$subject}",
                metadata: ['campaign_id' => $campaign->id],
            );

            $campaign->increment('sent_count');

            return true;

        } catch (\Throwable $e) {
            Log::error('CampaignMailer: send failed', [
                'campaign_id'  => $campaign->id,
                'recipient_id' => $recipient->id,
                'email'        => $recipient->email,
                'error'        => $e->getMessage(),
            ]);

            $recipient->update([
                'status'        => 'FAILED',
                'failed_at'     => now(),
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    private function applyMergeTags(string $text, Person $person): string
    {
        return str_replace(
            ['{{first_name}}', '{{last_name}}', '{{full_name}}', '{{email}}'],
            [$person->first_name ?? '', $person->last_name ?? '', $person->full_name ?? '', $person->email ?? ''],
            $text
        );
    }

    private function markSkipped(EmailCampaignRecipient $recipient, string $reason): void
    {
        $recipient->update([
            'status'        => 'SKIPPED',
            'error_message' => $reason,
        ]);
    }

    public function unsubscribeToken(EmailCampaignRecipient $recipient): string
    {
        return hash_hmac('sha256', $recipient->id . $recipient->email, config('app.key'));
    }
}
