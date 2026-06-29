<?php
/**
 * Configuration File
 * 
 * Defines global configuration constants, such as the Gemini API Key.
 */

// Define the Gemini API Key, fallback to a default key if not set in the environment
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AQ.Ab8RN6KPipjeP6OmOA8xZmab4Yn1ylaCFMvrl4FBkcZazRt3jw');

// Gemini model to use — gemini-2.5-flash has confirmed quota on this project's free tier
define('GEMINI_MODEL', 'gemini-2.5-flash');
?>
