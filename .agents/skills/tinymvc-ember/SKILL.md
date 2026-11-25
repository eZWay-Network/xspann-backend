---
name: tinymvc-ember
description: Implement, debug, review, and test TinyMVC applications powered by TinyCore/Spark and TinyMVC Ember. Use whenever working on Spark routes, controllers, FormRequest validation, models, ORM, JSON resources, Blade views, cache, queues, storage, migrations, or testing.
---

# TinyMVC Ember AI Skill

This application uses **TinyMVC Ember** (`tinymvc/ember`) for framework-tailored AI assistance.

1. Load the comprehensive framework guidance from [vendor/tinymvc/ember/resources/ai/SKILL.md](../../../vendor/tinymvc/ember/resources/ai/SKILL.md).
2. For specific subsystems, refer to the reference guides in `vendor/tinymvc/ember/resources/ai/references/`.
3. Verify public APIs against installed source or run:
   ```bash
   php spark ember:lookup <symbol>
   ```
4. Adhere strictly to the Spark conventions and difference contracts documented in `AGENTS.md`.

If Ember is absent (for example after `composer install --no-dev`), inspect installed source and project conventions; do not silently install packages or use a sibling checkout.
