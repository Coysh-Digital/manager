{{--
    The plain-text alternative, built in PHP rather than here.

    Alignment, column widths and which context rows are worth printing are decisions with a reason
    behind each, and they belong next to the ones the HTML part makes - see
    App\Domain\Notifications\EmailTransport::body(), which is also what the invariants assert on.
    This view exists because Laravel addresses a text part by view name; it is the hook, not the
    content.

    Unescaped on purpose. This is the text/plain part, so an escaped ampersand would arrive as
    "&amp;" in somebody's mail client.
--}}
{!! $plain !!}
