<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $slug = generate_document_slug();
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, slug)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $slug]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title' => $title,
            'slug' => $slug,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

// Search: ?q= runs an FTS5 MATCH against titles. Empty -> full list.
$q = trim((string) ($_GET['q'] ?? ''));
$ftsQuery = $q !== '' ? build_fts_query($q) : null;

if ($ftsQuery !== null) {
    // Join FTS virtual table -> documents -> staff. bm25 rank first
    // (lower = better match), recency as tiebreaker.
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents_fts f
        JOIN documents d ON d.id = f.rowid
        JOIN staff s ON s.id = d.created_by
        WHERE documents_fts MATCH :q
        ORDER BY bm25(documents_fts), d.created_at DESC
    ');
    $stmt->execute([':q' => $ftsQuery]);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form" role="search">
        <label for="q" class="sr-only">Search documents by title</label>
        <input
            type="search"
            id="q"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Search by title…"
            autocomplete="off"
        >
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($q !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>

    <?php if ($q !== '' && empty($docs)): ?>
        <p class="empty">No documents match “<?= h($q) ?>”.</p>
    <?php elseif (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <?php if ($q !== ''): ?>
            <p class="meta"><?= count($docs) ?> result<?= count($docs) === 1 ? '' : 's' ?> for “<?= h($q) ?>”.</p>
        <?php endif ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
