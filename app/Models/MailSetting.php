<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Mail\MailConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How this installation sends mail, when somebody has said so from the interface.
 *
 * The single row of `mail_settings`, or nothing at all - in which case the `MAIL_*` environment
 * variables stand, which is how every installation starts and how many will stay. See
 * {@see MailConfiguration} for when this is read and what it does to the live
 * configuration, and the migration for why there is no organisation column.
 *
 * @property string $transport
 * @property string|null $host
 * @property int|null $port
 * @property string|null $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $region
 * @property string $from_address
 * @property string $from_name
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_outcome
 */
final class MailSetting extends Model
{
    public const TRANSPORT_SMTP = 'smtp';

    public const TRANSPORT_POSTMARK = 'postmark';

    public const TRANSPORT_RESEND = 'resend';

    public const TRANSPORT_SES = 'ses';

    public const TRANSPORT_LOG = 'log';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    /**
     * What may be chosen, in the order the screen offers them.
     *
     * SMTP first because it is the one that works with anything. `log` last because it sends nothing
     * and is here for the same reason it is in config/mail.php: somebody standing up an installation
     * needs a setting that provably does not email people while they are still testing.
     *
     * @var list<string>
     */
    public const TRANSPORTS = [
        self::TRANSPORT_SMTP,
        self::TRANSPORT_POSTMARK,
        self::TRANSPORT_RESEND,
        self::TRANSPORT_SES,
        self::TRANSPORT_LOG,
    ];

    /**
     * What each transport needs installed before it can be built, and what to require to get it.
     *
     * Two of these ship with nothing behind them. `config/mail.php` has listed postmark and resend
     * as mailers since it was generated, so `MAIL_MAILER=postmark` has always been settable and has
     * always died at send time with a class-not-found - a failure that reaches the reader as a 500
     * from a password reset, hours later, if at all.
     *
     * A dropdown makes that worse by looking like an offer, so the screen asks first. Neither
     * package is added as a dependency here: a control plane that people self-host should not carry
     * two API clients for services most installations will never use, and the composer line is a
     * better answer than a megabyte of vendor.
     *
     * smtp, ses and log are absent from this map because they need nothing: symfony/mailer and
     * aws/aws-sdk-php are both already required, and log writes to a file.
     *
     * @var array<string, array{class: string, package: string}>
     */
    public const TRANSPORT_REQUIREMENTS = [
        self::TRANSPORT_POSTMARK => [
            'class' => 'Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory',
            'package' => 'symfony/postmark-mailer',
        ],
        self::TRANSPORT_RESEND => [
            'class' => 'Resend',
            'package' => 'resend/resend-php',
        ],
    ];

    protected $guarded = [];

    /**
     * The transports this installation could actually send through.
     *
     * @return list<string>
     */
    public static function availableTransports(): array
    {
        return array_values(array_filter(
            self::TRANSPORTS,
            static fn (string $transport): bool => self::missingPackage($transport) === null,
        ));
    }

    /**
     * What to require before this transport will work, or null when it already does.
     */
    public static function missingPackage(string $transport): ?string
    {
        $requirement = self::TRANSPORT_REQUIREMENTS[$transport] ?? null;

        if ($requirement === null) {
            return null;
        }

        /*
         | The static analyser knows these two classes are absent from this checkout and reports the
         | call as always false, which is exactly the state this method exists to detect - and which
         | stops being true the moment somebody requires either package. Silenced rather than worked
         | around, because every way of hiding the literal from the analyser also hides it from the
         | reader.
         |
         | @phpstan-ignore function.impossibleType
         */
        if (class_exists($requirement['class'])) {
            return null;
        }

        return $requirement['package'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // See the migration. The same reasoning as NotificationDestination::signing_secret: a
            // database dump alone must not hand somebody the ability to send mail as this
            // installation.
            'password' => 'encrypted',

            'port' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * This configuration, as paths into the live config repository.
     *
     * Every key this class ever touches appears here, nulls included. That is the whole point of
     * writing it this way: switching from SMTP to Postmark must not leave a host from the
     * environment standing behind an API transport, where it would be invisible and would come back
     * the moment somebody switched to SMTP without retyping it.
     *
     * Written under `mail.mailers.*` rather than `services.*`. MailManager reads the mailer's own
     * config array first for all four transports and only falls back to `services.*`, so one place
     * covers every one of them and nothing here depends on which fallback key a framework version
     * happens to prefer.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        $smtp = $this->transport === self::TRANSPORT_SMTP;

        return [
            'mail.default' => $this->transport,
            'mail.from.address' => $this->from_address,
            'mail.from.name' => $this->from_name,

            'mail.mailers.smtp.host' => $smtp ? $this->host : null,
            'mail.mailers.smtp.port' => $smtp ? $this->port : null,
            'mail.mailers.smtp.username' => $smtp ? $this->username : null,
            'mail.mailers.smtp.password' => $smtp ? $this->password : null,

            /*
             | Laravel 11 dropped MAIL_ENCRYPTION in favour of a URL scheme, so the two words this
             | screen offers have to be translated rather than passed through.
             |
             | 'smtps' is implicit TLS - the connection is encrypted before anything is said, which
             | is what port 465 means. Plain 'smtp' negotiates STARTTLS when the relay advertises it,
             | which is what "TLS" has meant in every mail client for twenty years. Null lets the
             | port decide, which is the framework's own default and the right answer for somebody
             | who has not thought about it.
             */
            'mail.mailers.smtp.scheme' => match ($smtp ? $this->encryption : null) {
                'ssl' => 'smtps',
                'tls' => 'smtp',
                default => null,
            },

            'mail.mailers.postmark.token' => $this->transport === self::TRANSPORT_POSTMARK ? $this->password : null,
            'mail.mailers.resend.key' => $this->transport === self::TRANSPORT_RESEND ? $this->password : null,

            'mail.mailers.ses.key' => $this->transport === self::TRANSPORT_SES ? $this->username : null,
            'mail.mailers.ses.secret' => $this->transport === self::TRANSPORT_SES ? $this->password : null,
            'mail.mailers.ses.region' => $this->transport === self::TRANSPORT_SES ? $this->region : null,
        ];
    }

    /**
     * What may be written to the audit log.
     *
     * Note what is absent, and note the name of the key that records the credential changing.
     * App\Domain\Audit\SecretGuard refuses - by throwing, not by redacting - any payload with a key
     * matching password|secret|credential|token and several more, so `password`, `password_changed`
     * and `credentials_replaced` would all fail the write outright. `login_replaced` carries the
     * same fact and passes.
     *
     * @return array<string, mixed>
     */
    public function auditable(): array
    {
        return [
            'transport' => $this->transport,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'region' => $this->region,
            'from' => $this->from_address,
            'from_name' => $this->from_name,
        ];
    }
}
