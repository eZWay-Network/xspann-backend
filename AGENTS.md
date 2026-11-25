<tinymvc-ember-guidelines>
# TinyMVC Application Guidelines

This repository contains a **TinyMVC / Spark** application. It uses TinyMVC's native architecture, Spark CLI (`php spark`), and the `Spark\` namespace.

Read `.agents/skills/tinymvc-ember/SKILL.md` and only the relevant topic reference before implementation. Installed source is authoritative; this stack snapshot is refreshed by `php spark ember:install`.

## Application & Stack Context

- **Framework**: TinyMVC Spark (`tinymvc/tinycore` 4.3.9)
- **PHP Version**: 8.4.27 (required >= 8.2)
- **Database**: mysql
- **Cache Store**: database
- **Queue Driver**: database
- **Session Driver**: database
- **Frontend Stack**: Blade + Vite + Tailwind CSS + Inertia.js
- **Test Runner**: `php test` (`Tests\TestCase`)

## Common Development Commands

| Task | Command |
| --- | --- |
| Start Dev Server | `php spark serve` |
| Run Migrations | `php spark migrate` |
| Rollback Migration | `php spark migrate:rollback` |
| Clear All Caches | `php spark cache:clear && php spark view:clear && php spark config:clear` |
| Run Tests | `php test` (or `php test --filter=TestClassName`) |
| Build Assets | `npm run build` (or `npm run dev`) |
| Make Code | `php spark make:model`, `php spark make:controller`, `php spark make:migration`, `php spark make:request`, `php spark make:resource`, `php spark make:job` |
| API Lookup | `php spark ember:lookup <term>` |

## Critical Spark vs Laravel API Differences

| Intent | Spark Contract | Laravel Habit to AVOID |
| --- | --- | --- |
| Validated input | Inject `Spark\Foundation\Http\FormRequest`. `$request->validated()` returns `Spark\Http\Input` (`ArrayAccess`, property access). | Expecting a plain array |
| Validation factory | `Validator::make($rules, $data)` (**rules first**). | `make($data, $rules)` |
| Model writes | `Model::create($input)` returns model; `$model->fill($input)->save()` returns `bool`. | Expecting `save()` to return model |
| Model deletion | `$model->remove()` returns `bool`. Query builder `delete()` returns affected count. | `$model->delete()` (not instance API) |
| Query collections | `get()` returns a Collection; `all()` returns a plain PHP array. | Expecting `all()` to return collection |
| Pagination | `Spark\Paginator` (`paginate()`, `simplePaginate()`, `cursorPaginate()`). | `Illuminate\Pagination\*` |
| Passwords | `Hash::password($pw)` / `Hash::verify($pw, $hash)`. | `Hash::make()` / `Hash::check()` |
| Cache | `Cache::remember($key, fn() => ..., '+1 hour')` (**callback before TTL**). | `remember($key, $ttl, $cb)` |
| Queues & Jobs | Implement `Spark\Queue\Contracts\JobInterface`, use `Spark\Queue\Dispatchable`. String IDs. | Closure jobs (unsupported) |
| Date handling | `now()`, `carbon()`, `Spark\Carbon` follow app timezone. | Reformatting dates by hand |
| Blade Loops | `@forelse(...) ... @empty ... @endforelse`, `@foreach`. Native `$loop` metadata; conditional `@break($cond)`, `@continue($cond)`. | Assuming loop metadata for `@for` / `@while` |
| Blade Components | Anonymous components in `resources/views/components/`. Named slots `<x-slot:name>` or `<x-slot name="...">`. | Class-based components |
| Blade Directives | Native `@stack`, `@push`, `@prepend`, `@pushIf`, `@prependIf`, `@once`, `@each`, `@inject`, `@canany`. Helpers in views (`user()`, `auth()`, `session()`, `route()`, `old()`, `errors()`). | `\Spark\Facades\*` directly in views |
| Errors in Views | `Spark\Http\ErrorBag`: `$errors->has('field')`, `$errors->first('field')`, `$errors->all()`. | `ViewErrorBag` / `InputErrors` |

## Coding & Architectural Conventions

- Follow clean, focused code: 4-space indentation, explicit visibility, typed parameters and returns.
- Keep controllers thin: validate via FormRequest, delegate heavy domain logic to services or model scopes, return explicit JSON resources or views.
- Preserve authorization and ownership checks; validate writes, escape untrusted output, and keep credentials out of code and generated guidance.
- Follow existing conventions and reuse native APIs before adding dependencies or abstractions.
- Test changes with the narrowest check (`php test --filter=...`).
- When looking up framework APIs, use `php spark ember:lookup <symbol>` or add `--vendor=vendor/package` for an installed integration. Keep vendor files read-only during application work.
</tinymvc-ember-guidelines>