<?php

/**
 * phpMyAdmin configuration file
 */

// Set login cookie validity to 1 year (31,536,000 seconds)
$cfg['LoginCookieValidity'] = 31536000;

// Increase session timeout to match
$cfg['LoginCookieStore'] = 31536000;

// Additional session settings to prevent logouts
$cfg['LoginCookieRecall'] = true;
