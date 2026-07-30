# Phase 0 — Implementation Checklist

## Goal

Stable project foundation only. No marketplace features, no public UI polish beyond a bootable shell, no domain business logic.

---

## Pre-code artifacts (required before implementation)

- [x] Final project folder structure → `docs/architecture/FOLDER_STRUCTURE.md`
- [x] Database ERD → `docs/architecture/DATABASE_ERD.md`
- [x] Role and permission matrix → `docs/architecture/ROLE_PERMISSION_MATRIX.md`
- [x] API route plan → `docs/api/API_ROUTE_PLAN.md`
- [x] Design-token file → `docs/design/DESIGN_TOKENS.md` + `frontend/src/styles/tokens.css`
- [x] This checklist → `docs/phases/PHASE_0_CHECKLIST.md`

---

## Backend (Laravel 12)

- [x] Create Laravel project in `backend/`
- [x] PHP 8.2+, PostgreSQL + Redis config in `.env.example`
- [x] Install Laravel Sanctum (structure prepared, not full auth flows)
- [x] Shared API response helper (`ApiResponse`)
- [x] Centralised exception → JSON error formatting
- [x] `GET /api/health` and `GET /api/v1/health` (DB + Redis checks)
- [x] Base `routes/api.php` versioning
- [x] Foundation migrations: users, cache, jobs, sessions, Sanctum tokens
- [x] RBAC stub migrations: roles, permissions, role_permissions, user_roles
- [x] Coding standards: Pint config
- [x] Feature test for health endpoint
- [x] Logging defaults (stack channel)
- [x] Queue connection = Redis in `.env.example`

## Frontend (Next.js)

- [x] Create Next.js + TypeScript + Tailwind app in `frontend/`
- [x] App Router shell
- [x] Design tokens CSS imported
- [x] Tailwind theme wired to brand tokens
- [x] API client stub pointing at backend URL
- [x] `.env.example` with `NEXT_PUBLIC_API_URL`
- [x] Minimal home page confirming frontend boots
- [x] ESLint / TypeScript strict baseline

## Infrastructure

- [x] Root `docker-compose.yml` (Postgres, Redis, backend, frontend, Nginx)
- [x] `docker/nginx/default.conf`
- [x] Root `.gitignore`
- [x] Root `README.md` with setup commands
- [x] Git repository initialised
- [x] GitHub Actions workflow stubs

## Verification criteria

| Check | Pass condition | Status |
|-------|----------------|--------|
| Frontend runs locally | `npm run dev` serves page | Pass |
| Backend runs locally | `php artisan serve` responds | Pass |
| Migrations | `php artisan migrate` succeeds | Pass (SQLite local) |
| Redis | Health endpoint reports Redis OK when available | Pending Docker/Redis install |
| Health API | `GET /api/v1/health` returns JSON envelope | Pass |
| Auth structure | Sanctum installed; `/auth/*` reserved for Phase 2 | Pass |
| Secrets | No real secrets in repo; `.env.example` only | Pass |

## Explicitly out of scope (Phase 0)

- Premium marketing pages (Phase 1)
- Login / register / MFA (Phase 2)
- Restaurant CRUD / menus / orders
- Stripe, Uber Direct, Maps
- Seed restaurants
- Realtime websockets

## Exit

Phase 0 foundation is complete for local SQLite boot. Full Redis + PostgreSQL verification requires Docker Desktop (or local Postgres/Redis services). Ready for Phase 1 after that optional infra confirmation.
