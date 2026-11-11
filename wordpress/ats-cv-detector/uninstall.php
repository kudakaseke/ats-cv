<?php

defined('WP_UNINSTALL_PLUGIN') || exit;
// Preserve private CVs and financial history. Removal requires the site's approved retention/export process.
wp_clear_scheduled_hook('atscv_maintenance');
wp_unschedule_hook('atscv_ai_job');
