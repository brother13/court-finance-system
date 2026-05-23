<?php

return [
    'app_debug' => true,
    'app_trace' => false,
    'app_multi_module' => true,
    'default_return_type' => 'json',
    'default_ajax_return' => 'json',
    'default_timezone' => 'PRC',
    'default_filter' => '',
    'default_module' => 'finance',
    'deny_module_list' => ['common'],
    'default_controller' => 'Index',
    'default_action' => 'index',
    'empty_controller' => 'Error',
    'url_route_on' => true,
    'route_config_file' => ['route'],
    'url_route_must' => false,
    'url_convert' => true,
    'log' => [
        'type' => 'File',
        'path' => LOG_PATH,
        'level' => [],
    ],
    'cache' => [
        'type' => 'File',
        'path' => CACHE_PATH,
        'prefix' => '',
        'expire' => 0,
    ],
    'paginate' => [
        'type' => 'bootstrap',
        'var_page' => 'page',
        'list_rows' => 15,
    ],
];
