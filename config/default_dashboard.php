<?php

/**
 * A multidimensional array of dashboard options, available from $_SESSION['settings'].
 * Each option is an array with the following possible keys:
 * 'default': The default value for users with no custom settings.
 *            Should always be a string, regardless of its actual type, in order to mirror the DB.
 *            Booleans should be either '0' or '1'.
 * 'type': 'int' or 'bool', for validation purposes. 'string' is assumed.
 * 'max_length': The maximum length in characters of the setting value.
 */

$default_dashboard = [
    'memorable_name' => [
        'default' => '',
        'max_length' => 100,
    ],
    'memorable_password' => [
        'default' => '',
    ],
    'email' => [
        'default' => '',
        'max_length' => 100,
    ],
    'custom_menu' => [
        'default' => DEFAULT_MENU,
        'max_length' => 600,
    ],
    'topics_mode' => [
        'default' => '0',
        'type' => 'bool',
    ],
    'spoiler_mode' => [
        'default' => '0',
        'type' => 'bool',
    ],
    'ostrich_mode' => [
        'default' => '0',
        'type' => 'bool',
    ],
    'celebrity_mode' => [
        'default' => '0',
        'type' => 'bool',
    ],
    'text_mode' => [
        'default' => '0',
        'type' => 'bool',
    ],
    'custom_style' => [
        'default' => '0',
        'type' => 'int',
    ],
    'snippet_length' => [
        'default' => '80',
        'type' => 'int',
    ],
    'posts_per_page' => [
        'default' => POSTS_PER_PAGE_DEFAULT,
        'type' => 'int',
    ],
    'style' => [
        'default' => DEFAULT_STYLESHEET,
    ]
];

?>