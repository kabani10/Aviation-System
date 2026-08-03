# Aviation System

AI-assisted operations CRM for flight support (trip support) companies — covers the full lifecycle
from an inbound flight request through supplier coordination, quotation, live operations, and
invoicing. Multi-tenant: each flight support company is a tenant with its own employees and roles.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the folder structure, tenancy model, and conventions a
new contributor needs before touching code.

## Stack

- Laravel 12 + Filament 3 (admin/ops UI)
- PostgreSQL, Redis (queue/cache)
- Postmark (transactional + inbound email)
- Pest (testing), Pint (style)

## Local setup

```bash
cp .env.example .env
php artisan key:generate

docker compose up -d        # Postgres, Redis, Mailpit
composer install
php artisan migrate

php artisan serve           # or use Herd, which already serves this directory
```

Mailpit (catches all local outbound mail): http://127.0.0.1:8025
Admin panel: `/admin`

## Testing

```bash
./vendor/bin/pest
./vendor/bin/pint --test    # check style without fixing
```

## Project plan

The phased roadmap this build follows is tracked outside the repo; ask before assuming a phase is
done — check `ARCHITECTURE.md` and the codebase itself as the source of truth over any external doc.
