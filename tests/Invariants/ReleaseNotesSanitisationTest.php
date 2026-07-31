<?php

declare(strict_types=1);

use App\Domain\Updates\ChangelogMarkdown;
use App\Domain\Updates\ReleaseNotesHtml;

/**
 * Nothing reaches the notes panel that the allowlist does not name.
 *
 * Release notes are written by whoever publishes the plugin, travel over the network from a site's
 * connector, and are rendered inside an authenticated control-plane session. Until this change the
 * platform's answer was to discard all markup, which was safe and also discarded the notes — the
 * panel showed version headings with nothing under them.
 *
 * Keeping the markup means constraining it, and this file is the constraint. Both entry points are
 * covered, because there are two source languages and only one of them is obvious: Craft's changelog
 * is Markdown from GitHub, a plugin's is HTML from the Plugin Store by way of the connector.
 */
$dangerous = [
    'a script tag' => '<script>alert(1)</script>',
    'a style block' => '<style>body{display:none}</style>',
    'an iframe' => '<iframe src="https://evil.example"></iframe>',
    'an object' => '<object data="https://evil.example"></object>',
    'an embed' => '<embed src="https://evil.example">',
    'a form' => '<form action="https://evil.example"><input name="x"></form>',
    'a base tag' => '<base href="https://evil.example/">',
    'an svg onload' => '<svg onload="alert(1)"></svg>',
    'an image onerror' => '<img src=x onerror="alert(1)">',
    'a tracking pixel' => '<img src="https://tracker.example/pixel.gif">',
    'a javascript link' => '<a href="javascript:alert(1)">x</a>',
    'a data link' => '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
    'a protocol-relative link' => '<a href="//evil.example">x</a>',
    'an inline handler' => '<a href="https://ok.example" onclick="alert(1)">x</a>',
];

it('puts nothing on the page that the allowlist does not name', function () use ($dangerous): void {
    foreach ($dangerous as $description => $markup) {
        $viaHtml = (string) ChangelogMarkdown::renderHtml([['heading' => '1.0.0', 'body' => $markup]]);
        $viaMarkdown = (string) ChangelogMarkdown::render([$markup]);

        foreach (['renderHtml' => $viaHtml, 'render' => $viaMarkdown] as $path => $output) {
            foreach (['<script', '<style', '<iframe', '<object', '<embed', '<form', '<input', '<base', '<svg', '<img', 'onerror', 'onload', 'onclick', 'javascript:', 'data:text/html', 'evil.example', 'tracker.example'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $output,
                    sprintf('%s let "%s" through from %s.', $path, $forbidden, $description),
                );
            }
        }
    }
});

it('keeps a version heading a plugin author cannot forge', function (): void {
    // The heading is composed after sanitising, from text the platform built itself, so a note
    // claiming to be a different release cannot produce a second one that looks the same.
    $html = (string) ChangelogMarkdown::renderHtml([[
        'heading' => '5.7.1 — 2026-07-22',
        'body' => '<h2>9.9.9 — not a real release</h2><p>Body text.</p>',
    ]]);

    expect(substr_count($html, '<h2>'))->toBe(2)
        ->and($html)->toStartWith('<h2>5.7.1 — 2026-07-22</h2>')
        ->and($html)->toContain('Body text.');
});

it('escapes the heading it composes', function (): void {
    // The version and date come from a report a site sent. They are put into markup by hand here,
    // which is exactly the place an escaping mistake would not be noticed.
    $html = (string) ChangelogMarkdown::renderHtml([[
        'heading' => '<script>alert(1)</script>',
        'body' => '<p>ok</p>',
    ]]);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('holds the section and character caps', function (): void {
    $many = array_map(
        fn (int $i): array => ['heading' => "1.0.{$i}", 'body' => '<p>Note.</p>'],
        range(1, 25),
    );

    expect(substr_count((string) ChangelogMarkdown::renderHtml($many), '<h2>'))
        ->toBe(ChangelogMarkdown::MAX_SECTIONS);

    // The budget runs across sections rather than per section, so twenty releases cannot each spend
    // the maximum and produce a megabyte of panel.
    $huge = array_map(
        fn (int $i): array => ['heading' => "2.0.{$i}", 'body' => '<p>'.str_repeat('a', 50000).'</p>'],
        range(1, 20),
    );

    expect(mb_strlen((string) ChangelogMarkdown::renderHtml($huge)))
        ->toBeLessThan(ChangelogMarkdown::MAX_CHARACTERS * 1.2);
});

it('produces a well-formed document from a body cut mid-tag', function (): void {
    // Truncation happens on the raw body before sanitising, so a cut landing inside a tag is
    // repaired by the parser rather than emitted as a dangling fragment.
    $cut = '<p>Fixed a thing and then <stro';

    $html = ReleaseNotesHtml::sanitise($cut);

    expect($html)->toContain('Fixed a thing')
        ->and(substr_count($html, '<'))->toBe(substr_count($html, '>'));
});

it('has one place where release notes become HTML', function (): void {
    // Two render paths, one allowlist. A third path added later that reaches for Str::markdown or
    // builds its own sanitiser would bypass every assertion above.
    $files = glob(app_path('Domain/Updates/*.php')) ?: [];

    $usingMarkdown = [];
    $usingSanitizer = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, 'Str::markdown(')) {
            $usingMarkdown[] = basename($file);
        }

        if (str_contains($source, 'new HtmlSanitizer') || str_contains($source, 'HtmlSanitizerConfig')) {
            $usingSanitizer[] = basename($file);
        }
    }

    expect($usingMarkdown)->toBe(['ChangelogMarkdown.php'])
        ->and($usingSanitizer)->toBe(['ReleaseNotesHtml.php']);
});
