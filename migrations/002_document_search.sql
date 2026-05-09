-- 002_document_search.sql
--
-- FTS5 external-content virtual table over documents.title, kept in
-- sync via triggers. External-content means the virtual table stores
-- only the index, not a second copy of the data.

CREATE VIRTUAL TABLE documents_fts USING fts5(
    title,
    content='documents',
    content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);

CREATE TRIGGER documents_ai AFTER INSERT ON documents BEGIN
    INSERT INTO documents_fts(rowid, title) VALUES (new.id, new.title);
END;

CREATE TRIGGER documents_ad AFTER DELETE ON documents BEGIN
    INSERT INTO documents_fts(documents_fts, rowid, title) VALUES('delete', old.id, old.title);
END;

CREATE TRIGGER documents_au AFTER UPDATE ON documents BEGIN
    INSERT INTO documents_fts(documents_fts, rowid, title) VALUES('delete', old.id, old.title);
    INSERT INTO documents_fts(rowid, title) VALUES (new.id, new.title);
END;
