# SIPANTAU: Laravel API + Next.js

The Next.js frontend uses this Laravel 12 API for authentication, user
profiles, groups, tasks, activity, and administrator accounts. Laravel Sanctum
issues bearer tokens. MySQL/MariaDB is the intended database; XAMPP is
supported.

## One-time setup with XAMPP

1. Start **MySQL** in the XAMPP Control Panel and create a database named
   `sipantau` in phpMyAdmin using `utf8mb4`.
2. Copy `backend/.env.example` to `backend/.env`, then set the MySQL connection
   and a unique administrator email and password:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sipantau
   DB_USERNAME=root
   DB_PASSWORD=

   SIPANTAU_ADMIN_EMAIL=admin@example.com
   SIPANTAU_ADMIN_PASSWORD=use-a-unique-password
   ```

3. Install backend and frontend dependencies from the project root:

   ```powershell
   cd backend
   composer install
   if (-not (Test-Path .env)) { Copy-Item .env.example .env }
   php artisan key:generate
   cd ..\frontend
   npm install
   ```

   If `backend/.env` already exists, do not overwrite it; verify its database
   and admin settings instead. `frontend/.env.example` sets the local API URL
   to `http://127.0.0.1:8000/api`; copy it to `frontend/.env.local` if that
   value needs to be changed.

4. From the project root, run:

   ```powershell
   npm run dev:all
   ```

   The launcher applies database migrations, creates or updates the admin from
   the configured environment values, and opens separate terminals for the
   Laravel API (`http://127.0.0.1:8000`) and frontend
   (`http://127.0.0.1:3000`). Keep MySQL running while using the app.

You can also run the API and frontend manually in separate terminals:

```powershell
cd backend
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
cd frontend
npm run dev
```

## Email setup

Password reset and account-approval notifications are sent by Laravel. The
example environment uses `MAIL_MAILER=log`, which writes messages to
`backend/storage/logs/laravel.log` instead of delivering them. To send real
email, configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` in
`backend/.env`.

## Task reports and notifications

The dashboard includes role-aware task reports. Administrators and mentors can
filter by group, intern, status, priority, and deadline range; interns see only
their own assigned tasks. Reports can be exported as UTF-8 CSV for spreadsheet
applications.

The notification bell shows task assignments and deadline reminders. Deadline
reminders for tasks due within three days are created when the recipient opens
or refreshes the notification list. The frontend refreshes this list
periodically while the dashboard is open.

## Task assignment and evidence review

Administrators and mentors create tasks and assign them to active group
members. Interns can view only their assigned tasks and submit one evidence
file (PDF, image, Word, or Excel; up to 10 MB) with an optional note. Evidence
is stored on Laravel's private local disk and downloaded through an
authenticated API route. Mentors or administrators review the evidence, add
feedback when requesting changes, and approve it to mark the task complete.
Interns cannot create tasks, edit assignments, change task status, or review
their own submissions through the API.

## Tests

```powershell
cd backend
php artisan test
```

The test suite uses SQLite and does not require XAMPP or a running MySQL
service.
