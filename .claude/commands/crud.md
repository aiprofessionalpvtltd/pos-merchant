---
description: Scaffold a full CRUD resource following project architecture
argument-hint: <ResourceName> [admin|api|both] [fields...]
---

Resource: $ARGUMENTS

Create, following CLAUDE.md conventions (check for existing files first; never overwrite):

- Migration (`foreignId()->constrained()`, explicit `nullable()`, comments on ambiguous columns)
- Model with `$fillable`, `$casts`, relationships, scopes
- `StoreXxxRequest` / `UpdateXxxRequest` with `authorize()`
- Service class in `app/Services/` holding the business logic
- Thin controller in `app/Http/Controllers/Admin/` and/or `API/` with resourceful methods
- API Resource in `app/Http/Resources/` (API only); paginate the index
- Named routes via `Route::resource()`, permission middleware
- Permissions in the seeder
- Feature test covering happy path, validation (422) and authorization (403)

Give the plan first, then implement. List every created file at the end.
