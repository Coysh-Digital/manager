<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\MailManager;

/**
 * The framework's mail manager, asking what this installation is configured to do first.
 *
 * `mailer()` is the sole funnel and that is why this is the only method here. `driver()` delegates
 * to it, `__call` delegates to it, the container's `'mailer'` binding is
 * `$app['mail.manager']->mailer()`, the notification channel calls `$factory->mailer(...)` and a
 * queued mailable calls `$mailer->mailer(...)`. Overriding one method therefore covers web requests,
 * queue workers and console commands without a single per-entry-point hook.
 *
 * Not final, unlike almost everything else here. `Mail::shouldReceive(...)` builds a partial mock of
 * whatever class is bound, and Mockery cannot replace a method on a final one - so marking this
 * final would break every test that fakes a send, in a way whose message points at Mockery rather
 * than at the change that caused it.
 *
 * @see MailConfiguration for what "configured" means and why it is not read at boot.
 */
class ConfiguredMailManager extends MailManager
{
    /**
     * @param  \UnitEnum|string|null  $name
     * @return Mailer
     */
    public function mailer($name = null)
    {
        /*
         | Before parent::mailer(), not after.
         |
         | A Mailer is built from configuration exactly once and then cached by name - including the
         | global From address, which MailManager::resolve() sets on the instance rather than reading
         | per message. Anything already built predates whatever this pushes in, which is why
         | MailConfiguration forgets the cached mailers when it changes something.
         */
        $this->app->make(MailConfiguration::class)->apply();

        return parent::mailer($name);
    }
}
