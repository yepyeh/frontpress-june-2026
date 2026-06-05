<?php

defined('FRONTPRESS_BOOT') || exit;

// ── Admin login ───────────────────────────────────────────────────────────
define('FPS_ADMIN_USER',      getenv('FPS_ADMIN_USER')      ?: 'fpsadmin');
define('FPS_ADMIN_PASS_HASH', '$2y$12$WFAGSzJ9ZtvcWNDLg8IXDeNCOWOoaHlmyNoFM2wBaagpx.wJ0V4k6');

// ── Runtime ───────────────────────────────────────────────────────────────
define('FPS_APP_ENV',              getenv('FPS_APP_ENV')              ?: 'dev');
define('FPS_APP_DEBUG',            getenv('FPS_APP_DEBUG')            ?: '0');
define('FPS_SESSION_IDLE_SECONDS', getenv('FPS_SESSION_IDLE_SECONDS') ?: '7200');

// ── Integrations (optional) ───────────────────────────────────────────────
// Server-wide fallback for the Unsplash Access Key. Used only when the
// install has no key set under Settings → Integrations. Convenient when
// you run multiple installs and don't want to click through the UI on
// each one. Leave empty to require per-install configuration.
//
// This file is gitignored (config.php) so the key never ships in releases.
// NEVER paste a real key into the tracked `sample.config.php` template —
// any value committed here would leak to every install of the framework.
define('FPS_UNSPLASH_ACCESS_KEY', getenv('FPS_UNSPLASH_ACCESS_KEY') ?: '');
