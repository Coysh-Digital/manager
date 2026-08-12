<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answering an action without sending the browser anywhere.
 *
 * Every route this is applied to queues a job and returns `back()`. The job was always queued the
 * moment the row was written - nothing in this application reaches out to a site - so the redirect
 * is not part of the work. It is only how a form post says "done", and following it reloads a screen
 * that has not changed yet, because a site has up to five minutes to come and collect the request.
 *
 * The observable consequence is somebody pressing "Back up now" a second time, which is another full
 * dump of a production database. So: let the browser ask for the same action and read the answer
 * where it stands.
 *
 * **This converts one thing.** A redirect carrying flash data becomes that flash data as JSON.
 * Anything else - a view, a stream, an error page - is returned exactly as it came, because a
 * response this file does not understand is one it has no business reshaping.
 *
 * That includes aborts. `abort(403)` is rendered into a response by the routing pipeline before this
 * middleware sees it, so a refusal arrives here as an HTML error page and leaves as one; the script
 * on the other end cannot read it, gives up, and re-posts the form the ordinary way. The person then
 * lands on the same error page they would have landed on with JavaScript switched off, which is the
 * honest outcome and needs no code here to produce it.
 */
final class AnswerInPlace
{
    /**
     * The opt-in.
     *
     * A header of our own rather than `expectsJson()`, which is true of anything sending
     * `Accept: application/json` - including curl, and including a future integration that wanted
     * the redirect it has always been given. This is narrow, greppable, and set in exactly one
     * place: resources/js/submit.js.
     */
    public const HEADER = 'X-Manager-Async';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader(self::HEADER)) {
            return $next($request);
        }

        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        /*
         | Only what *this* request flashed.
         |
         | A flash survives one further request, so while this one runs the session still holds the
         | previous action's message. Reading `status` unconditionally would re-announce the last
         | backup every time somebody pressed a button that had nothing to say. `_flash.new` is the
         | list of keys flashed during this request, which is precisely the question being asked.
         |
         | Nothing is forgotten afterwards. The bands above <main> are left to render on the next
         | ordinary navigation exactly as they always have, so an installation with JavaScript
         | switched off cannot tell this file exists.
        */
        $session = $request->session();
        $fresh = (array) $session->get('_flash.new', []);
        $errors = $session->get('errors');

        return response()->json([
            'status' => in_array('status', $fresh, true) ? $session->get('status') : null,
            'warning' => in_array('warning', $fresh, true) ? $session->get('warning') : null,

            'errors' => in_array('errors', $fresh, true) && $errors instanceof ViewErrorBag
                ? $errors->all()
                : [],

            /*
             | Where the server wanted the browser to go.
             |
             | `back()` names the page the press came from, and staying on it is the whole point. But
             | a route moving behind `password.confirm` later, or a controller deciding to send
             | somebody elsewhere, would otherwise be silently ignored - so the destination travels
             | and the caller compares it against where it already is.
            */
            'redirect' => $response->getTargetUrl(),
        ]);
    }
}
