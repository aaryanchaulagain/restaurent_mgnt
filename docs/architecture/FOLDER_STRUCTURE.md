# Suvakamana — Project Folder Structure

```text
restaurent/
├── docs/
│   ├── architecture/
│   │   ├── FOLDER_STRUCTURE.md
│   │   ├── DATABASE_ERD.md
│   │   └── ROLE_PERMISSION_MATRIX.md
│   ├── api/
│   │   └── API_ROUTE_PLAN.md
│   ├── design/
│   │   └── DESIGN_TOKENS.md
│   └── phases/
│       └── PHASE_0_CHECKLIST.md
├── backend/                          # Laravel 12 API
│   ├── app/
│   │   ├── Enums/
│   │   ├── Events/
│   │   ├── Exceptions/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Api/
│   │   │   │   │   ├── V1/
│   │   │   │   │   │   ├── Auth/
│   │   │   │   │   │   ├── Customer/
│   │   │   │   │   │   ├── Restaurant/
│   │   │   │   │   │   ├── Admin/
│   │   │   │   │   │   └── Shared/
│   │   │   │   │   └── HealthController.php
│   │   │   ├── Middleware/
│   │   │   ├── Requests/
│   │   │   └── Resources/
│   │   ├── Jobs/
│   │   ├── Listeners/
│   │   ├── Models/
│   │   ├── Notifications/
│   │   ├── Policies/
│   │   ├── Providers/
│   │   ├── Services/
│   │   │   ├── Auth/
│   │   │   ├── Cart/
│   │   │   ├── Commission/
│   │   │   ├── Delivery/
│   │   │   ├── Finance/
│   │   │   ├── Menu/
│   │   │   ├── Order/
│   │   │   ├── Payment/
│   │   │   └── Notification/
│   │   └── Support/
│   │       ├── ApiResponse.php
│   │       └── Money.php
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   │   ├── factories/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── routes/
│   │   ├── api.php
│   │   ├── channels.php
│   │   ├── console.php
│   │   └── web.php
│   ├── storage/
│   ├── tests/
│   │   ├── Feature/
│   │   └── Unit/
│   ├── .env.example
│   ├── composer.json
│   ├── Dockerfile
│   └── phpunit.xml
├── frontend/                         # Next.js (App Router)
│   ├── public/
│   │   ├── fonts/
│   │   ├── images/
│   │   └── textures/
│   ├── src/
│   │   ├── app/
│   │   │   ├── (public)/
│   │   │   ├── (auth)/
│   │   │   ├── (customer)/
│   │   │   ├── restaurant/
│   │   │   ├── admin/
│   │   │   ├── layout.tsx
│   │   │   ├── page.tsx
│   │   │   └── globals.css
│   │   ├── components/
│   │   │   ├── ui/                   # shadcn/ui
│   │   │   ├── layout/
│   │   │   ├── restaurant/
│   │   │   ├── cart/
│   │   │   ├── order/
│   │   │   └── shared/
│   │   ├── features/
│   │   ├── hooks/
│   │   ├── lib/
│   │   │   ├── api/
│   │   │   ├── auth/
│   │   │   └── utils/
│   │   ├── stores/                   # Zustand
│   │   ├── styles/
│   │   │   └── tokens.css
│   │   └── types/
│   ├── .env.example
│   ├── Dockerfile
│   ├── next.config.ts
│   ├── package.json
│   ├── tailwind.config.ts
│   └── tsconfig.json
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   ├── php/
│   │   └── php.ini
│   └── postgres/
│       └── init.sql
├── .github/
│   └── workflows/
│       ├── backend-tests.yml
│       └── frontend-ci.yml
├── docker-compose.yml
├── .gitignore
└── README.md
```

## Boundaries

| Area | Responsibility |
|------|----------------|
| `backend/` | REST API, auth, domain logic, queues, webhooks |
| `frontend/` | UI only — never owns price/commission truth |
| `docs/` | Architecture decisions and phase plans |
| `docker/` | Local infra config (Postgres, Redis, Nginx) |

Phase 0 populates the skeleton above. Domain folders under `Services/`, portal routes, and feature UI ship in later phases.
