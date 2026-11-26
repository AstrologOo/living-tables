<?php

// The page's script runs only in a browser; these check what it is built from.

it('asks for an API token, keeps it in the browser and sends it with every request', function () {
    $page = $this->get('/graph')->assertOk()->getContent();

    expect($page)->toContain('id="token-input"')
        ->toContain('localStorage')
        ->toContain('Authorization: `Bearer ${token}`')
        // One call site, so no request can go out without the header.
        ->and(substr_count($page, 'fetch('))->toBe(1)
        ->and($page)->not->toContain('user_id')->not->toContain('user-id');
});

it('escapes what it puts into the page, as node data comes from outside', function () {
    $page = $this->get('/graph')->getContent();

    // Every ${...} inside an HTML string is escaped or a number the API computed.
    preg_match_all('/\$\{([^}]*)\}/', $page, $matches);
    $htmlLines = array_filter(explode("\n", $page), fn (string $line) => preg_match('/<[a-z\/][^`\']*\$\{|\$\{[^}]*\}[^`\']*<\//i', $line) === 1);

    expect($htmlLines)->not->toBeEmpty();
    foreach ($htmlLines as $line) {
        preg_match_all('/\$\{([^}]*)\}/', $line, $inserted);
        foreach ($inserted[1] as $expression) {
            expect($expression)->toMatch('/^(escapeHtml\(|rows\.meta\.total$|impact\.affected_count$|read$|action$)/');
        }
    }
    expect($page)->toContain('return escapeHtml(');
});

it('shortens a long node name to its box and shows the whole name on hover', function () {
    $page = $this->get('/graph')->getContent();

    expect($page)->toContain('fitText(label, nodeW - 20)')
        ->toContain("createElementNS(ns, 'title')")
        ->toContain('title.textContent = n.name');
});
