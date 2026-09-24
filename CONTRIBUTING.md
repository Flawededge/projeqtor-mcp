# Contributing

`main` contains releasable code and is tagged for every release. Develop changes on a focused branch such as `feature/schema-discovery`; do not commit secrets or deployment-specific addresses.

Before merging:

1. Run `npm ci`, `npm run check`, and `npm test` in `server/`.
2. Run `php -l bridge/index.php` and `php -l bridge/schema.php` where PHP is available.
3. Exercise read and write paths against a disposable ProjeQtOr project.
4. Confirm one configured user cannot exceed that user's ProjeQtOr permissions.
5. Update `CHANGELOG.md` and the documented capability list.

Use Semantic Versioning. Breaking tool schemas require a major release; backward-compatible tools are minor releases; fixes are patch releases.
