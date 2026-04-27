<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Converts MTR country names (full strings like "South Africa") to
 * ISO-3166-1 alpha-2 codes and flag emoji.
 *
 * MTR returns full country names, not ISO codes, so we maintain this map.
 * Add entries as new countries appear in your client base.
 */
class CountryHelper
{
    /**
     * Full country name → ISO-2 code mapping.
     * Keys are lowercase for case-insensitive lookup.
     */
    private const NAME_TO_ISO2 = [
        'afghanistan'                          => 'AF',
        'albania'                              => 'AL',
        'algeria'                              => 'DZ',
        'angola'                               => 'AO',
        'argentina'                            => 'AR',
        'australia'                            => 'AU',
        'austria'                              => 'AT',
        'bahrain'                              => 'BH',
        'bangladesh'                           => 'BD',
        'botswana'                             => 'BW',
        'brazil'                               => 'BR',
        'bulgaria'                             => 'BG',
        'cameroon'                             => 'CM',
        'canada'                               => 'CA',
        'chile'                                => 'CL',
        'china'                                => 'CN',
        'colombia'                             => 'CO',
        'congo'                                => 'CG',
        'democratic republic of the congo'     => 'CD',
        'croatia'                              => 'HR',
        'czech republic'                       => 'CZ',
        'czechia'                              => 'CZ',
        'denmark'                              => 'DK',
        'egypt'                                => 'EG',
        'ethiopia'                             => 'ET',
        'finland'                              => 'FI',
        'france'                               => 'FR',
        'germany'                              => 'DE',
        'ghana'                                => 'GH',
        'greece'                               => 'GR',
        'hong kong'                            => 'HK',
        'hungary'                              => 'HU',
        'india'                                => 'IN',
        'indonesia'                            => 'ID',
        'iran'                                 => 'IR',
        'iraq'                                 => 'IQ',
        'ireland'                              => 'IE',
        'israel'                               => 'IL',
        'italy'                                => 'IT',
        'ivory coast'                          => 'CI',
        'côte d\'ivoire'                       => 'CI',
        'jamaica'                              => 'JM',
        'japan'                                => 'JP',
        'jordan'                               => 'JO',
        'kenya'                                => 'KE',
        'kuwait'                               => 'KW',
        'lebanon'                              => 'LB',
        'lesotho'                              => 'LS',
        'libya'                                => 'LY',
        'madagascar'                           => 'MG',
        'malawi'                               => 'MW',
        'malaysia'                             => 'MY',
        'mali'                                 => 'ML',
        'mauritius'                            => 'MU',
        'mexico'                               => 'MX',
        'morocco'                              => 'MA',
        'mozambique'                           => 'MZ',
        'namibia'                              => 'NA',
        'netherlands'                          => 'NL',
        'new zealand'                          => 'NZ',
        'nigeria'                              => 'NG',
        'norway'                               => 'NO',
        'oman'                                 => 'OM',
        'pakistan'                             => 'PK',
        'peru'                                 => 'PE',
        'philippines'                          => 'PH',
        'poland'                               => 'PL',
        'portugal'                             => 'PT',
        'qatar'                                => 'QA',
        'romania'                              => 'RO',
        'russia'                               => 'RU',
        'russian federation'                   => 'RU',
        'rwanda'                               => 'RW',
        'saudi arabia'                         => 'SA',
        'senegal'                              => 'SN',
        'serbia'                               => 'RS',
        'sierra leone'                         => 'SL',
        'singapore'                            => 'SG',
        'somalia'                              => 'SO',
        'south africa'                         => 'ZA',
        'south korea'                          => 'KR',
        'republic of korea'                    => 'KR',
        'spain'                                => 'ES',
        'sri lanka'                            => 'LK',
        'sudan'                                => 'SD',
        'swaziland'                            => 'SZ',
        'eswatini'                             => 'SZ',
        'sweden'                               => 'SE',
        'switzerland'                          => 'CH',
        'tanzania'                             => 'TZ',
        'thailand'                             => 'TH',
        'togo'                                 => 'TG',
        'trinidad and tobago'                  => 'TT',
        'tunisia'                              => 'TN',
        'turkey'                               => 'TR',
        'türkiye'                              => 'TR',
        'uganda'                               => 'UG',
        'ukraine'                              => 'UA',
        'united arab emirates'                 => 'AE',
        'united kingdom'                       => 'GB',
        'united states'                        => 'US',
        'united states of america'             => 'US',
        'usa'                                  => 'US',
        'uk'                                   => 'GB',
        'uae'                                  => 'AE',
        'uruguay'                              => 'UY',
        'venezuela'                            => 'VE',
        'vietnam'                              => 'VN',
        'viet nam'                             => 'VN',
        'zambia'                               => 'ZM',
        'zimbabwe'                             => 'ZW',
    ];

    /**
     * Convert a country name (or ISO-2 code) to ISO-2.
     * Returns null if not found.
     */
    public static function toIso2(?string $country): ?string
    {
        if (! $country) {
            return null;
        }

        $lower = strtolower(trim($country));

        // Already a 2-letter code?
        if (strlen($country) === 2 && ctype_alpha($country)) {
            return strtoupper($country);
        }

        return self::NAME_TO_ISO2[$lower] ?? null;
    }

    /**
     * Convert a country name to a flag emoji.
     * Flag emoji are built from two Regional Indicator Symbol Letters.
     */
    public static function toFlag(?string $country): string
    {
        $iso2 = self::toIso2($country);

        if (! $iso2) {
            return '🌍';
        }

        // Convert each letter to its Regional Indicator Symbol (U+1F1E6 + offset)
        $offset = 0x1F1E6 - ord('A');
        $chars  = array_map(
            fn (string $char) => mb_chr(ord($char) + $offset),
            str_split(strtoupper($iso2))
        );

        return implode('', $chars);
    }

    /**
     * Return flag + country name for display, e.g. "🇿🇦 South Africa"
     */
    public static function display(?string $country): string
    {
        if (! $country) {
            return '—';
        }

        $flag = self::toFlag($country);

        return "{$flag} {$country}";
    }
}
