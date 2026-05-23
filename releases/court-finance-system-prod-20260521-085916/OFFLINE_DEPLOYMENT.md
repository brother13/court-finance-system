# Offline production deployment

This package is prepared for an offline production server.

Included runtime dependencies:
- backend/vendor
- frontend/dist
- backend/composer.json
- backend/composer.lock

Production checks:
1. PHP must be compatible with the application.
2. PHP extensions `gd` and `mbstring` must be enabled for mPDF.
3. `backend/runtime` must be writable by the PHP process.
4. Configure `backend/app/database.php` for the production MySQL database.
5. Apply database scripts from `backend/database` according to the target environment.

Do not run Composer on the offline production server. Build dependencies on a
connected build machine, then deploy this zip.
