<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'created_by_user_id',
        'name',
        'subject',
        'body_html',
        'body_text',
        'merge_tags',
        'is_active',
    ];

    protected $casts = [
        'merge_tags' => 'array',
        'is_active'  => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class, 'email_template_id');
    }

    /**
     * Apply merge tags to subject and body for a given person.
     */
    public function render(Person $person, string $unsubscribeUrl, string $trackingPixelUrl): array
    {
        $tags = [
            '{{first_name}}'      => $person->first_name ?? '',
            '{{last_name}}'       => $person->last_name ?? '',
            '{{full_name}}'       => $person->full_name ?? '',
            '{{email}}'           => $person->email ?? '',
            '{{unsubscribe_url}}' => $unsubscribeUrl,
            '{{tracking_pixel}}' => "<img src=\"{$trackingPixelUrl}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\">",
        ];

        $subject = str_replace(array_keys($tags), array_values($tags), $this->subject);
        $html    = str_replace(array_keys($tags), array_values($tags), $this->body_html);

        // Inject tracking pixel just before </body> if not already in template
        if (! str_contains($html, $trackingPixelUrl)) {
            $html = str_replace('</body>', $tags['{{tracking_pixel}}'] . '</body>', $html);
            if (! str_contains($html, '</body>')) {
                $html .= $tags['{{tracking_pixel}}'];
            }
        }

        return ['subject' => $subject, 'html' => $html];
    }
}
