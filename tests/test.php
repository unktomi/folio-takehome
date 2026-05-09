<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function render_page(string $script, array $get = [], array $post = [], string $method = 'GET'): string {
    $env = [
        'TEST_SCRIPT' => $script,
        'TEST_GET'    => json_encode($get),
        'TEST_POST'   => json_encode($post),
        'TEST_METHOD' => $method,
        'PATH'        => getenv('PATH') ?: '',
    ];
    $cmd = ['php', __DIR__ . '/_render.php'];

    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('could not start subprocess');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException("render_page({$script}) exited {$code}: {$stderr}");
    }
    return $stdout;
}

function make_doc(string $title, string $body = 'body'): array {
    $slug = generate_document_slug();
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)
    ');
    $stmt->execute([$title, $body, $slug]);
    return [(int) db()->lastInsertId(), $slug];
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Slug (internal handle) ---

test('new documents get a unique, well-formed slug', function () {
    [$id1, $slug1] = make_doc('Doc One');
    [$id2, $slug2] = make_doc('Doc Two');
    assert_true($id1 !== $id2, 'distinct IDs');
    assert_true($slug1 !== $slug2, 'distinct slugs');
    assert_true(preg_match('/^doc_[2-9A-HJ-NP-TV-Z]{10}$/', $slug1) === 1, "slug format: {$slug1}");
    assert_true(preg_match('/^doc_[2-9A-HJ-NP-TV-Z]{10}$/', $slug2) === 1, "slug format: {$slug2}");
});

test('slug column has a unique index (duplicate insert fails)', function () {
    $slug = generate_document_slug();
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['A', 'a', $slug]);
    $threw = false;
    try {
        $stmt->execute(['B', 'b', $slug]);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected UNIQUE constraint violation on duplicate slug');
});

test('slug is never rendered in admin list HTML', function () {
    [$id, $slug] = make_doc('Hidden Slug Doc');
    $html = render_page('admin.php');
    assert_true(str_contains($html, 'Hidden Slug Doc'), 'title should render');
    assert_true(!str_contains($html, $slug), "slug {$slug} leaked into admin HTML");
    assert_true(!str_contains($html, 'doc_'), 'no slug prefix should appear anywhere in admin HTML');
});

test('slug is never rendered in the recipient view', function () {
    [$docId, $slug] = make_doc('Recipient View Doc', 'hello world');
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'r@example.com']);

    $html = render_page('view.php', ['token' => $token]);
    assert_true(str_contains($html, 'Recipient View Doc'), 'title should render');
    assert_true(!str_contains($html, $slug), "slug {$slug} leaked into view HTML");
});

test('slug is recorded in the audit log on document creation', function () {
    $html = render_page('admin.php', [], ['title' => 'Audited Doc', 'body' => 'b'], 'POST');
    $row = db()->query("
        SELECT details FROM audit_log
        WHERE action='create' AND entity_type='document'
        ORDER BY id DESC LIMIT 1
    ")->fetch();
    assert_true($row !== false, 'expected an audit row');
    $details = json_decode($row['details'], true);
    assert_true(isset($details['slug']), 'audit details should include slug');
    assert_true(preg_match('/^doc_/', $details['slug']) === 1, 'slug format in audit log');
});

// --- Search ---

test('search finds a document by full title', function () {
    make_doc('Onboarding Packet');
    $html = render_page('admin.php', ['q' => 'Onboarding Packet']);
    assert_true(str_contains($html, 'Onboarding Packet'), 'exact match should appear');
});

test('search matches a prefix of a single token', function () {
    make_doc('Quarterly Review 2026');
    $html = render_page('admin.php', ['q' => 'quart']);
    assert_true(str_contains($html, 'Quarterly Review 2026'), 'prefix match should appear');
});

test('search matches multiple tokens with AND semantics', function () {
    make_doc('Acme Renewal Contract');
    make_doc('Acme Kickoff Notes');
    $html = render_page('admin.php', ['q' => 'acme renewal']);
    assert_true(str_contains($html, 'Acme Renewal Contract'), 'both-token match should appear');
    assert_true(!str_contains($html, 'Acme Kickoff Notes'), 'partial-token match should NOT appear');
});

test('search is case-insensitive', function () {
    make_doc('Vendor Security Review');
    $html = render_page('admin.php', ['q' => 'VENDOR security']);
    assert_true(str_contains($html, 'Vendor Security Review'), 'case-insensitive match should appear');
});

test('search with no matches shows an empty-state message', function () {
    $html = render_page('admin.php', ['q' => 'zzznevermatchzzz']);
    assert_true(str_contains($html, 'No documents match'), 'empty-state should render');
});

test('empty search query shows the full list', function () {
    make_doc('Listed Doc A');
    make_doc('Listed Doc B');
    $html = render_page('admin.php', ['q' => '']);
    assert_true(str_contains($html, 'Listed Doc A'), 'should show all docs');
    assert_true(str_contains($html, 'Listed Doc B'), 'should show all docs');
});

test('search survives hostile FTS5 input without crashing', function () {
    make_doc('Safe Title');
    $hostile = ['"safe" OR NEAR(', '* * *', 'AND NOT ^col:', '()()()'];
    foreach ($hostile as $q) {
        $html = render_page('admin.php', ['q' => $q]);
        assert_true(str_contains($html, '<table') || str_contains($html, 'No documents match'),
            "hostile query {$q} should render a normal page");
    }
});

test('search finds a doc via a quote-containing user query', function () {
    make_doc('Safe Title');
    $html = render_page('admin.php', ['q' => '"safe"']);
    assert_true(str_contains($html, 'Safe Title'), 'should find "Safe Title" via "safe" input');
});

test('build_fts_query handles edge cases', function () {
    assert_true(build_fts_query('') === null, 'empty input -> null');
    assert_true(build_fts_query('   ') === null, 'whitespace-only -> null');
    assert_true(build_fts_query('!!!') === null, 'punctuation-only -> null');
    assert_true(build_fts_query('hello') === '"hello"*', 'single token quoted + *');
    assert_true(build_fts_query('foo bar') === '"foo"* "bar"*', 'multi token quoted + *');
    assert_true(build_fts_query('FOO "bar"') === '"FOO"* "bar"*', 'strips quotes from input');
    assert_true(build_fts_query('OR NEAR') === '"OR"* "NEAR"*', 'reserved words quoted');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
