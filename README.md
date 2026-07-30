# Suvakamana

Multi-restaurant marketplace platform. Independent restaurants publish menus, receive orders, and pay configurable commission to the Suvakamana platform.

> **Current phase: Phase 1 — Premium design system and static UI**

## Stack

| Layer | Technology |
|-------|------------|
| Frontend | Next.js, TypeScript, Tailwind CSS |
| Backend | Laravel 12, PHP 8.2, Sanctum |
| Database | MySQL / MariaDB (`suvakamana_restaurant`) |
| Cache / session / queue (Phase 1) | `file` / `file` / `sync` |

## Documentation

- [Folder structure](docs/architecture/FOLDER_STRUCTURE.md)
- [Database ERD](docs/architecture/DATABASE_ERD.md)
- [Role & permission matrix](docs/architecture/ROLE_PERMISSION_MATRIX.md)
- [API route plan](docs/api/API_ROUTE_PLAN.md)
- [Design tokens](docs/design/DESIGN_TOKENS.md)
- [Phase 0 checklist](docs/phases/PHASE_0_CHECKLIST.md)

## Prerequisites

- PHP 8.2+, Composer, `pdo_mysql`
- Node.js 20+, npm
- MySQL / MariaDB via XAMPP or native install (port `3306`)

## Backend setup

1. Start MySQL (XAMPP Control Panel → MySQL Start).
2. Create the database if needed:

```sql
CREATE DATABASE suvakamana_restaurant
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

3. Configure and migrate:

```bash
cd backend
cp .env.example .env
php artisan key:generate
# Ensure DB_* matches your MySQL credentials
php artisan optimize:clear
php artisan migrate
php artisan serve
```

Health check: [http://localhost:8000/api/v1/health](http://localhost:8000/api/v1/health)

## Frontend setup

```bash
cd frontend
cp .env.example .env.local
npm install
npm run dev
```

App: [http://localhost:3000](http://localhost:3000)

## Environment variables

See `backend/.env.example` and `frontend/.env.example`.

Never commit real secrets. `.env` is gitignored.

## Tests

```bash
cd backend
php artisan test
```
"# restaurent_mgnt" 
