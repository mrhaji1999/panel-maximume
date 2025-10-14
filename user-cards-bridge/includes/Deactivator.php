<?php

namespace UCB;

class Deactivator {
    
    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Clear any scheduled events
        wp_clear_scheduled_hook('ucb_cleanup_logs');
    }
}
